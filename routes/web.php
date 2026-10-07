<?php

use App\Http\Controllers\EvolutionWebhookController;
use App\Http\Controllers\PrintDentalTreatmentPlanController;
use App\Http\Controllers\TattooImageController;
use App\Http\Controllers\TattooQuotePdfController;
use App\Http\Controllers\TattooReceiptController;
use App\Livewire\PublicBooking\BookingWizard;
use App\Livewire\PublicBooking\ManageAppointment;
use App\Livewire\PublicOrders\OrderWizard;
use App\Livewire\Signup\CompanySignupWizard;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/cadastro', CompanySignupWizard::class)
    ->middleware('throttle:5,1')
    ->name('signup.company');

Route::get('/agendar/{company:slug}', BookingWizard::class)->name('public.booking.show');
Route::get('/pedir/{company:slug}', OrderWizard::class)->name('public.orders.show');
Route::get('/agendamento/{token}', ManageAppointment::class)->name('public.appointment.manage');
Route::post('/webhooks/evolution/{instance?}', EvolutionWebhookController::class)
    ->name('webhooks.evolution');

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
