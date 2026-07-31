<?php

use App\Http\Controllers\EvolutionWebhookController;
use App\Livewire\PublicBooking\BookingWizard;
use App\Livewire\PublicBooking\ManageAppointment;
use App\Livewire\Signup\CompanySignupWizard;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/cadastro', CompanySignupWizard::class)
    ->middleware('throttle:5,1')
    ->name('signup.company');

Route::get('/agendar/{company:slug}', BookingWizard::class)->name('public.booking.show');
Route::get('/agendamento/{token}', ManageAppointment::class)->name('public.appointment.manage');
Route::post('/webhooks/evolution', EvolutionWebhookController::class)
    ->name('webhooks.evolution');
