<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\Pwa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PwaController extends Controller
{
    public function panelManifest(): JsonResponse
    {
        return response()
            ->json(Pwa::panelManifest())
            ->header('Content-Type', 'application/manifest+json');
    }

    public function bookingManifest(Company $company): JsonResponse
    {
        return response()
            ->json(Pwa::clientManifest($company, 'booking'))
            ->header('Content-Type', 'application/manifest+json');
    }

    public function ordersManifest(Company $company): JsonResponse
    {
        return response()
            ->json(Pwa::clientManifest($company, 'orders'))
            ->header('Content-Type', 'application/manifest+json');
    }

    public function offline(): Response
    {
        return response()->view('pwa.offline', Pwa::offlineViewData());
    }

    public function serviceWorker(): Response
    {
        $script = (string) file_get_contents(resource_path('pwa/sw.js'));

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
