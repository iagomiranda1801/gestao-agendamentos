<?php

namespace App\Services\Tattoo;

use App\Models\Appointment;
use App\Models\AsaasCharge;
use App\Models\Professional;
use App\Models\ProfessionalWorkingHour;
use App\Models\TattooAiConversation;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Models\User;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AvailabilityService;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TattooAISchedulingService
{
    public const WEEKDAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    public function __construct(protected AvailabilityService $availability, protected AppointmentService $appointments) {}

    /**
     * Compatibilidade: primeiros horários livres (Y-m-d H:i), só depois do sinal.
     *
     * @return list<string>
     */
    public function slots(TattooAiConversation $conversation): array
    {
        if (! $this->paid($conversation)) {
            return [];
        }
        $slots = [];
        foreach ($this->days($conversation, 5) as $date) {
            foreach ($this->times($conversation, $date) as $time) {
                $slots[] = $date.' '.$time;
                if (count($slots) >= 5) {
                    return $slots;
                }
            }
        }

        return $slots;
    }

    public function quote(TattooAiConversation $conversation): ?TattooQuote
    {
        return $conversation->request?->quotes()->latest('version')->first();
    }

    public function professional(TattooAiConversation $conversation): ?Professional
    {
        $request = $conversation->request;
        if ($request?->professional_id) {
            return $request->professional;
        }
        $base = Professional::query()->where('company_id', $conversation->company_id)->where('is_active', true)->where('is_bookable', true);
        $configured = Str::lower(Str::ascii(trim((string) $conversation->company->schedulingSetting?->tattoo_artist_name)));
        if ($configured !== '') {
            $first = (string) Str::of($configured)->explode(' ')->first();
            $match = (clone $base)->get()->first(fn (Professional $p) => ($name = Str::lower(Str::ascii(trim((string) $p->name)))) === $configured
                || (string) Str::of($name)->explode(' ')->first() === $first);
            if ($match) {
                return $match;
            }
        }
        $all = (clone $base)->limit(2)->get();

        return $all->count() === 1 ? $all->first() : null;
    }

    public function duration(TattooAiConversation $conversation): int
    {
        return max(15, (int) ($this->quote($conversation)?->minutes_per_session ?: 60));
    }

    /**
     * Próximos dias com pelo menos um horário livre (Y-m-d).
     *
     * @return list<string>
     */
    public function days(TattooAiConversation $conversation, int $limit = 7, ?Appointment $ignore = null): array
    {
        $professional = $ignore?->professional ?? $this->professional($conversation);
        if (! $professional) {
            return [];
        }
        $company = $conversation->company;
        $maxDays = (int) ($company->schedulingSetting?->maximum_advance_days ?: 60);
        $today = CompanyDateTime::nowLocal($company)->startOfDay();
        $days = [];
        for ($i = 0; $i <= min($maxDays, 120) && count($days) < $limit; $i++) {
            $date = $today->addDays($i)->format('Y-m-d');
            if ($this->times($conversation, $date, 1, $professional, $ignore) !== []) {
                $days[] = $date;
            }
        }

        return $days;
    }

    /**
     * Horários livres (H:i) de um dia.
     *
     * @return list<string>
     */
    public function times(TattooAiConversation $conversation, string $date, int $limit = 12, ?Professional $professional = null, ?Appointment $ignore = null): array
    {
        $professional ??= $ignore?->professional ?? $this->professional($conversation);
        if (! $professional) {
            return [];
        }
        $company = $conversation->company;
        $setting = $company->schedulingSetting;
        $interval = max(5, (int) ($setting?->slot_interval_minutes ?: 15));
        $duration = $ignore ? max(15, (int) $ignore->duration_minutes_snapshot ?: 60) : $this->duration($conversation);
        $now = CompanyDateTime::nowLocal($company);
        $earliest = $now->addMinutes((int) ($setting?->minimum_advance_minutes ?? 0));
        $latest = $now->startOfDay()->addDays((int) ($setting?->maximum_advance_days ?: 60))->endOfDay();
        $day = CompanyDateTime::parseLocal($company, $date, '00:00');
        if ($day->gt($latest) || $day->endOfDay()->lt($earliest)) {
            return [];
        }
        $times = [];
        foreach ($this->windows($conversation, $professional, $day) as [$from, $to]) {
            $from = (int) (ceil($from / $interval) * $interval);
            for ($minute = $from; $minute + $duration <= $to && count($times) < $limit; $minute += $interval) {
                $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
                $start = CompanyDateTime::parseLocal($company, $date, $time);
                if ($start->lt($earliest) || $start->gt($latest)) {
                    continue;
                }
                if ($this->availability->assertAvailable($company, $professional, null, $start, $duration, 0, 0, $ignore, isTattoo: true)->available) {
                    $times[] = $time;
                }
            }
        }

        return array_values(array_unique($times));
    }

    /**
     * Jornada real do profissional; sem jornada cadastrada usa o horário padrão antigo.
     *
     * @return list<array{0: int, 1: int}>
     */
    protected function windows(TattooAiConversation $conversation, Professional $professional, CarbonImmutable $day): array
    {
        $ranges = ProfessionalWorkingHour::query()->where('company_id', $conversation->company_id)
            ->where('professional_id', $professional->id)->where('weekday', $day->dayOfWeek)->active()
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $day->toDateString()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $day->toDateString()))
            ->orderBy('start_time')->get()
            ->map(fn (ProfessionalWorkingHour $h) => [CompanyDateTime::timeToMinutes((string) $h->start_time), CompanyDateTime::timeToMinutes((string) $h->end_time)])
            ->all();
        if ($ranges !== []) {
            return $ranges;
        }

        return (bool) $conversation->company->schedulingSetting?->tattoo_custom_flow_enabled ? [[14 * 60, 22 * 60]] : [[8 * 60, 21 * 60]];
    }

    public function isFree(TattooAiConversation $conversation, string $slot, ?Appointment $ignore = null): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $slot)) {
            return false;
        }
        $professional = $ignore?->professional ?? $this->professional($conversation);

        return $professional !== null && in_array(substr($slot, 11, 5), $this->times($conversation, substr($slot, 0, 10), 200, $professional, $ignore), true);
    }

    public function label(TattooAiConversation $conversation, string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return self::WEEKDAYS[$day->dayOfWeek].' '.$day->format('d/m');
    }

    public function book(TattooAiConversation $conversation, string $selected): Appointment
    {
        $request = $conversation->request;
        $quote = $this->quote($conversation);
        $professional = $this->professional($conversation);
        if (! $request || ! $quote || ! $professional || ! $request->client || ! $this->paid($conversation) || $request->appointment_id) {
            throw ValidationException::withMessages(['slot' => 'Horário indisponível. Consulte os horários novamente.']);
        }
        $user = $this->actor($conversation, $quote, $professional);
        if (! $user) {
            throw ValidationException::withMessages(['slot' => 'Nenhum usuário responsável pela agenda.']);
        }

        // Quem tiver o sinal aprovado primeiro leva o horário.
        return Cache::lock('tattoo-book:'.$conversation->company_id.':'.$professional->id, 30)->block(10, function () use ($conversation, $request, $quote, $selected, $professional, $user): Appointment {
            if (! $this->isFree($conversation, $selected)) {
                throw ValidationException::withMessages(['slot' => 'Horário indisponível. Consulte os horários novamente.']);
            }

            return DB::transaction(function () use ($conversation, $request, $quote, $selected, $professional, $user): Appointment {
                $locked = TattooRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                if ($locked->appointment_id) {
                    throw ValidationException::withMessages(['slot' => 'Este pedido já foi agendado.']);
                }
                $appointment = $this->appointments->createInternalAppointment(
                    $conversation->company, $user, $request->client, $professional, null,
                    CompanyDateTime::parseLocal($conversation->company, substr($selected, 0, 10), substr($selected, 11, 5)),
                    ['service_selection_mode' => 'to_be_defined', 'duration_minutes_snapshot' => $quote->minutes_per_session ?: 60,
                        'internal_notes' => 'Pedido de tatuagem #'.$request->id.': '.$request->description,
                        'send_whatsapp_confirmation' => false, 'reference_key' => 'tattoo-ai-'.$request->id,
                        'is_tattoo' => true],
                );
                $locked->update(['appointment_id' => $appointment->id, 'status' => 'booked', 'professional_id' => $locked->professional_id ?: $professional->id]);
                $data = $conversation->collected_data ?: [];
                unset($data['_schedule']);
                $conversation->update(['status' => 'converted_to_appointment', 'collected_data' => $data]);

                return $appointment;
            });
        });
    }

    protected function actor(TattooAiConversation $conversation, TattooQuote $quote, Professional $professional): ?User
    {
        if ($quote->creator) {
            return $quote->creator;
        }
        if ($professional->user) {
            return $professional->user;
        }

        return $conversation->company->users()->orderBy('users.id')->first();
    }

    public function paid(TattooAiConversation $conversation): bool
    {
        $quote = $this->quote($conversation);
        if ($quote?->accepted_at && (float) $quote->deposit_amount <= 0) {
            return true;
        }

        if ($conversation->receipts()->where('payment_status', 'confirmed')->exists()) {
            return true;
        }

        return $quote !== null && AsaasCharge::depositIsPaid((int) $conversation->company_id, (int) $quote->getKey());
    }
}
