@php
    use App\Models\Company;
    use App\Services\Company\CompanyModuleService;
    use Filament\Facades\Filament;

    $tenant = Filament::getTenant();
    $daysRemaining = $tenant instanceof Company
        ? app(CompanyModuleService::class)->trialDaysRemaining($tenant)
        : null;
@endphp

@if ($tenant instanceof Company && $daysRemaining !== null && app(CompanyModuleService::class)->shouldShowTrialBanner($tenant))
    <div class="fi-trial-banner border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
        @if ($daysRemaining === 0)
            Seu período de teste termina hoje. Entre em contato para continuar usando o Agendaqui.
        @else
            Restam {{ $daysRemaining }} {{ $daysRemaining === 1 ? 'dia' : 'dias' }} do seu teste gratuito.
        @endif
    </div>
@endif
