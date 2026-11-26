<?php

namespace App\Http\Controllers;

use App\Enums\CompanyModule;
use App\Models\Company;
use App\Models\Order;
use App\Services\Company\CompanyModuleService;
use App\Services\Orders\CompanyOrderSettingService;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OrderReceiptController
{
    public function __invoke(Request $request, Company $company, Order $order): View
    {
        $user = $request->user();
        abort_unless($user !== null && $user->canAccessTenant($company), 403);
        abort_unless((int) $order->company_id === (int) $company->getKey(), 404);
        abort_unless(app(CompanyModuleService::class)->hasModule($company, CompanyModule::Orders), 404);

        $panel = Filament::getPanel('app');
        Filament::setCurrentPanel($panel);
        Filament::setTenant($company, isQuiet: true);

        abort_unless($user->can('printReceipt', $order), 403);

        $order->loadMissing(['items', 'diningTable']);
        $setting = app(CompanyOrderSettingService::class)->getOrCreate($company);

        return view('orders.receipt', [
            'company' => $company,
            'order' => $order,
            'paperMm' => $setting->receiptPaperMm(),
            'autoPrint' => $request->boolean('imprimir'),
        ]);
    }
}
