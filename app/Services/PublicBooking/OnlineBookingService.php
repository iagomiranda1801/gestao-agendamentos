<?php

namespace App\Services\PublicBooking;

use App\DataTransferObjects\PublicBooking\OnlineBookingData;
use App\DataTransferObjects\PublicBooking\OnlineBookingResult;
use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Events\OnlineAppointmentConfirmed;
use App\Events\OnlineAppointmentCreated;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AppointmentSnapshotResolver;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Support\CompanyDateTime;
use App\Support\PublicBookingTextSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnlineBookingService
{
    public function __construct(
        protected CompanySchedulingSettingService $settingsService,
        protected AvailabilityService $availabilityService,
        protected AppointmentSnapshotResolver $snapshotResolver,
        protected PublicConfirmationCodeGenerator $confirmationCodeGenerator,
        protected PublicAppointmentTokenService $tokenService,
        protected OnlineClientResolver $clientResolver,
        protected OnlineProfessionalResolver $professionalResolver,
        protected AppointmentService $appointmentService,
    ) {}

    public function create(OnlineBookingData $data): OnlineBookingResult
    {
        $company = $data->company;
        $settings = $this->settingsService->getOrCreate($company);
        $referenceKey = $this->buildReferenceKey($company, $data->idempotencyUuid);

        $this->assertBookingEnabled($company, $settings);
        $this->assertAntiSpam($data);
        $this->assertAcceptances($settings, $data);

        $existing = Appointment::query()
            ->where('company_id', $company->getKey())
            ->where('reference_key', $referenceKey)
            ->first();

        if ($existing !== null) {
            return $this->buildIdempotentResult($existing);
        }

        $lock = Cache::lock("public-booking:{$company->getKey()}:{$data->idempotencyUuid}", 10);

        try {
            $lock->block(5);

            $existing = Appointment::query()
                ->where('company_id', $company->getKey())
                ->where('reference_key', $referenceKey)
                ->first();

            if ($existing !== null) {
                return $this->buildIdempotentResult($existing);
            }

            return $this->createAppointment($data, $settings, $referenceKey);
        } finally {
            optional($lock)->release();
        }
    }

    protected function createAppointment(
        OnlineBookingData $data,
        $settings,
        string $referenceKey,
    ): OnlineBookingResult {
        $company = $data->company;

        $service = Service::query()
            ->where('company_id', $company->getKey())
            ->whereKey($data->serviceId)
            ->where('is_active', true)
            ->availableForOnlineBooking()
            ->first();

        if ($service === null) {
            throw ValidationException::withMessages([
                'service_id' => 'Serviço indisponível para agendamento online.',
            ]);
        }

        $this->assertAdvanceRules($company, $settings, $data->localStart);

        if ($settings->require_email_for_online_booking && blank($data->clientEmail)) {
            throw ValidationException::withMessages([
                'client_email' => 'Informe um e-mail válido.',
            ]);
        }

        $clientName = PublicBookingTextSanitizer::clientName($data->clientName);
        $notes = PublicBookingTextSanitizer::clientNotes($data->notes);
        $clientEmail = filled($data->clientEmail) ? trim(strtolower($data->clientEmail)) : null;
        $clientPhone = $data->clientPhone;

        $result = DB::transaction(function () use (
            $data,
            $company,
            $settings,
            $service,
            $referenceKey,
            $clientName,
            $clientPhone,
            $clientEmail,
            $notes,
        ): array {
            $client = $this->clientResolver->resolve(
                $company,
                $clientName ?? '',
                $clientPhone,
                $clientEmail,
                $data->clientDocument,
            );

            $professional = $this->resolveProfessionalWithLock(
                $company,
                $service,
                $data->professionalId,
                $data->localStart,
                (bool) $settings->allow_no_professional_preference,
            );

            $snapshots = $this->snapshotResolver->resolve($company, $professional, $service);
            $startUtc = CompanyDateTime::localToUtc($company, $data->localStart);
            $endUtc = $startUtc->addMinutes($snapshots['duration_minutes_snapshot']);

            $this->availabilityService->assertAvailable(
                $company,
                $professional,
                $service,
                $data->localStart,
                $snapshots['duration_minutes_snapshot'],
                $snapshots['buffer_before_minutes_snapshot'],
                $snapshots['buffer_after_minutes_snapshot'],
            )->assertAvailable();

            $autoConfirm = (bool) $settings->online_auto_confirm;
            $now = CompanyDateTime::nowUtc();

            $appointment = new Appointment([
                'status' => $autoConfirm ? AppointmentStatus::Confirmed : AppointmentStatus::Pending,
                'origin' => AppointmentOrigin::Online,
                'reference_key' => $referenceKey,
                'public_confirmation_code' => $this->confirmationCodeGenerator->generate($company),
                'start_at' => $startUtc,
                'end_at' => $endUtc,
                'service_name_snapshot' => $snapshots['service_name_snapshot'],
                'price_snapshot' => $snapshots['price_snapshot'],
                'duration_minutes_snapshot' => $snapshots['duration_minutes_snapshot'],
                'buffer_before_minutes_snapshot' => $snapshots['buffer_before_minutes_snapshot'],
                'buffer_after_minutes_snapshot' => $snapshots['buffer_after_minutes_snapshot'],
                'notes' => $notes,
                'client_name_snapshot' => $clientName,
                'client_phone_snapshot' => $clientPhone,
                'client_email_snapshot' => $clientEmail,
                'privacy_accepted_at' => filled($settings->privacy_notice) ? $now : null,
                'terms_accepted_at' => filled($settings->booking_terms) ? $now : null,
                'public_booked_at' => $now,
                'confirmed_at' => $autoConfirm ? $now : null,
            ]);

            $appointment->company()->associate($company);
            $appointment->client()->associate($client);
            $appointment->professional()->associate($professional);
            $appointment->service()->associate($service);
            $appointment->save();

            $this->appointmentService->recordPublicHistory(
                $company,
                $appointment,
                AppointmentHistoryType::Created,
                [
                    'new_status' => $appointment->status,
                    'new_start_at' => $appointment->start_at,
                    'new_end_at' => $appointment->end_at,
                ],
                ['source' => 'public'],
            );

            if ($autoConfirm) {
                $this->appointmentService->recordPublicHistory(
                    $company,
                    $appointment,
                    AppointmentHistoryType::Confirmed,
                    [
                        'old_status' => AppointmentStatus::Pending,
                        'new_status' => $appointment->status,
                        'new_start_at' => $appointment->start_at,
                        'new_end_at' => $appointment->end_at,
                    ],
                    ['source' => 'public', 'auto_confirmed' => true],
                );
            }

            $plainToken = $this->tokenService->issue($appointment->refresh());
            $manageUrl = route('public.appointment.manage', ['token' => $plainToken]);

            DB::afterCommit(function () use ($appointment, $settings, $manageUrl): void {
                event(new OnlineAppointmentCreated($appointment, $manageUrl));

                if ((bool) $settings->online_auto_confirm) {
                    event(new OnlineAppointmentConfirmed($appointment));
                }
            });

            return [$appointment->refresh(), $plainToken, $manageUrl];
        });

        [$appointment, $plainToken, $manageUrl] = $result;

        $whatsappQueued = (bool) ($settings->whatsapp_notifications_enabled ?? false)
            && filled($settings->whatsapp_instance ?: config('services.evolution.instance'))
            && filled($appointment->client_phone_snapshot);

        return new OnlineBookingResult(
            appointment: $appointment,
            plainToken: $plainToken,
            confirmationCode: (string) $appointment->public_confirmation_code,
            manageUrl: $manageUrl,
            whatsappQueued: $whatsappQueued,
        );
    }

    protected function resolveProfessionalWithLock(
        Company $company,
        Service $service,
        ?int $professionalId,
        CarbonImmutable $localStart,
        bool $allowNoPreference,
    ): Professional {
        if ($professionalId === null && $allowNoPreference) {
            return $this->professionalResolver->resolveNoPreference($company, $service, $localStart);
        }

        return DB::transaction(function () use ($company, $service, $professionalId, $localStart, $allowNoPreference): Professional {
            $professional = $this->professionalResolver->resolveForBooking(
                $company,
                $service,
                $professionalId,
                $localStart,
                $allowNoPreference,
            );

            return Professional::query()
                ->whereKey($professional->getKey())
                ->lockForUpdate()
                ->firstOrFail();
        });
    }

    protected function buildIdempotentResult(Appointment $appointment): OnlineBookingResult
    {
        $activeToken = $appointment->publicAccessTokens()->active()->exists();
        $plainToken = null;

        if (! $activeToken) {
            $plainToken = $this->tokenService->issue($appointment);
        }

        return new OnlineBookingResult(
            appointment: $appointment,
            plainToken: $plainToken,
            confirmationCode: (string) $appointment->public_confirmation_code,
            manageUrl: $plainToken !== null
                ? route('public.appointment.manage', ['token' => $plainToken])
                : null,
            whatsappQueued: false,
        );
    }

    protected function buildReferenceKey(Company $company, string $uuid): string
    {
        return "online:{$company->getKey()}:{$uuid}";
    }

    protected function assertBookingEnabled(Company $company, $settings): void
    {
        if (! $company->is_active) {
            abort(404);
        }

        if (! (bool) $settings->public_booking_enabled) {
            abort(404);
        }
    }

    protected function assertAntiSpam(OnlineBookingData $data): void
    {
        if (filled($data->honeypot)) {
            throw ValidationException::withMessages([
                'form' => 'Não foi possível processar o agendamento.',
            ]);
        }

        $elapsed = CompanyDateTime::nowUtc()->diffInSeconds($data->formStartedAt, true);

        if ($elapsed < 3) {
            throw ValidationException::withMessages([
                'form' => 'Não foi possível processar o agendamento.',
            ]);
        }
    }

    protected function assertAcceptances($settings, OnlineBookingData $data): void
    {
        if (filled($settings->privacy_notice) && ! $data->privacyAccepted) {
            throw ValidationException::withMessages([
                'privacy_accepted' => 'É necessário aceitar o aviso de privacidade.',
            ]);
        }

        if (filled($settings->booking_terms) && ! $data->termsAccepted) {
            throw ValidationException::withMessages([
                'terms_accepted' => 'É necessário aceitar os termos do agendamento.',
            ]);
        }
    }

    protected function assertAdvanceRules(Company $company, $settings, CarbonImmutable $localStart): void
    {
        $now = CompanyDateTime::nowLocal($company);
        $minimumStart = $now->addMinutes((int) $settings->minimum_advance_minutes);
        $maximumStart = $now->addDays((int) $settings->maximum_advance_days)->endOfDay();

        if ($localStart->lt($minimumStart)) {
            throw ValidationException::withMessages([
                'start_at' => 'O horário selecionado não respeita a antecedência mínima.',
            ]);
        }

        if ($localStart->gt($maximumStart)) {
            throw ValidationException::withMessages([
                'start_at' => 'O horário selecionado está além do limite permitido.',
            ]);
        }
    }
}
