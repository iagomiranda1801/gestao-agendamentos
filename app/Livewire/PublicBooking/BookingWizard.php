<?php

namespace App\Livewire\PublicBooking;

use App\DataTransferObjects\PublicBooking\OnlineBookingData;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Services\PublicBooking\OnlineBookingCatalogService;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\PublicBooking\PublicBookingRateLimiter;
use App\Services\PublicBooking\PublicClientLookupService;
use App\Support\CompanyDateTime;
use App\Support\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.public-booking')]
class BookingWizard extends Component
{
    public const NO_PREFERENCE = 'no_preference';

    public const STEP_SERVICE = 'service';

    public const STEP_PROFESSIONAL = 'professional';

    public const STEP_SCHEDULE = 'schedule';

    public const STEP_CLIENT = 'client';

    public const STEP_REVIEW = 'review';

    public const STEP_CONFIRMATION = 'confirmation';

    public Company $company;

    public string $step = self::STEP_SERVICE;

    public string $idempotencyUuid;

    public int $formStartedAt;

    public string $website_url = '';

    public ?int $serviceId = null;

    public string|int|null $professionalSelection = null;

    public ?string $selectedDate = null;

    public ?string $selectedTime = null;

    public ?string $calendarMonth = null;

    /** @var list<string> */
    public array $cachedAvailableDateKeys = [];

    public string $clientName = '';

    public string $clientPhone = '';

    public ?string $clientEmail = null;

    public ?string $clientLookupMessage = null;

    public bool $clientLookupFound = false;

    public ?string $lastPhoneLookedUp = null;

    public ?string $notes = null;

    public bool $privacyAccepted = false;

    public bool $termsAccepted = false;

    public ?string $confirmationCode = null;

    public ?string $manageUrl = null;

    public ?string $confirmationMessage = null;

    public ?string $errorMessage = null;

    public bool $whatsappQueued = false;

    public bool $isSubmitting = false;

    public bool $isLoadingSchedule = false;

    public bool $scheduleDatesLoaded = false;

    public function mount(Company $company): void
    {
        $company->load('schedulingSetting');

        abort_unless(
            $company->is_active
            && $company->schedulingSetting?->public_booking_enabled,
            404,
        );

        $this->company = $company;
        $this->idempotencyUuid = (string) Str::uuid();
        $this->formStartedAt = time();
    }

    /**
     * @return array<string, mixed>
     */
    protected function bookingLayoutData(): array
    {
        $settings = $this->company->schedulingSetting;

        return [
            'primaryColor' => filled($settings?->booking_primary_color)
                ? $settings->booking_primary_color
                : '#2563eb',
            'company' => $this->company,
        ];
    }

    public function selectService(int $serviceId): void
    {
        $this->errorMessage = null;
        $this->serviceId = $serviceId;
        $this->resetFromProfessional();

        if ($this->findSelectedService() === null) {
            $this->serviceId = null;
            $this->errorMessage = 'Serviço indisponível.';

            return;
        }

        $this->advanceFromServiceSelection();
    }

    public function selectProfessional(string|int $professionalId): void
    {
        $this->errorMessage = null;
        $this->professionalSelection = $professionalId === self::NO_PREFERENCE
            ? self::NO_PREFERENCE
            : (int) $professionalId;
        $this->resetFromSchedule();
        $this->enterScheduleStep();
    }

    public function selectCalendarDate(string $date): void
    {
        $this->errorMessage = null;

        if (! in_array($date, $this->cachedAvailableDateKeys, true)) {
            $this->errorMessage = 'Esta data não está disponível.';

            return;
        }

        $this->selectedDate = $date;
        $this->selectedTime = null;
        $this->calendarMonth = CarbonImmutable::parse($date)->startOfMonth()->format('Y-m-d');
    }

    public function selectTime(string $time): void
    {
        $this->errorMessage = null;
        $this->selectedTime = $time;
        $this->step = self::STEP_CLIENT;
    }

    public function updatedClientPhone(?string $value): void
    {
        $digits = PhoneNormalizer::normalize($value) ?? '';

        if (strlen($digits) < 10) {
            $this->clientLookupMessage = null;
            $this->clientLookupFound = false;
            $this->lastPhoneLookedUp = null;

            return;
        }

        if ($this->lastPhoneLookedUp === $digits) {
            return;
        }

        $this->runPhoneLookup(
            app(PublicClientLookupService::class),
            app(PublicBookingRateLimiter::class),
        );
    }

    protected function runPhoneLookup(
        PublicClientLookupService $lookupService,
        PublicBookingRateLimiter $rateLimiter,
    ): void {
        $digits = PhoneNormalizer::normalize($this->clientPhone);

        if ($digits === null || strlen($digits) < 10) {
            return;
        }

        $this->clientLookupMessage = null;
        $this->clientLookupFound = false;
        $this->errorMessage = null;
        $this->resetErrorBag('clientPhone');

        $rateLimiter->assertClientLookupAllowed(
            (int) $this->company->getKey(),
            (string) request()->ip(),
        );

        $result = $lookupService->lookupByPhone($this->company, $digits);
        $this->lastPhoneLookedUp = $digits;
        $this->clientLookupFound = $result['found'];
        $this->clientLookupMessage = $result['message'];

        if ($result['found']) {
            $this->clientName = $result['name'] ?? $this->clientName;
            $this->clientEmail = $result['email'] ?? $this->clientEmail;

            if (filled($result['phone'] ?? null)) {
                $this->clientPhone = $result['phone'];
            }
        }
    }

    public function previousCalendarMonth(): void
    {
        $month = CarbonImmutable::parse($this->resolvedCalendarMonth())->subMonth()->startOfMonth();
        $bounds = $this->scheduleBounds();

        if ($month->lt($bounds['min']->startOfMonth())) {
            return;
        }

        $this->calendarMonth = $month->format('Y-m-d');
        $this->selectedDate = null;
        $this->selectedTime = null;
        $this->refreshAvailableDatesForVisibleMonth();
    }

    public function nextCalendarMonth(): void
    {
        $month = CarbonImmutable::parse($this->resolvedCalendarMonth())->addMonth()->startOfMonth();
        $bounds = $this->scheduleBounds();

        if ($month->gt($bounds['max']->startOfMonth())) {
            return;
        }

        $this->calendarMonth = $month->format('Y-m-d');
        $this->selectedDate = null;
        $this->selectedTime = null;
        $this->refreshAvailableDatesForVisibleMonth();
    }

    public function goToReview(): void
    {
        $this->validateClientStep();
        $this->errorMessage = null;
        $this->step = self::STEP_REVIEW;
    }

    public function confirmBooking(
        OnlineBookingService $bookingService,
        PublicBookingRateLimiter $rateLimiter,
    ): void {
        if ($this->step === self::STEP_CONFIRMATION) {
            return;
        }

        $this->errorMessage = null;
        $this->isSubmitting = true;

        try {
            if (filled($this->website_url)) {
                $this->step = self::STEP_CONFIRMATION;
                $this->confirmationCode = '------';
                $this->manageUrl = '#';

                return;
            }

            $this->validateClientStep();
            $this->validateReviewStep();

            $rateLimiter->assertCreateAttemptAllowed(
                (int) $this->company->getKey(),
                (string) request()->ip(),
                $this->clientPhone !== '' ? $this->clientPhone : null,
            );

            $localStart = CompanyDateTime::parseLocal(
                $this->company,
                (string) $this->selectedDate,
                (string) $this->selectedTime,
            );

            $result = $bookingService->create(new OnlineBookingData(
                company: $this->company,
                serviceId: (int) $this->serviceId,
                professionalId: $this->resolvedProfessionalId(),
                localStart: $localStart,
                clientName: $this->clientName,
                clientPhone: $this->clientPhone,
                clientEmail: $this->clientEmail,
                notes: $this->notes,
                idempotencyUuid: $this->idempotencyUuid,
                privacyAccepted: $this->privacyAccepted,
                termsAccepted: $this->termsAccepted,
                honeypot: $this->website_url,
                formStartedAt: CarbonImmutable::createFromTimestamp($this->formStartedAt),
                clientDocument: null,
            ));

            $settings = $this->company->schedulingSetting;
            $this->confirmationCode = $result->confirmationCode;
            $this->manageUrl = $result->manageUrl;
            $this->whatsappQueued = $result->whatsappQueued;
            $this->confirmationMessage = filled($settings?->booking_confirmation_message)
                ? $settings->booking_confirmation_message
                : 'Seu agendamento foi registrado com sucesso.';
            $this->step = self::STEP_CONFIRMATION;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->errorMessage = $exception->getMessage() ?: 'Não foi possível concluir o agendamento. Tente novamente.';
        } finally {
            $this->isSubmitting = false;
        }
    }

    public function goBack(): void
    {
        $this->errorMessage = null;

        $this->step = match ($this->step) {
            self::STEP_PROFESSIONAL => self::STEP_SERVICE,
            self::STEP_SCHEDULE => $this->shouldShowProfessionalStep() ? self::STEP_PROFESSIONAL : self::STEP_SERVICE,
            self::STEP_CLIENT => self::STEP_SCHEDULE,
            self::STEP_REVIEW => self::STEP_CLIENT,
            default => $this->step,
        };
    }

    public function loadScheduleAvailability(PublicBookingRateLimiter $rateLimiter): void
    {
        if ($this->step !== self::STEP_SCHEDULE || $this->serviceId === null || $this->scheduleDatesLoaded) {
            return;
        }

        $this->isLoadingSchedule = true;

        try {
            $rateLimiter->assertAvailabilityCheckAllowed(
                (int) $this->company->getKey(),
                (string) request()->ip(),
            );

            $this->refreshAvailableDatesForVisibleMonth();
        } catch (ValidationException $exception) {
            $this->errorMessage = collect($exception->errors())->flatten()->first()
                ?: 'Muitas consultas. Aguarde um momento.';
            $this->scheduleDatesLoaded = true;
        } finally {
            $this->isLoadingSchedule = false;
        }
    }

    public function render(OnlineBookingCatalogService $catalog)
    {
        $settings = $this->company->schedulingSetting;
        $services = $catalog->getEligibleServices($this->company);

        $professionals = collect();

        if ($this->serviceId !== null) {
            $service = $this->findSelectedService();

            if ($service !== null) {
                $professionals = $catalog->getEligibleProfessionals($this->company, $service);
            }
        }

        $availableSlots = collect();

        if ($this->selectedDate !== null && in_array($this->step, [self::STEP_SCHEDULE, self::STEP_CLIENT, self::STEP_REVIEW], true)) {
            $availableSlots = $this->loadAvailableSlots($catalog);
        }

        $selectedService = $this->findSelectedService();
        $selectedProfessional = $this->findSelectedProfessional($professionals);
        $scheduleBounds = $this->scheduleBounds();

        $pageTitle = filled($settings?->booking_page_title)
            ? $settings->booking_page_title
            : 'Agendar — '.$this->company->name;

        $pageDescription = filled($settings?->booking_page_description)
            ? $settings->booking_page_description
            : 'Agende seu horário online com '.($this->company->name).'.';

        $canonicalUrl = route('public.booking.show', $this->company);

        return view('livewire.public-booking.booking-wizard', [
            'settings' => $settings,
            'services' => $services,
            'professionals' => $professionals,
            'availableDateKeys' => $this->cachedAvailableDateKeys,
            'availableSlots' => $availableSlots,
            'selectedService' => $selectedService,
            'selectedProfessional' => $selectedProfessional,
            'steps' => $this->visibleSteps(),
            'pageTitle' => $pageTitle,
            'pageDescription' => $pageDescription,
            'canonicalUrl' => $canonicalUrl,
            'showProfessionalStep' => $this->shouldShowProfessionalStep(),
            'calendarMonth' => $this->resolvedCalendarMonth(),
            'scheduleMinDate' => $scheduleBounds['min']->format('Y-m-d'),
            'scheduleMaxDate' => $scheduleBounds['max']->format('Y-m-d'),
            'scheduleDatesLoaded' => $this->scheduleDatesLoaded,
        ])->layoutData($this->bookingLayoutData());
    }

    protected function advanceFromServiceSelection(): void
    {
        $service = $this->findSelectedService();

        if ($service === null) {
            return;
        }

        $settings = $this->company->schedulingSetting;
        $catalog = app(OnlineBookingCatalogService::class);
        $professionals = $catalog->getEligibleProfessionals($this->company, $service);

        if (! $settings->allow_professional_selection) {
            $this->professionalSelection = $professionals->count() === 1
                ? $professionals->first()->getKey()
                : self::NO_PREFERENCE;
            $this->enterScheduleStep();

            return;
        }

        if ($professionals->count() === 1) {
            $this->professionalSelection = $professionals->first()->getKey();
            $this->enterScheduleStep();

            return;
        }

        $this->step = self::STEP_PROFESSIONAL;
    }

    protected function enterScheduleStep(): void
    {
        $this->step = self::STEP_SCHEDULE;
        $this->cachedAvailableDateKeys = [];
        $this->scheduleDatesLoaded = false;
        $this->calendarMonth = $this->scheduleBounds()['min']->startOfMonth()->format('Y-m-d');
        $this->refreshAvailableDatesForVisibleMonth();
    }

    protected function refreshAvailableDatesForVisibleMonth(): void
    {
        $catalog = app(OnlineBookingCatalogService::class);
        $month = CarbonImmutable::parse($this->resolvedCalendarMonth())->startOfMonth();

        $this->cachedAvailableDateKeys = $this->loadAvailableDatesForMonth($catalog, $month)
            ->map(fn (CarbonImmutable $date): string => $date->format('Y-m-d'))
            ->values()
            ->all();

        $this->scheduleDatesLoaded = true;
    }

    protected function resolvedCalendarMonth(): string
    {
        if ($this->calendarMonth !== null) {
            return $this->calendarMonth;
        }

        return CompanyDateTime::nowLocal($this->company)->startOfMonth()->format('Y-m-d');
    }

    /**
     * @return array{min: CarbonImmutable, max: CarbonImmutable}
     */
    protected function scheduleBounds(): array
    {
        $settings = $this->company->schedulingSetting;
        $now = CompanyDateTime::nowLocal($this->company);

        return [
            'min' => $now->addMinutes((int) ($settings?->minimum_advance_minutes ?? 0))->startOfDay(),
            'max' => $now->addDays((int) ($settings?->maximum_advance_days ?? 60))->endOfDay(),
        ];
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    protected function loadAvailableDatesForMonth(
        OnlineBookingCatalogService $catalog,
        CarbonImmutable $month,
    ): Collection {
        $service = $this->findSelectedService();

        if ($service === null) {
            return collect();
        }

        return $catalog->getAvailableDatesInRange(
            $this->company,
            $service,
            $this->resolvedProfessionalId(),
            $month->startOfMonth(),
            $month->endOfMonth(),
        );
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    protected function loadAvailableSlots(OnlineBookingCatalogService $catalog): Collection
    {
        $service = $this->findSelectedService();

        if ($service === null || $this->selectedDate === null) {
            return collect();
        }

        $localDate = CarbonImmutable::parse($this->selectedDate, CompanyDateTime::timezone($this->company));

        return $catalog->getAvailableSlots(
            $this->company,
            $service,
            $this->resolvedProfessionalId(),
            $localDate,
        );
    }

    protected function validateClientStep(): void
    {
        $settings = $this->company->schedulingSetting;

        $rules = [
            'clientName' => ['required', 'string', 'max:120'],
            'clientPhone' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
            'privacyAccepted' => ['accepted'],
        ];

        if ($settings?->require_email_for_online_booking) {
            $rules['clientEmail'] = ['required', 'email', 'max:255'];
        } else {
            $rules['clientEmail'] = ['nullable', 'email', 'max:255'];
        }

        if (filled($settings?->booking_terms)) {
            $rules['termsAccepted'] = ['accepted'];
        }

        $this->validate($rules, [
            'clientName.required' => 'Informe seu nome.',
            'clientPhone.required' => 'Informe seu telefone.',
            'clientEmail.required' => 'Informe seu e-mail.',
            'clientEmail.email' => 'Informe um e-mail válido.',
            'privacyAccepted.accepted' => 'Você precisa aceitar a política de privacidade.',
            'termsAccepted.accepted' => 'Você precisa aceitar os termos de agendamento.',
        ]);

        if (blank(PhoneNormalizer::normalize($this->clientPhone))) {
            throw ValidationException::withMessages([
                'clientPhone' => 'Informe um telefone válido.',
            ]);
        }
    }

    protected function validateReviewStep(): void
    {
        if ($this->serviceId === null || $this->selectedDate === null || $this->selectedTime === null) {
            throw ValidationException::withMessages([
                'step' => 'Selecione serviço, data e horário antes de confirmar.',
            ]);
        }

        if ($this->shouldShowProfessionalStep() && $this->professionalSelection === null) {
            throw ValidationException::withMessages([
                'professionalSelection' => 'Selecione um profissional.',
            ]);
        }
    }

    protected function findSelectedService(): ?Service
    {
        if ($this->serviceId === null) {
            return null;
        }

        return Service::query()
            ->where('company_id', $this->company->getKey())
            ->whereKey($this->serviceId)
            ->first();
    }

    /**
     * @param  Collection<int, Professional>  $professionals
     */
    protected function findSelectedProfessional(Collection $professionals): ?Professional
    {
        if ($this->professionalSelection === null || $this->professionalSelection === self::NO_PREFERENCE) {
            return null;
        }

        return $professionals->firstWhere('id', (int) $this->professionalSelection);
    }

    protected function resolvedProfessionalId(): ?int
    {
        if ($this->professionalSelection === null || $this->professionalSelection === self::NO_PREFERENCE) {
            return null;
        }

        return (int) $this->professionalSelection;
    }

    protected function shouldShowProfessionalStep(): bool
    {
        $settings = $this->company->schedulingSetting;

        if (! $settings?->allow_professional_selection) {
            return false;
        }

        if ($this->serviceId === null) {
            return true;
        }

        $service = $this->findSelectedService();

        if ($service === null) {
            return false;
        }

        $catalog = app(OnlineBookingCatalogService::class);
        $professionals = $catalog->getEligibleProfessionals($this->company, $service);

        return $professionals->count() > 1;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    protected function visibleSteps(): array
    {
        $steps = [
            ['key' => self::STEP_SERVICE, 'label' => 'Serviço'],
        ];

        if ($this->shouldShowProfessionalStep()) {
            $steps[] = ['key' => self::STEP_PROFESSIONAL, 'label' => 'Profissional'];
        }

        $steps[] = ['key' => self::STEP_SCHEDULE, 'label' => 'Data e horário'];
        $steps[] = ['key' => self::STEP_CLIENT, 'label' => 'Seus dados'];
        $steps[] = ['key' => self::STEP_REVIEW, 'label' => 'Revisão'];

        if ($this->step === self::STEP_CONFIRMATION) {
            $steps[] = ['key' => self::STEP_CONFIRMATION, 'label' => 'Confirmação'];
        }

        return $steps;
    }

    protected function resetFromProfessional(): void
    {
        $this->professionalSelection = null;
        $this->resetFromSchedule();
    }

    protected function resetFromSchedule(): void
    {
        $this->selectedDate = null;
        $this->selectedTime = null;
        $this->calendarMonth = null;
        $this->cachedAvailableDateKeys = [];
        $this->scheduleDatesLoaded = false;
    }

    public function formatMoney(?string $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    }

    public function formatDate(?CarbonImmutable $date): string
    {
        return $date?->format('d/m/Y') ?? '';
    }

    public function formatTime(?CarbonImmutable $date): string
    {
        return $date?->format('H:i') ?? '';
    }
}
