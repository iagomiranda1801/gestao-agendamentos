<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyRole;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\PlatformInvoice;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use App\Services\Company\CompanySubscriptionService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SubscriptionExpiredPage extends Page
{
    protected static ?string $slug = 'assinatura';

    protected static ?string $navigationLabel = 'Assinatura';

    protected static ?string $title = 'Assinatura';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected string $view = 'filament.app.pages.subscription-expired';

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Company || ! $user instanceof User) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        if ($user->hasActiveRoleInCompany($tenant, CompanyRole::CompanyAdmin, CompanyRole::Manager)) {
            return true;
        }

        return ! app(CompanyModuleService::class)->isAccessAllowed($tenant);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        return $user instanceof User
            && $tenant instanceof Company
            && ($user->is_super_admin || $user->hasActiveRoleInCompany($tenant, CompanyRole::CompanyAdmin, CompanyRole::Manager));
    }

    public function getHeading(): string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return 'Assinatura';
        }

        if (! app(CompanyModuleService::class)->isAccessAllowed($tenant)) {
            if ($tenant->subscription_status === SubscriptionStatus::Trial) {
                return 'Seu período de teste terminou';
            }

            return 'Sua assinatura precisa ser renovada';
        }

        return 'Assinatura';
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        $subscriptions = app(CompanySubscriptionService::class);
        $modules = app(CompanyModuleService::class);

        if (! $tenant instanceof Company) {
            return [];
        }

        $canSeeInvoices = $user instanceof User
            && ($user->is_super_admin || $user->hasActiveRoleInCompany($tenant, CompanyRole::CompanyAdmin, CompanyRole::Manager));

        $outstanding = $canSeeInvoices ? $subscriptions->outstandingInvoice($tenant) : null;
        $invoices = $canSeeInvoices
            ? $tenant->platformInvoices()->latest('due_at')->limit(20)->get()
            : collect();
        $accessAllowed = $modules->isAccessAllowed($tenant);
        $billingDaysRemaining = $subscriptions->billingDaysRemaining($tenant);
        [$statusTone, $statusLabel, $statusHint] = $this->statusPresentation(
            $tenant,
            $accessAllowed,
            $billingDaysRemaining,
            $outstanding,
        );

        return [
            'company' => $tenant,
            'accessAllowed' => $accessAllowed,
            'canSeeInvoices' => $canSeeInvoices,
            'moduleLabels' => collect($modules->enabledModules($tenant))
                ->map(fn ($module) => $module->label())
                ->all(),
            'intervalLabel' => $tenant->billing_interval?->label(),
            'quotedPrice' => $subscriptions->formatReais($tenant->quoted_price_cents),
            'periodEnd' => $tenant->current_period_end?->timezone($tenant->timezone ?: 'America/Sao_Paulo')->format('d/m/Y'),
            'outstanding' => $outstanding,
            'invoices' => $invoices,
            'pixInstructions' => (string) config('subscriptions.pix_instructions'),
            'formatReais' => fn (?int $cents): string => $subscriptions->formatReais($cents),
            'statusTone' => $statusTone,
            'statusLabel' => $statusLabel,
            'statusHint' => $statusHint,
            'billingDaysRemaining' => $billingDaysRemaining,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    protected function statusPresentation(
        Company $company,
        bool $accessAllowed,
        ?int $billingDays,
        ?PlatformInvoice $outstanding,
    ): array {
        if (! $accessAllowed) {
            if ($company->subscription_status === SubscriptionStatus::Trial) {
                return ['danger', 'Teste encerrado', 'Pague a fatura para renovar'];
            }

            return ['danger', 'Assinatura pendente', 'Pague a fatura para renovar'];
        }

        if ($company->subscription_status === SubscriptionStatus::Trial) {
            return ['warning', 'Período de teste', 'Aproveite o teste. Depois, a fatura aparece aqui.'];
        }

        if ($company->current_period_end === null) {
            return ['gray', 'Sem vencimento', 'Sem ciclo definido ainda'];
        }

        if ($outstanding !== null) {
            return ['warning', 'Fatura em aberto', 'Pague a fatura para renovar'];
        }

        $warningDays = max(1, (int) config('subscriptions.renewal_warning_days', 7));

        if ($billingDays !== null && $billingDays <= $warningDays) {
            $hint = $billingDays === 0
                ? 'Sua assinatura vence hoje.'
                : 'Sua assinatura vence em '.$billingDays.' '.($billingDays === 1 ? 'dia' : 'dias').'.';

            return ['warning', 'Renovar em breve', $hint];
        }

        return ['success', 'Assinatura ativa', 'Tudo em dia'];
    }
}
