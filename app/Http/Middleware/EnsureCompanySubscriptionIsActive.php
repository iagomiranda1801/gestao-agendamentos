<?php

namespace App\Http\Middleware;

use App\Filament\App\Pages\SubscriptionExpiredPage;
use App\Models\Company;
use App\Services\Company\CompanyModuleService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanySubscriptionIsActive
{
    public function __construct(
        protected CompanyModuleService $moduleService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return $next($request);
        }

        $user = auth()->user();

        if ($user !== null && $user->is_super_admin) {
            return $next($request);
        }

        if ($this->isSubscriptionPage($request)) {
            return $next($request);
        }

        if ($this->moduleService->isAccessAllowed($tenant)) {
            return $next($request);
        }

        return redirect()->to(SubscriptionExpiredPage::getUrl(['tenant' => $tenant]));
    }

    protected function isSubscriptionPage(Request $request): bool
    {
        return $request->routeIs('filament.app.pages.assinatura')
            || str_ends_with($request->path(), '/assinatura');
    }
}
