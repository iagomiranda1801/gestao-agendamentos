<?php

namespace App\Http\Controllers;

use App\Filament\App\Resources\TattooRequests\TattooRequestResource;
use App\Models\Company;
use App\Models\TattooPaymentReceipt;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

class TattooReceiptController
{
    public function __invoke(Company $company, TattooPaymentReceipt $receipt)
    {
        abort_unless((int) $company->id === (int) $receipt->company_id, 404);
        abort_unless(auth()->user()?->canAccessTenant($company), 403);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($company);
        abort_unless(TattooRequestResource::canManageRequests() || auth()->user()->can('view', $receipt->request), 403);
        abort_unless(Storage::disk($receipt->disk)->exists($receipt->path), 404);

        return Storage::disk($receipt->disk)->response($receipt->path, basename($receipt->path),
            ['Content-Type' => $receipt->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }
}
