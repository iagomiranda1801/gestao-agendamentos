<?php

use App\Livewire\PublicBooking\BookingWizard;
use App\Livewire\PublicBooking\ManageAppointment;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/agendar/{company:slug}', BookingWizard::class)->name('public.booking.show');
Route::get('/agendamento/{token}', ManageAppointment::class)->name('public.appointment.manage');
