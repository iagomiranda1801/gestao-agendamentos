<?php

namespace App\Services\Admin;

use App\Enums\AppointmentStatus;
use App\Enums\CompanyModule;
use App\Enums\SubscriptionStatus;
use App\Enums\WhatsAppCampaignRecipientStatus;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\EvolutionWebhookEvent;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminDashboardAggregator
{
    /**
     * @return array<string, mixed>
     */
    public function aggregate(): array
    {
        $today = CarbonImmutable::now('America/Sao_Paulo')->startOfDay();
        $todayStart = $today->utc();
        $todayEnd = $todayStart->addDay();
        $weekStart = $todayStart->subDays(6);
        $trialLimit = $todayStart->addDays(3);

        return [
            'dateLabel' => $today->translatedFormat('d/m/Y'),
            'cards' => $this->cards($todayStart, $todayEnd),
            'alerts' => $this->alerts($todayStart, $todayEnd, $trialLimit),
            'companiesAttention' => $this->companiesAttention($todayStart, $trialLimit),
            'usage' => $this->usage($weekStart, $todayEnd),
            'whatsapp' => $this->whatsAppHealth($todayStart, $todayEnd),
            'latestFailures' => $this->latestFailures(),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    protected function cards(CarbonImmutable $todayStart, CarbonImmutable $todayEnd): array
    {
        $companies = Company::query();
        $appointmentsToday = Appointment::query()
            ->where('start_at', '>=', $todayStart)
            ->where('start_at', '<', $todayEnd);

        return [
            $this->card('Empresas', (string) (clone $companies)->count(), 'Total cadastradas', 'primary', $this->companiesUrl()),
            $this->card('Ativas', (string) (clone $companies)->where('is_active', true)->count(), 'Com acesso liberado', 'success', $this->companiesUrl()),
            $this->card('Trial', (string) (clone $companies)->where('subscription_status', SubscriptionStatus::Trial->value)->count(), 'Em avaliação', 'warning', $this->companiesUrl()),
            $this->card('Expiradas', (string) (clone $companies)->where('subscription_status', SubscriptionStatus::Expired->value)->count(), 'Pedem atenção', 'danger', $this->companiesUrl()),
            $this->card('Usuários ativos', (string) User::query()->where('is_active', true)->count(), 'Contas habilitadas', 'primary', $this->usersUrl()),
            $this->card('Agenda hoje', (string) (clone $appointmentsToday)->count(), 'Agendamentos no dia', 'primary', $this->companiesUrl()),
            $this->card('Cancelados hoje', (string) (clone $appointmentsToday)->where('status', AppointmentStatus::Cancelled->value)->count(), 'Possível ruído operacional', 'warning', $this->companiesUrl()),
            $this->card('Jobs falhados', (string) $this->failedJobsSince($todayStart), 'Falhas nas últimas 24h', 'danger', '/admin/operacao/jobs-falhos'),
        ];
    }

    /**
     * @return list<array<string, string|int>>
     */
    protected function alerts(CarbonImmutable $todayStart, CarbonImmutable $todayEnd, CarbonImmutable $trialLimit): array
    {
        $alerts = [
            $this->alert(
                'Trials vencendo em até 3 dias',
                Company::query()
                    ->where('subscription_status', SubscriptionStatus::Trial->value)
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '>=', $todayStart)
                    ->where('trial_ends_at', '<=', $trialLimit)
                    ->count(),
                'Bom momento para contato comercial.',
                $this->companiesUrl(),
                'warning',
            ),
            $this->alert(
                'Empresas expiradas',
                Company::query()->where('subscription_status', SubscriptionStatus::Expired->value)->count(),
                'Revise cobrança, bloqueio ou reativação.',
                $this->companiesUrl(),
                'danger',
            ),
            $this->alert(
                'Empresas sem admin ativo',
                Company::query()
                    ->whereDoesntHave('users', function ($query): void {
                        $query->where('company_user.role', 'company_admin')
                            ->where('company_user.is_active', true);
                    })
                    ->count(),
                'Sem administrador, a empresa pode ficar travada.',
                $this->companiesUrl(),
                'danger',
            ),
            $this->alert(
                'Jobs falhados hoje',
                $this->failedJobsSince($todayStart),
                'Investigue fila, SMTP, WhatsApp e jobs longos.',
                '/admin/operacao/jobs-falhos',
                'danger',
            ),
            $this->alert(
                'Webhooks Evolution com erro hoje',
                $this->evolutionWebhookErrorsToday($todayStart, $todayEnd),
                'Pode indicar número inválido, bloqueio ou falha da instância.',
                '/admin/operacao/webhooks',
                'danger',
            ),
            $this->alert(
                'WhatsApp aceito, mas sem entrega',
                WhatsAppCampaignRecipient::query()
                    ->where('status', WhatsAppCampaignRecipientStatus::Accepted->value)
                    ->count(),
                'Mensagens aceitas pela Evolution, ainda sem confirmação final.',
                $this->companiesUrl(),
                'warning',
            ),
        ];

        return collect($alerts)
            ->filter(fn (array $alert): bool => (int) $alert['count'] > 0)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function companiesAttention(CarbonImmutable $todayStart, CarbonImmutable $trialLimit): Collection
    {
        return Company::query()
            ->withCount([
                'users as active_admins_count' => function ($query): void {
                    $query->where('company_user.role', 'company_admin')
                        ->where('company_user.is_active', true);
                },
                'appointments as appointments_last_7_days_count' => function ($query) use ($todayStart): void {
                    $query->where('start_at', '>=', $todayStart->subDays(6));
                },
                'whatsappCampaigns as failed_campaigns_count' => function ($query): void {
                    $query->where('failed_count', '>', 0);
                },
            ])
            ->where(function ($query) use ($todayStart, $trialLimit): void {
                $query->where('is_active', false)
                    ->orWhere('subscription_status', SubscriptionStatus::Expired->value)
                    ->orWhere(function ($query) use ($todayStart, $trialLimit): void {
                        $query->where('subscription_status', SubscriptionStatus::Trial->value)
                            ->whereNotNull('trial_ends_at')
                            ->where('trial_ends_at', '>=', $todayStart)
                            ->where('trial_ends_at', '<=', $trialLimit);
                    })
                    ->orWhereDoesntHave('users', function ($query): void {
                        $query->where('company_user.role', 'company_admin')
                            ->where('company_user.is_active', true);
                    })
                    ->orWhereHas('whatsappCampaigns', function ($query): void {
                        $query->where('failed_count', '>', 0);
                    });
            })
            ->orderByRaw("case when subscription_status = ? then 0 when is_active = 0 then 1 else 2 end", [SubscriptionStatus::Expired->value])
            ->latest()
            ->limit(8)
            ->get()
            ->map(function (Company $company): array {
                return [
                    'name' => $company->name,
                    'status' => $company->subscription_status?->label() ?? '-',
                    'isActive' => (bool) $company->is_active,
                    'modules' => collect($company->enabled_modules ?? [])
                        ->map(fn (string $module): string => CompanyModule::tryFrom($module)?->label() ?? $module)
                        ->take(3)
                        ->implode(', '),
                    'trialEndsAt' => $company->trial_ends_at?->format('d/m/Y'),
                    'activeAdmins' => (int) $company->active_admins_count,
                    'appointments7d' => (int) $company->appointments_last_7_days_count,
                    'failedCampaigns' => (int) $company->failed_campaigns_count,
                    'url' => $this->companyEditUrl($company),
                ];
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function usage(CarbonImmutable $weekStart, CarbonImmutable $todayEnd): array
    {
        return [
            'appointments7d' => Appointment::query()
                ->where('start_at', '>=', $weekStart)
                ->where('start_at', '<', $todayEnd)
                ->count(),
            'onlineAppointments7d' => Appointment::query()
                ->online()
                ->where('start_at', '>=', $weekStart)
                ->where('start_at', '<', $todayEnd)
                ->count(),
            'campaigns7d' => WhatsAppCampaign::query()
                ->where('created_at', '>=', $weekStart)
                ->where('created_at', '<', $todayEnd)
                ->count(),
            'activeCompanies7d' => Appointment::query()
                ->where('start_at', '>=', $weekStart)
                ->where('start_at', '<', $todayEnd)
                ->distinct('company_id')
                ->count('company_id'),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function whatsAppHealth(CarbonImmutable $todayStart, CarbonImmutable $todayEnd): array
    {
        return [
            'campaignsToday' => WhatsAppCampaign::query()
                ->where('created_at', '>=', $todayStart)
                ->where('created_at', '<', $todayEnd)
                ->count(),
            'accepted' => WhatsAppCampaignRecipient::query()
                ->where('status', WhatsAppCampaignRecipientStatus::Accepted->value)
                ->count(),
            'sent' => WhatsAppCampaignRecipient::query()
                ->whereIn('status', [
                    WhatsAppCampaignRecipientStatus::Sent->value,
                    WhatsAppCampaignRecipientStatus::Delivered->value,
                    WhatsAppCampaignRecipientStatus::Read->value,
                ])
                ->count(),
            'failed' => WhatsAppCampaignRecipient::query()
                ->where('status', WhatsAppCampaignRecipientStatus::Failed->value)
                ->count(),
            'webhooksToday' => $this->evolutionWebhooksToday($todayStart, $todayEnd),
        ];
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    protected function latestFailures(): Collection
    {
        if (! Schema::hasTable('failed_jobs')) {
            return collect();
        }

        return DB::table('failed_jobs')
            ->latest('failed_at')
            ->limit(5)
            ->get(['uuid', 'queue', 'exception', 'failed_at'])
            ->map(fn ($failure): array => [
                'uuid' => (string) $failure->uuid,
                'queue' => (string) $failure->queue,
                'failedAt' => (string) $failure->failed_at,
                'error' => mb_substr(preg_replace('/\s+/', ' ', (string) $failure->exception) ?? '', 0, 220),
            ]);
    }

    protected function failedJobsSince(CarbonImmutable $since): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return DB::table('failed_jobs')
            ->where('failed_at', '>=', $since)
            ->count();
    }

    protected function evolutionWebhookErrorsToday(CarbonImmutable $todayStart, CarbonImmutable $todayEnd): int
    {
        if (! Schema::hasTable('evolution_webhook_events')) {
            return 0;
        }

        return EvolutionWebhookEvent::query()
            ->where('created_at', '>=', $todayStart)
            ->where('created_at', '<', $todayEnd)
            ->where('provider_status', 'ERROR')
            ->count();
    }

    protected function evolutionWebhooksToday(CarbonImmutable $todayStart, CarbonImmutable $todayEnd): int
    {
        if (! Schema::hasTable('evolution_webhook_events')) {
            return 0;
        }

        return EvolutionWebhookEvent::query()
            ->where('created_at', '>=', $todayStart)
            ->where('created_at', '<', $todayEnd)
            ->count();
    }

    protected function companiesUrl(): string
    {
        return route('filament.admin.resources.companies.index');
    }

    protected function usersUrl(): string
    {
        return route('filament.admin.resources.users.index');
    }

    protected function companyEditUrl(Company $company): string
    {
        return route('filament.admin.resources.companies.edit', ['record' => $company]);
    }

    /**
     * @return array{label: string, value: string, description: string, color: string, url: string}
     */
    protected function card(string $label, string $value, string $description, string $color, string $url): array
    {
        return compact('label', 'value', 'description', 'color', 'url');
    }

    /**
     * @return array{label: string, count: int, description: string, url: string, color: string}
     */
    protected function alert(string $label, int $count, string $description, string $url, string $color): array
    {
        return compact('label', 'count', 'description', 'url', 'color');
    }
}
