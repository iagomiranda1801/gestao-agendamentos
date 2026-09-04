@php
    use App\Models\Company;
    use App\Services\Company\CompanyModuleService;
    use App\Services\Company\CompanySubscriptionService;
    use Filament\Facades\Filament;

    $tenant = Filament::getTenant();
    $modules = app(CompanyModuleService::class);
    $subscriptions = app(CompanySubscriptionService::class);
    $trialDays = $tenant instanceof Company ? $modules->trialDaysRemaining($tenant) : null;
    $billingDays = $tenant instanceof Company ? $subscriptions->billingDaysRemaining($tenant) : null;
@endphp

@if ($tenant instanceof Company && $trialDays !== null && $modules->shouldShowTrialBanner($tenant))
    <div class="fi-trial-banner border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
        @if ($trialDays === 0)
            Seu período de teste termina hoje. Entre em contato para continuar usando o Agendaqui.
        @else
            Restam {{ $trialDays }} {{ $trialDays === 1 ? 'dia' : 'dias' }} do seu teste gratuito.
        @endif
    </div>
@elseif ($tenant instanceof Company && $billingDays !== null && $subscriptions->shouldShowBillingBanner($tenant))
    <div class="fi-trial-banner border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
        @if ($billingDays === 0)
            Sua assinatura vence hoje. Entre em contato com a Agendaqui para renovar via PIX.
        @else
            Sua assinatura vence em {{ $billingDays }} {{ $billingDays === 1 ? 'dia' : 'dias' }}
            @if ($tenant->quoted_price_cents)
                ({{ $subscriptions->formatReais($tenant->quoted_price_cents) }})
            @endif
            . Entre em contato para renovar via PIX.
        @endif
    </div>
@endif
