<?php

namespace App\Providers;

use App\Events\AppointmentCancelled;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Events\OnlineAppointmentCreated;
use App\Listeners\SendAppointmentCancelledNotifications;
use App\Listeners\SendAppointmentRescheduledNotifications;
use App\Listeners\SendOnlineBookingWhatsAppNotification;
use App\Listeners\SendProfessionalAppointmentNotifications;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            OnlineAppointmentCreated::class,
            SendOnlineBookingWhatsAppNotification::class,
        );

        Event::listen(
            AppointmentCreated::class,
            SendOnlineBookingWhatsAppNotification::class,
        );

        Event::listen(OnlineAppointmentCreated::class, SendProfessionalAppointmentNotifications::class);
        Event::listen(AppointmentCreated::class, SendProfessionalAppointmentNotifications::class);
        Event::listen(AppointmentConfirmed::class, SendProfessionalAppointmentNotifications::class);
        Event::listen(AppointmentRescheduled::class, SendProfessionalAppointmentNotifications::class);
        Event::listen(AppointmentCancelled::class, SendProfessionalAppointmentNotifications::class);

        Event::listen(
            AppointmentCancelled::class,
            SendAppointmentCancelledNotifications::class,
        );

        Event::listen(
            AppointmentRescheduled::class,
            SendAppointmentRescheduledNotifications::class,
        );

        Filament::serving(function (): void {
            app()->setLocale('pt_BR');
        });
    }
}
