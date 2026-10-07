<?php

namespace App\Services\Beauty;

use App\DataTransferObjects\PublicBooking\OnlineBookingData;
use App\DataTransferObjects\PublicBooking\OnlineBookingResult;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Services\PublicBooking\OnlineBookingCatalogService;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Horários e agendamentos da IA de estética/salão. Usa exatamente os mesmos
 * serviços do agendamento online, então a IA só oferece horários reais.
 */
class BeautyAISchedulingService
{
    public const SEARCH_DAYS = 21;

    private const WEEKDAYS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

    public function __construct(
        protected OnlineBookingCatalogService $catalog,
        protected OnlineBookingService $booking,
        protected CompanySchedulingSettingService $settings,
    ) {}

    /** @return Collection<int, Service> */
    public function services(Company $company): Collection
    {
        return $this->catalog->getEligibleServices($company);
    }

    /** @return Collection<int, Professional> */
    public function professionals(Company $company, Service $service): Collection
    {
        return $this->catalog->getEligibleProfessionals($company, $service);
    }

    /**
     * Até $limit horários livres, espaçados para dar opções diferentes.
     *
     * @return list<array{value: string, professional_id: int, professional_name: string, label: string}>
     */
    public function slots(Company $company, Service $service, ?int $professionalId, ?string $fromDate = null,
        ?string $period = null, ?string $after = null, int $limit = 3): array
    {
        $professionals = $this->professionals($company, $service)
            ->when($professionalId !== null, fn (Collection $list) => $list->where('id', $professionalId))
            ->values();
        if ($professionals->isEmpty()) {
            return [];
        }
        $settings = $this->settings->getOrCreate($company);
        $today = CompanyDateTime::nowLocal($company)->startOfDay();
        $start = $today;
        if ($fromDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $fromDate, CompanyDateTime::timezone($company))->startOfDay()->max($today);
        }
        $afterMoment = $after !== null ? CompanyDateTime::parseLocal($company, substr($after, 0, 10), substr($after, 11, 5)) : null;
        if ($afterMoment !== null && $afterMoment->startOfDay()->gt($start)) {
            $start = $afterMoment->startOfDay();
        }
        $lastDay = $today->addDays(min(self::SEARCH_DAYS, max(1, (int) $settings->maximum_advance_days)));
        $spacing = max(60, (int) $service->duration_minutes);
        $picked = [];

        for ($day = $start; $day->lte($lastDay) && count($picked) < $limit; $day = $day->addDay()) {
            $daySlots = collect();
            foreach ($professionals as $professional) {
                foreach ($this->catalog->getAvailableSlots($company, $service, $professional->id, $day) as $slot) {
                    $key = $slot->format('Y-m-d H:i');
                    if (! $daySlots->has($key)) {
                        $daySlots->put($key, ['slot' => $slot, 'professional' => $professional]);
                    }
                }
            }
            $lastPicked = null;
            foreach ($daySlots->sortKeys() as $key => $entry) {
                /** @var CarbonImmutable $slot */
                $slot = $entry['slot'];
                if (($afterMoment !== null && $slot->lte($afterMoment)) || ! $this->inPeriod($slot, $period)
                    || ($lastPicked !== null && $lastPicked->diffInMinutes($slot) < $spacing)) {
                    continue;
                }
                $picked[] = [
                    'value' => $key,
                    'professional_id' => (int) $entry['professional']->id,
                    'professional_name' => (string) $entry['professional']->name,
                    'label' => $this->label($company, $slot),
                ];
                $lastPicked = $slot;
                if (count($picked) >= $limit || count(array_filter($picked, fn ($p) => str_starts_with($p['value'], $day->format('Y-m-d')))) >= 2) {
                    break;
                }
            }
        }

        return $picked;
    }

    public function book(Company $company, Service $service, int $professionalId, string $slot, string $name,
        string $phone, ?string $email, string $idempotencyUuid): OnlineBookingResult
    {
        return $this->booking->create(new OnlineBookingData(
            company: $company,
            serviceId: (int) $service->id,
            professionalId: $professionalId,
            localStart: CompanyDateTime::parseLocal($company, substr($slot, 0, 10), substr($slot, 11, 5)),
            clientName: $name,
            clientPhone: $phone,
            clientEmail: $email,
            notes: 'Agendado pelo atendimento com IA no WhatsApp.',
            idempotencyUuid: $idempotencyUuid,
            privacyAccepted: true,
            termsAccepted: true,
            honeypot: null,
            formStartedAt: CarbonImmutable::now()->subSeconds(30),
        ));
    }

    public function label(Company $company, CarbonImmutable $slot): string
    {
        $today = CompanyDateTime::nowLocal($company)->startOfDay();
        $day = match (true) {
            $slot->isSameDay($today) => 'hoje',
            $slot->isSameDay($today->addDay()) => 'amanhã',
            default => self::WEEKDAYS[$slot->dayOfWeek],
        };

        return $day.' ('.$slot->format('d/m').') às '.$this->time($slot);
    }

    public function time(CarbonImmutable $moment): string
    {
        return $moment->format('G').'h'.($moment->format('i') !== '00' ? $moment->format('i') : '');
    }

    protected function inPeriod(CarbonImmutable $slot, ?string $period): bool
    {
        $hour = (int) $slot->format('G');

        return match ($period) {
            'manha' => $hour < 12,
            'tarde' => $hour >= 12 && $hour < 18,
            'noite' => $hour >= 18,
            default => true,
        };
    }
}
