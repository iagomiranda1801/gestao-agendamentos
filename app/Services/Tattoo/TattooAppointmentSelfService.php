<?php

namespace App\Services\Tattoo;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\TattooAiConversation;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Models\User;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AppointmentStatusService;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cliente remarca ou cancela sozinho o horário marcado pelo WhatsApp.
 * Estado em collected_data._manage; o sinal já pago continua valendo na remarcação.
 */
class TattooAppointmentSelfService
{
    public const HANDOFF = "\0handoff";

    public function __construct(
        protected TattooAISchedulingService $scheduling,
        protected TattooBookingFlow $booking,
        protected AppointmentService $appointments,
        protected AppointmentStatusService $statuses,
    ) {}

    /** Null: não dá pra fazer sozinho (prazo curto, sem tatuador ou sem responsável), o chamador passa pro tatuador. */
    public function start(TattooAiConversation $conversation, Appointment $appointment, string $choice): ?string
    {
        if (! $this->manageable($conversation, $appointment) || ! $this->withinNotice($conversation, $appointment)) {
            return null;
        }
        if ($choice === 'cancelar') {
            $this->store($conversation, ['mode' => 'cancel', 'appointment_id' => $appointment->id]);

            return 'Certeza que quer cancelar seu horário de '.$this->when($conversation, $appointment).'? '
                .$this->depositNotice($conversation, $appointment)."\n1 Sim, cancelar\n2 Não, manter";
        }

        return $this->showDays($conversation, $appointment, 'Bora remarcar! ');
    }

    public function handle(TattooAiConversation $conversation, string $text, string $normalized): ?string
    {
        $state = ($conversation->collected_data ?: [])['_manage'] ?? null;
        $appointment = $state ? Appointment::query()->with('professional')->find((int) ($state['appointment_id'] ?? 0)) : null;
        if ($appointment === null || ! $this->manageable($conversation, $appointment)) {
            $this->store($conversation, null);

            return null;
        }
        $normalized = trim($normalized);
        if (($state['mode'] ?? null) === 'cancel') {
            if (preg_match('/^(1|sim|s|pode|confirmo|cancela|cancelar)\b/u', $normalized)) {
                return $this->cancel($conversation, $appointment);
            }
            if (preg_match('/^(2|nao|n|manter|mantem|deixa)\b/u', $normalized)) {
                $this->store($conversation, null);

                return 'Beleza, seu horário de '.$this->when($conversation, $appointment).' continua de pé 🤙';
            }

            return "Só confirma pra mim: cancelar o horário de ".$this->when($conversation, $appointment)."?\n1 Sim, cancelar\n2 Não, manter";
        }
        if (preg_match('/^(0|manter|mantem|deixa|desisti|deixa quieto|nao quero mais remarcar)\b/u', $normalized)) {
            $this->store($conversation, null);

            return 'Tranquilo, mantive seu horário de '.$this->when($conversation, $appointment).'.';
        }
        if (preg_match('/\b(falar com|gustavo|tatuador|atendente|humano)\b/u', $normalized)) {
            $this->store($conversation, null);

            return self::HANDOFF;
        }
        if (($state['stage'] ?? null) === 'time') {
            if (preg_match('/\boutr[oa]s? (dia|data)s?\b/u', $normalized)) {
                return $this->showDays($conversation, $appointment);
            }
            if (($time = $this->booking->pickTime($state['times'] ?? [], $normalized)) !== null) {
                return $this->move($conversation, $appointment, $state['date'].' '.$time);
            }
            if (($date = $this->booking->pickDay($conversation, $state['days'] ?? [], $normalized, false)) !== null) {
                return $this->showTimes($conversation, $appointment, $date);
            }

            return $this->showTimes($conversation, $appointment, (string) $state['date'], 'Não entendi o horário 😅. ');
        }
        if (($date = $this->booking->pickDay($conversation, $state['days'] ?? [], $normalized)) !== null) {
            return $this->showTimes($conversation, $appointment, $date);
        }

        return $this->showDays($conversation, $appointment, 'Não entendi o dia 😅. ');
    }

    protected function showDays(TattooAiConversation $conversation, Appointment $appointment, string $prefix = ''): string
    {
        $days = $this->scheduling->days($conversation, 7, $appointment);
        $days = array_values(array_filter($days, fn (string $date) => $this->times($conversation, $appointment, $date) !== []));
        if ($days === []) {
            $this->store($conversation, null);

            return self::HANDOFF;
        }
        $this->store($conversation, ['mode' => 'reschedule', 'appointment_id' => $appointment->id, 'stage' => 'day', 'days' => $days]);
        $lines = [];
        foreach ($days as $i => $date) {
            $lines[] = ($i + 1).'. '.$this->scheduling->label($conversation, $date);
        }

        return $prefix."Esses são os próximos dias com horário livre:\n".implode("\n", $lines)
            ."\n\nResponde com o número do dia (ou *manter* pra deixar como está).";
    }

    protected function showTimes(TattooAiConversation $conversation, Appointment $appointment, string $date, string $prefix = ''): string
    {
        $times = $this->times($conversation, $appointment, $date);
        if ($times === []) {
            return $this->showDays($conversation, $appointment, 'Esse dia não tem horário livre. ');
        }
        $days = (($conversation->collected_data ?: [])['_manage']['days'] ?? null) ?: [];
        $this->store($conversation, ['mode' => 'reschedule', 'appointment_id' => $appointment->id, 'stage' => 'time',
            'date' => $date, 'times' => $times, 'days' => $days]);
        $lines = [];
        foreach ($times as $i => $time) {
            $lines[] = ($i + 1).'. '.$time;
        }

        return $prefix.'Horários livres na '.$this->scheduling->label($conversation, $date).":\n".implode("\n", $lines)
            ."\n\nResponde com o número do horário ou *outro dia* pra ver outras datas.";
    }

    /** Horários livres do dia, ignorando o próprio agendamento e sem repetir o horário atual. @return list<string> */
    protected function times(TattooAiConversation $conversation, Appointment $appointment, string $date): array
    {
        $current = CompanyDateTime::utcToLocal($conversation->company, CarbonImmutable::parse($appointment->start_at))->format('Y-m-d H:i');

        return array_values(array_filter($this->scheduling->times($conversation, $date, 12, null, $appointment),
            fn (string $time) => $date.' '.$time !== $current));
    }

    protected function move(TattooAiConversation $conversation, Appointment $appointment, string $slot): string
    {
        $user = $this->actor($conversation, $appointment);
        $old = $this->when($conversation, $appointment);
        $moved = null;
        try {
            $moved = Cache::lock('tattoo-book:'.$conversation->company_id.':'.$appointment->professional_id, 30)->block(10,
                function () use ($conversation, $appointment, $slot, $user): ?Appointment {
                    $fresh = $appointment->fresh(['professional']);
                    if (! $user || ! $fresh || ! $this->scheduling->isFree($conversation, $slot, $fresh)) {
                        return null;
                    }

                    return $this->appointments->reschedule($conversation->company, $user, $fresh,
                        CompanyDateTime::parseLocal($conversation->company, substr($slot, 0, 10), substr($slot, 11, 5)));
                });
        } catch (ValidationException) {
            $moved = null;
        } catch (Throwable $exception) {
            Log::warning('Tattoo self-service: reschedule failed.', ['appointment_id' => $appointment->id, 'error_type' => $exception::class]);
            $moved = null;
        }
        if ($moved === null) {
            return $this->showDays($conversation, $appointment, 'Esse horário acabou de ser ocupado 😕 ');
        }
        $this->store($conversation, null);
        $new = $this->when($conversation, $moved);
        $this->booking->notifyArtist($conversation, '🔁 '.$this->clientName($moved).' remarcou pelo WhatsApp: de '.$old.' para '.$new.'. O sinal pago continua valendo.');

        return 'Prontinho! Seu horário foi remarcado pra '.$new.' ✅ O sinal que você já pagou continua valendo, não precisa pagar de novo.';
    }

    protected function cancel(TattooAiConversation $conversation, Appointment $appointment): string
    {
        $user = $this->actor($conversation, $appointment);
        $when = $this->when($conversation, $appointment);
        [$quote, $outcome] = $this->depositOutcome($conversation, $appointment);
        try {
            DB::transaction(function () use ($conversation, $appointment, $user, $quote, $outcome): void {
                $this->statuses->cancel($conversation->company, $user, $appointment, 'Cancelado pelo cliente no WhatsApp');
                if ($quote && $outcome) {
                    $quote->update(['deposit_outcome' => $outcome, 'deposit_outcome_at' => now()]);
                }
            });
        } catch (Throwable $exception) {
            Log::warning('Tattoo self-service: cancel failed.', ['appointment_id' => $appointment->id, 'error_type' => $exception::class]);
            $this->store($conversation, null);

            return self::HANDOFF;
        }
        $this->store($conversation, null);
        $amount = $quote ? 'R$ '.number_format((float) $quote->deposit_amount, 2, ',', '.') : '';
        $artist = '❌ '.$this->clientName($appointment).' cancelou pelo WhatsApp o horário de '.$when.'.';
        $reply = 'Pronto, cancelei seu horário de '.$when.'.';
        if ($outcome === 'refund_pending') {
            $artist .= ' Sinal de '.$amount.': REEMBOLSAR. Faz a devolução do PIX manualmente pro cliente.';
            $reply .= ' O sinal de '.$amount.' vai ser devolvido, o '.$this->artistName($conversation, $appointment).' faz o PIX de volta pra você em breve.';
        } elseif ($outcome === 'retained') {
            $artist .= ' Sinal de '.$amount.' retido (política do estúdio).';
            $reply .= ' Pela política do estúdio, o sinal de '.$amount.' fica retido.';
        }
        $this->booking->notifyArtist($conversation, $artist);

        return $reply.' Se quiser marcar de novo é só chamar 🤙';
    }

    /** @return array{0: ?TattooQuote, 1: ?string} */
    protected function depositOutcome(TattooAiConversation $conversation, Appointment $appointment): array
    {
        $request = TattooRequest::query()->where('company_id', $conversation->company_id)->where('appointment_id', $appointment->id)->first();
        $quote = $request?->quotes()->whereNotNull('accepted_at')->latest('version')->first();
        if (! $quote || (float) $quote->deposit_amount <= 0) {
            return [null, null];
        }

        return [$quote, $this->refunds($conversation, $appointment) ? 'refund_pending' : 'retained'];
    }

    protected function refunds(TattooAiConversation $conversation, Appointment $appointment): bool
    {
        $setting = $conversation->company->schedulingSetting;
        if (($setting?->tattoo_cancel_deposit_policy ?: 'reter') !== 'reembolsar') {
            return false;
        }
        $cutoff = $setting?->tattoo_refund_cutoff_hours;

        return $cutoff === null || now()->diffInMinutes(CarbonImmutable::parse($appointment->start_at), false) >= $cutoff * 60;
    }

    protected function depositNotice(TattooAiConversation $conversation, Appointment $appointment): string
    {
        [$quote, $outcome] = $this->depositOutcome($conversation, $appointment);
        if (! $quote) {
            return '';
        }
        $amount = 'R$ '.number_format((float) $quote->deposit_amount, 2, ',', '.');

        return $outcome === 'refund_pending' ? 'O sinal de '.$amount.' é devolvido.' : 'Pela política do estúdio, o sinal de '.$amount.' fica retido.';
    }

    protected function manageable(TattooAiConversation $conversation, Appointment $appointment): bool
    {
        return (int) $appointment->company_id === (int) $conversation->company_id
            && CarbonImmutable::parse($appointment->start_at)->isFuture()
            && in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)
            && $appointment->professional !== null && $this->actor($conversation, $appointment) !== null;
    }

    protected function withinNotice(TattooAiConversation $conversation, Appointment $appointment): bool
    {
        $hours = $conversation->company->schedulingSetting?->tattoo_self_service_min_hours;

        return ! $hours || now()->diffInMinutes(CarbonImmutable::parse($appointment->start_at), false) >= $hours * 60;
    }

    protected function actor(TattooAiConversation $conversation, Appointment $appointment): ?User
    {
        return $appointment->professional?->user ?? $conversation->company->users()->orderBy('users.id')->first();
    }

    protected function when(TattooAiConversation $conversation, Appointment $appointment): string
    {
        $local = CompanyDateTime::utcToLocal($conversation->company, CarbonImmutable::parse($appointment->start_at));

        return $this->scheduling->label($conversation, $local->format('Y-m-d')).' às '.$local->format('H:i');
    }

    protected function clientName(Appointment $appointment): string
    {
        return trim((string) $appointment->client?->name) ?: 'Cliente';
    }

    protected function artistName(TattooAiConversation $conversation, Appointment $appointment): string
    {
        $name = trim((string) $appointment->professional?->name);

        return $name !== '' ? (string) Str::of($name)->explode(' ')->first() : TattooStudioInfo::artistName($conversation->company);
    }

    /** @param array<string, mixed>|null $state */
    protected function store(TattooAiConversation $conversation, ?array $state): void
    {
        $data = $conversation->collected_data ?: [];
        if ($state === null) {
            unset($data['_manage']);
        } else {
            $data['_manage'] = $state;
        }
        $conversation->update(['collected_data' => $data]);
    }
}
