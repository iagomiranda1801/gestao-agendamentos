<?php

namespace App\Providers;

use App\Events\OnlineAppointmentCreated;
use App\Listeners\SendOnlineBookingWhatsAppNotification;
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

        Filament::serving(function (): void {
            app()->setLocale('pt_BR');
        });
    }
}
