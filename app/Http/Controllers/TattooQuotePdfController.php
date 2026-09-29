<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\TattooQuote;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Symfony\Component\HttpFoundation\Response;

class TattooQuotePdfController
{
    public function __invoke(Company $company, TattooQuote $quote): Response
    {
        abort_unless((int) $company->getKey() === (int) $quote->company_id, 404);
        abort_unless(auth()->user()?->canAccessTenant($company), 403);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($company);

        $quote->loadMissing(['request.client', 'request.company']);
        abort_unless(auth()->user()->can('view', $quote->request), 403);

        $filename = 'orcamento-tatuagem-v'.$quote->version.'.pdf';

        return Pdf::loadView('tattoo.quote', [
            'company' => $company,
            'quote' => $quote,
            'request' => $quote->request,
        ])
            ->setPaper('a4')
            ->download($filename);
    }
}
