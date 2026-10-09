<?php

namespace App\Http\Middleware;

use App\Filament\App\Pages\Dashboard;
use App\Models\Company;
use App\Support\Segment;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToSegmentPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $panelId = Segment::panelIdForCompany($tenant);

        if ($panelId === Segment::DEFAULT_PANEL || Filament::getCurrentPanel()?->getId() !== Segment::DEFAULT_PANEL) {
            return $next($request);
        }

        return redirect()->away(Dashboard::getUrl(['tenant' => $tenant], panel: $panelId));
    }
}
