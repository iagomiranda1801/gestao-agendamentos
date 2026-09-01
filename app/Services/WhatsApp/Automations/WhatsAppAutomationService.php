<?php

namespace App\Services\WhatsApp\Automations;

use App\Enums\AppointmentStatus;
use App\Enums\CompanyModule;
use App\Enums\WhatsAppAutomationSendStatus;
use App\Enums\WhatsAppAutomationType;
use App\Jobs\SendWhatsAppAfterSalesJob;
use App\Jobs\SendWhatsAppAutomationJob;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\Company;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppAutomationSend;
use App\Services\Company\CompanyModuleService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\Outbound\WhatsAppOutboundGate;
use App\Support\CompanyDateTime;
use Illuminate\Validation\ValidationException;
use Throwable;

class WhatsAppAutomationService
{
    public function __construct(
        protected WhatsAppAutomationMessageBuilder $messages,
        protected InactiveClientQuery $inactiveClients,
        protected CompanyModuleService $modules,
        protected CompanySchedulingSettingService $schedulingSettings,
        protected CompanyWhatsAppInstanceService $instances,
        protected EvolutionApiClient $evolution,
        protected WhatsAppOutboundGate $outbound,
    ) {}

    /**
     * @return array<string, WhatsAppAutomation>
     */
    public function ensureDefaults(Company $company): array
    {
        $records = [];

        foreach (WhatsAppAutomationType::cases() as $type) {
            $records[$type->value] = $this->getOrCreate($company, $type);
        }

        return $records;
    }

    public function getOrCreate(Company $company, WhatsAppAutomationType $type): WhatsAppAutomation
    {
        $existing = WhatsAppAutomation::query()
            ->where('company_id', $company->getKey())
            ->where('type', $type->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $defaults = WhatsAppAutomationDefaults::forType($type, $company);
        $automation = new WhatsAppAutomation([
            ...$defaults,
            'type' => $type,
            'is_enabled' => false,
        ]);
        $automation->company()->associate($company);
        $automation->save();

        return $automation->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, WhatsAppAutomationType $type, array $data): WhatsAppAutomation
    {
        $automation = $this->getOrCreate($company, $type);

        $delay = (int) ($data['delay_value'] ?? $automation->delay_value);
        $cooldown = (int) ($data['cooldown_days'] ?? $automation->cooldown_days);
        $template = trim((string) ($data['message_template'] ?? $automation->message_template));

        if ($delay < 1) {
            throw ValidationException::withMessages([
                'delay_value' => 'Informe um intervalo maior que zero.',
            ]);
        }

        if ($type === WhatsAppAutomationType::WinBack && $delay > 365) {
            throw ValidationException::withMessages([
                'delay_value' => 'O intervalo de reconquista deve ser de no máximo 365 dias.',
            ]);
        }

        if ($type !== WhatsAppAutomationType::WinBack && $delay > 168) {
            throw ValidationException::withMessages([
                'delay_value' => 'O intervalo deve ser de no máximo 168 horas.',
            ]);
        }

        if ($cooldown < 0 || $cooldown > 365) {
            throw ValidationException::withMessages([
                'cooldown_days' => 'O intervalo mínimo entre envios deve estar entre 0 e 365 dias.',
            ]);
        }

        if ($template === '') {
            throw ValidationException::withMessages([
                'message_template' => 'Informe o texto da mensagem.',
            ]);
        }

        $automation->fill([
            'is_enabled' => (bool) ($data['is_enabled'] ?? $automation->is_enabled),
            'delay_value' => $delay,
            'cooldown_days' => $cooldown,
            'quiet_hours_start' => $this->normalizeTime($data['quiet_hours_start'] ?? $automation->quiet_hours_start),
            'quiet_hours_end' => $this->normalizeTime($data['quiet_hours_end'] ?? $automation->quiet_hours_end),
            'message_template' => $template,
        ]);
        $automation->save();

        return $automation->refresh();
    }

    public function processDue(): int
    {
        $queued = 0;
        $companyIds = WhatsAppAutomation::query()
            ->where('is_enabled', true)
            ->distinct()
            ->pluck('company_id');

        foreach (Company::query()->whereKey($companyIds)->where('is_active', true)->cursor() as $company) {
            $queued += $this->processCompany($company);
        }

        return $queued;
    }

    public function processCompany(Company $company): int
    {
        if (! $this->modules->hasModule($company, CompanyModule::WhatsApp)) {
            return 0;
        }

        $queued = $this->queueDueReminders($company);
        $queued += $this->queueDueAfterSales($company);

        if ($this->modules->hasModule($company, CompanyModule::Marketing)) {
            $queued += $this->queueDueWinBacks($company);
        }

        return $queued;
    }

    public function queueAfterSalesIfEnabled(Attendance $attendance): void
    {
        $attendance->loadMissing(['company', 'client', 'appointment']);
        $company = $attendance->company;

        if ($company === null || ! $this->modules->hasModule($company, CompanyModule::WhatsApp)) {
            return;
        }

        $automation = $this->getOrCreate($company, WhatsAppAutomationType::AfterSales);

        if (! $automation->is_enabled) {
            return;
        }

        SendWhatsAppAfterSalesJob::dispatch($attendance->getKey())
            ->delay(now()->addHours(max(1, (int) $automation->delay_value)));
    }

    public function sendReminder(Appointment $appointment): bool
    {
        $appointment->loadMissing(['company', 'client']);
        $company = $appointment->company;

        if ($company === null) {
            return false;
        }

        return $this->queueCandidate(
            $this->getOrCreate($company, WhatsAppAutomationType::Reminder),
            $appointment->client,
            $appointment,
            null,
        );
    }

    public function sendAfterSales(Attendance $attendance): bool
    {
        $attendance->loadMissing(['company', 'client', 'appointment']);
        $company = $attendance->company;

        if ($company === null) {
            return false;
        }

        return $this->queueCandidate(
            $this->getOrCreate($company, WhatsAppAutomationType::AfterSales),
            $attendance->client,
            $attendance->appointment,
            $attendance,
        );
    }

    public function sendWinBack(Client $client): bool
    {
        $client->loadMissing('company');
        $company = $client->company;

        if ($company === null) {
            return false;
        }

        return $this->queueCandidate(
            $this->getOrCreate($company, WhatsAppAutomationType::WinBack),
            $client,
            null,
            $client->attendances()->latest('completed_at')->first(),
        );
    }

    public function deliver(WhatsAppAutomationSend $send): void
    {
        $send->loadMissing(['automation.company', 'client', 'appointment', 'attendance']);
        $automation = $send->automation;
        $company = $send->company ?? $automation?->company;

        if ($automation === null || $company === null || $send->status !== WhatsAppAutomationSendStatus::Pending) {
            return;
        }

        if ($this->isQuietHours($company, $automation)) {
            return;
        }

        $instance = $this->resolveInstance($company);

        if ($instance === null) {
            return;
        }

        $phone = (string) ($send->phone ?: $send->client?->phone_normalized ?: '');

        if ($phone === '') {
            $send->forceFill([
                'status' => WhatsAppAutomationSendStatus::Skipped,
                'skip_reason' => 'Sem telefone.',
            ])->save();

            return;
        }

        try {
            $this->evolution->sendText($instance, $phone, (string) $send->message_snapshot);
            $send->forceFill([
                'status' => WhatsAppAutomationSendStatus::Sent,
                'sent_at' => now(),
                'skip_reason' => null,
            ])->save();
        } catch (Throwable $exception) {
            $send->forceFill([
                'status' => WhatsAppAutomationSendStatus::Failed,
                'skip_reason' => mb_substr($exception->getMessage(), 0, 255),
            ])->save();
        }
    }

    public function isQuietHours(Company $company, WhatsAppAutomation $automation): bool
    {
        $local = CompanyDateTime::nowLocal($company);
        $minutes = ((int) $local->format('H') * 60) + (int) $local->format('i');
        $start = CompanyDateTime::timeToMinutes(substr((string) $automation->quiet_hours_start, 0, 8));
        $end = CompanyDateTime::timeToMinutes(substr((string) $automation->quiet_hours_end, 0, 8));

        if ($start === $end) {
            return false;
        }

        return $minutes < $start || $minutes >= $end;
    }

    protected function queueDueReminders(Company $company): int
    {
        $automation = $this->getOrCreate($company, WhatsAppAutomationType::Reminder);

        if (! $automation->is_enabled || ! $this->operationalChannelReady($company)) {
            return 0;
        }

        if ($this->isQuietHours($company, $automation)) {
            return 0;
        }

        $windowEnd = now()->addHours((int) $automation->delay_value);
        $queued = 0;

        Appointment::query()
            ->with('client')
            ->where('company_id', $company->getKey())
            ->whereIn('status', [AppointmentStatus::Confirmed, AppointmentStatus::InProgress])
            ->where('start_at', '>', now())
            ->where('start_at', '<=', $windowEnd)
            ->whereDoesntHave('whatsappAutomationSends', function ($query) use ($automation): void {
                $query->where('whatsapp_automation_id', $automation->getKey());
            })
            ->orderBy('start_at')
            ->limit(8)
            ->get()
            ->each(function (Appointment $appointment) use ($automation, &$queued): void {
                if ($this->queueCandidate($automation, $appointment->client, $appointment, null)) {
                    $queued++;
                }
            });

        return $queued;
    }

    protected function queueDueAfterSales(Company $company): int
    {
        $automation = $this->getOrCreate($company, WhatsAppAutomationType::AfterSales);

        if (! $automation->is_enabled || ! $this->operationalChannelReady($company)) {
            return 0;
        }

        if ($this->isQuietHours($company, $automation)) {
            return 0;
        }

        $dueBefore = now()->subHours((int) $automation->delay_value);
        $queued = 0;

        Attendance::query()
            ->with(['client', 'appointment'])
            ->where('company_id', $company->getKey())
            ->where('completed_at', '<=', $dueBefore)
            ->where('completed_at', '>=', now()->subDays(7))
            ->whereDoesntHave('whatsappAutomationSends', function ($query) use ($automation): void {
                $query->where('whatsapp_automation_id', $automation->getKey());
            })
            ->orderBy('completed_at')
            ->limit(8)
            ->get()
            ->each(function (Attendance $attendance) use ($automation, &$queued): void {
                if ($this->queueCandidate($automation, $attendance->client, $attendance->appointment, $attendance)) {
                    $queued++;
                }
            });

        return $queued;
    }

    protected function queueDueWinBacks(Company $company): int
    {
        $automation = $this->getOrCreate($company, WhatsAppAutomationType::WinBack);

        if (! $automation->is_enabled) {
            return 0;
        }

        if ($this->isQuietHours($company, $automation) || $this->resolveInstance($company) === null) {
            return 0;
        }

        if (! $this->outbound->allowsMarketing($company)) {
            return 0;
        }

        $queued = 0;
        $cooldownFrom = now()->subDays(max(1, (int) $automation->cooldown_days));

        $this->inactiveClients
            ->optedInInactive($company, (int) $automation->delay_value)
            ->whereDoesntHave('whatsappAutomationSends', function ($query) use ($automation, $cooldownFrom): void {
                $query->where('whatsapp_automation_id', $automation->getKey())
                    ->where(function ($query) use ($cooldownFrom): void {
                        $query->where('status', WhatsAppAutomationSendStatus::Pending->value)
                            ->orWhere(function ($query) use ($cooldownFrom): void {
                                $query->where('status', WhatsAppAutomationSendStatus::Sent->value)
                                    ->where('sent_at', '>=', $cooldownFrom);
                            });
                    });
            })
            ->orderBy('id')
            ->limit(3)
            ->get()
            ->each(function (Client $client) use ($automation, &$queued): void {
                if ($this->queueCandidate($automation, $client, null, $client->attendances()->latest('completed_at')->first())) {
                    $queued++;
                }
            });

        return $queued;
    }

    protected function queueCandidate(
        WhatsAppAutomation $automation,
        ?Client $client,
        ?Appointment $appointment,
        ?Attendance $attendance,
    ): bool {
        $company = $automation->company ?? $appointment?->company ?? $attendance?->company ?? $client?->company;

        if ($company === null || ! $automation->is_enabled) {
            return false;
        }

        if ($this->isQuietHours($company, $automation)) {
            return false;
        }

        $type = $automation->type;

        if (in_array($type, [WhatsAppAutomationType::Reminder, WhatsAppAutomationType::AfterSales], true)
            && ! $this->operationalChannelReady($company)) {
            return false;
        }

        if ($this->resolveInstance($company) === null) {
            return false;
        }

        if ($client === null || ! $client->is_active) {
            return false;
        }

        $phone = preg_replace('/\D+/', '', (string) $client->phone_normalized) ?? '';

        if ($phone === '') {
            return false;
        }

        if ($type->requiresMarketingOptIn() && ! $this->outbound->allowsMarketing($company)) {
            return false;
        }

        if ($type->requiresMarketingOptIn() && ! $client->whatsapp_marketing_opt_in) {
            return false;
        }

        if (in_array($type, [WhatsAppAutomationType::AfterSales, WhatsAppAutomationType::WinBack], true)
            && $this->hasFutureAppointment($company, $client, $appointment)) {
            return false;
        }

        $alreadyQueued = WhatsAppAutomationSend::query()
            ->where('whatsapp_automation_id', $automation->getKey())
            ->when(
                $type === WhatsAppAutomationType::Reminder && $appointment !== null,
                fn ($query) => $query->where('appointment_id', $appointment->getKey()),
            )
            ->when(
                $type === WhatsAppAutomationType::AfterSales && $attendance !== null,
                fn ($query) => $query->where('attendance_id', $attendance->getKey()),
            )
            ->when(
                $type === WhatsAppAutomationType::WinBack,
                function ($query) use ($client, $automation): void {
                    $cooldownFrom = now()->subDays(max(1, (int) $automation->cooldown_days));
                    $query->where('client_id', $client->getKey())
                        ->where(function ($query) use ($cooldownFrom): void {
                            $query->where('status', WhatsAppAutomationSendStatus::Pending->value)
                                ->orWhere(function ($query) use ($cooldownFrom): void {
                                    $query->where('status', WhatsAppAutomationSendStatus::Sent->value)
                                        ->where('sent_at', '>=', $cooldownFrom);
                                });
                        });
                },
            )
            ->exists();

        if ($alreadyQueued) {
            return false;
        }

        $send = new WhatsAppAutomationSend([
            'client_id' => $client->getKey(),
            'appointment_id' => $type === WhatsAppAutomationType::WinBack ? null : $appointment?->getKey(),
            'attendance_id' => $type === WhatsAppAutomationType::WinBack ? null : $attendance?->getKey(),
            'type' => $type,
            'phone' => $phone,
            'message_snapshot' => $this->messages->render(
                $company,
                (string) $automation->message_template,
                $client,
                $appointment,
                $attendance,
            ),
            'status' => WhatsAppAutomationSendStatus::Pending,
        ]);
        $send->company()->associate($company);
        $send->automation()->associate($automation);

        try {
            $send->save();
        } catch (Throwable) {
            return false;
        }

        SendWhatsAppAutomationJob::dispatch($send->getKey());

        return true;
    }

    protected function hasFutureAppointment(Company $company, Client $client, ?Appointment $except = null): bool
    {
        return Appointment::query()
            ->where('company_id', $company->getKey())
            ->where('client_id', $client->getKey())
            ->whereIn('status', WhatsAppAutomationMessageBuilder::futureBlockingStatuses())
            ->where('start_at', '>', now())
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();
    }

    protected function operationalChannelReady(Company $company): bool
    {
        $settings = $this->schedulingSettings->getOrCreate($company);

        return (bool) $settings->whatsapp_notifications_enabled;
    }

    protected function resolveInstance(Company $company): ?string
    {
        $settings = $this->schedulingSettings->getOrCreate($company);
        $default = $this->instances->defaultForCompany($company);

        if ($default !== null && ! in_array($default->status, ['open', 'connected', null, ''], true)) {
            return null;
        }

        $instance = $this->evolution->resolveInstance($default?->instance_name ?: $settings->whatsapp_instance);

        return $instance !== '' ? $instance : null;
    }

    protected function normalizeTime(mixed $value): string
    {
        $raw = substr((string) $value, 0, 8);

        if (preg_match('/^\d{2}:\d{2}$/', $raw) === 1) {
            return $raw.':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $raw) === 1) {
            return $raw;
        }

        return '08:00:00';
    }
}
