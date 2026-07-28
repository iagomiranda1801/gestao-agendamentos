<?php

namespace App\Providers;

use App\Jobs\SendWhatsAppAppointmentConfirmationJob;
use App\Jobs\SendWhatsAppStaffBookingAlertJob;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal
                || $entry->isReportableException()
                || $entry->isFailedRequest()
                || $entry->isFailedJob()
                || $entry->isScheduledTask()
                || $this->isWhatsAppBookingJob($entry)
                || $entry->hasMonitoredTag();
        });
    }

    protected function isWhatsAppBookingJob(IncomingEntry $entry): bool
    {
        if ($entry->type !== EntryType::JOB) {
            return false;
        }

        return in_array($entry->content['name'] ?? null, [
            SendWhatsAppAppointmentConfirmationJob::class,
            SendWhatsAppStaffBookingAlertJob::class,
        ], true);
    }

    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'current_password',
        ]);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'authorization',
        ]);
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', function (?User $user): bool {
            return $user !== null
                && $user->is_active
                && $user->is_super_admin;
        });
    }
}
