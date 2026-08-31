<?php

namespace App\Services\WhatsApp\Automations;

use App\Enums\AppointmentStatus;
use App\Models\Client;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class InactiveClientQuery
{
    /**
     * @return Builder<Client>
     */
    public function optedInInactive(Company $company, int $days): Builder
    {
        return $this->base($company, $days)
            ->whatsappMarketingOptedIn();
    }

    /**
     * @return Builder<Client>
     */
    public function base(Company $company, int $days): Builder
    {
        $cutoff = Carbon::now()->subDays(max(1, $days));

        return Client::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->whereNotNull('phone_normalized')
            ->where('phone_normalized', '!=', '')
            ->whereHas('attendances')
            ->whereDoesntHave('attendances', function (Builder $query) use ($cutoff): void {
                $query->where('completed_at', '>', $cutoff);
            })
            ->whereDoesntHave('appointments', function (Builder $query): void {
                $query->whereIn('status', [
                    AppointmentStatus::Pending->value,
                    AppointmentStatus::Confirmed->value,
                    AppointmentStatus::InProgress->value,
                ])->where('start_at', '>', now());
            });
    }
}
