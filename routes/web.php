<?php

use App\Http\Controllers\AsaasWebhookController;
use App\Http\Controllers\EvolutionWebhookController;
use App\Http\Controllers\PrintDentalTreatmentPlanController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\ServiceOrderDocumentController;
use App\Http\Controllers\TattooImageController;
use App\Http\Controllers\TattooQuotePdfController;
use App\Http\Controllers\TattooReceiptController;
use App\Livewire\PublicBooking\BookingWizard;
use App\Livewire\PublicBooking\ManageAppointment;
use App\Livewire\PublicOrders\OrderWizard;
use App\Livewire\Signup\CompanySignupWizard;
use App\Support\Segment;
use Illuminate\Support\Facades\Route;

Route::get('/os/documento/{token}', [ServiceOrderDocumentController::class, 'document'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:60,1')->name('service-orders.document');
Route::get('/os/orcamento/{token}', [ServiceOrderDocumentController::class, 'quote'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:60,1')->name('service-orders.quote');
Route::post('/os/orcamento/{token}', [ServiceOrderDocumentController::class, 'decide'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:10,1')->name('service-orders.decide');
Route::get('/os/fotos/{photo}', [ServiceOrderDocumentController::class, 'photo'])->name('service-orders.photo');

foreach (Segment::hosts('salon') as $salonHost) {
    Route::domain($salonHost)->group(function (): void {
        Route::redirect('/', '/painel');
    });
}

Route::domain((string) config('segments.tattoo.domain'))->group(function (): void {
    Route::view('/', 'landing.tattoo')->name('tattoo.landing');
    Route::get('/cadastro', \App\Livewire\Signup\TattooSignup::class)
        ->middleware('throttle:5,1')->name('tattoo.signup');
});

Route::redirect('/', '/admin');

Route::get('/cadastro', CompanySignupWizard::class)
    ->middleware('throttle:5,1')
    ->name('signup.company');

Route::get('/manifest.webmanifest', [PwaController::class, 'panelManifest'])->name('pwa.manifest.panel');
Route::get('/agendar/{company:slug}/manifest.webmanifest', [PwaController::class, 'bookingManifest'])->name('pwa.manifest.booking');
Route::get('/pedir/{company:slug}/manifest.webmanifest', [PwaController::class, 'ordersManifest'])->name('pwa.manifest.orders');
Route::get('/offline', [PwaController::class, 'offline'])->name('pwa.offline');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.sw');

Route::get('/agendar/{company:slug}', BookingWizard::class)->name('public.booking.show');
Route::get('/pedir/{company:slug}', OrderWizard::class)->name('public.orders.show');
Route::get('/agendamento/{token}', ManageAppointment::class)->name('public.appointment.manage');
Route::post('/webhooks/evolution/{instance?}', EvolutionWebhookController::class)
    ->name('webhooks.evolution');

Route::post('/webhooks/asaas/{company:slug}', AsaasWebhookController::class)
    ->name('webhooks.asaas');
Route::post('/webhooks/asaas/{company:slug}/fiscal-sandbox', [AsaasWebhookController::class, 'fiscalSandbox'])
    ->name('webhooks.asaas.fiscal-sandbox');

Route::get('/app/empresa/{company:slug}/clinico/planos/{plan}/imprimir', PrintDentalTreatmentPlanController::class)
    ->middleware('auth')
    ->name('dental.treatment-plan.print');

Route::get('/app/empresa/{company:slug}/tatuagem/fotos/{image}', TattooImageController::class)
    ->middleware('auth')
    ->name('tattoo.images.download');

Route::get('/app/empresa/{company:slug}/tatuagem/orcamentos/{quote}', TattooQuotePdfController::class)
    ->middleware('auth')
    ->name('tattoo.quotes.pdf');

Route::get('/app/empresa/{company:slug}/tatuagem/comprovantes/{receipt}', TattooReceiptController::class)
    ->middleware('auth')->name('tattoo.receipts.download');
