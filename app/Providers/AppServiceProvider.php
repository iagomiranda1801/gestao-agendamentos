<?php

namespace App\Providers;

use App\Events\AppointmentCancelled;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Events\OnlineAppointmentCreated;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Listeners\SendAppointmentCancelledNotifications;
use App\Listeners\SendAppointmentRescheduledNotifications;
use App\Listeners\SendOnlineBookingWhatsAppNotification;
use App\Listeners\SendOrderWhatsAppNotification;
use App\Listeners\SendProfessionalAppointmentNotifications;
use App\Models\Company;
use App\Models\User;
use App\Observers\AdminAuditObserver;
use App\Services\WhatsApp\Automations\WhatsAppAutomationService;
use Filament\Facades\Filament;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('external-integrations', function (Request $request): array {
            $identity = hash('sha256', (string) $request->bearerToken());

            return [
                Limit::perMinute(60)->by('integration-ip:'.$request->ip())->response(
                    fn (Request $request, array $headers) => response()->json(['success' => false, 'message' => 'Limite de requisições excedido.'], 429, $headers),
                ),
                Limit::perMinute(30)->by('integration-key:'.$identity)->response(
                    fn (Request $request, array $headers) => response()->json(['success' => false, 'message' => 'Limite de requisições excedido.'], 429, $headers),
                ),
            ];
        });

        config(['livewire.temporary_file_upload.disk' => 'local']);

        Company::observe(AdminAuditObserver::class);
        User::observe(AdminAuditObserver::class);

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
        Event::listen(AppointmentRescheduled::class, function (AppointmentRescheduled $event): void {
            app(WhatsAppAutomationService::class)->clearPendingReminder($event->appointment);
        });
        Event::listen(AppointmentCancelled::class, SendProfessionalAppointmentNotifications::class);

        Event::listen(
            AppointmentCancelled::class,
            SendAppointmentCancelledNotifications::class,
        );

        Event::listen(
            AppointmentRescheduled::class,
            SendAppointmentRescheduledNotifications::class,
        );

        Event::listen(OrderCreated::class, SendOrderWhatsAppNotification::class);
        Event::listen(OrderStatusChanged::class, SendOrderWhatsAppNotification::class);

        Filament::serving(function (): void {
            app()->setLocale('pt_BR');
        });
    }
}
