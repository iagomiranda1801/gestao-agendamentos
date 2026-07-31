<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\WhatsAppContact;
use Illuminate\Database\Eloquent\Builder;

class WhatsAppContactCleanupService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function deleteByFilters(Company $company, array $filters): int
    {
        $query = WhatsAppContact::query()
            ->where('company_id', $company->getKey());

        $this->applyFilters($query, $filters);

        return $query->delete();
    }

    /**
     * @param  Builder<WhatsAppContact>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        $instanceId = (int) ($filters['instance_id'] ?? 0);

        if ($instanceId > 0) {
            $query->where('company_whatsapp_instance_id', $instanceId);
        }

        $importStatus = (string) ($filters['import_status'] ?? 'all');

        if ($importStatus === 'imported') {
            $query->whereNotNull('imported_as_client_at');
        }

        if ($importStatus === 'not_imported') {
            $query->whereNull('imported_as_client_at');
        }

        $syncedBefore = $filters['synced_before'] ?? null;

        if (filled($syncedBefore)) {
            $query->whereDate('last_synced_at', '<=', $syncedBefore);
        }

        return $query;
    }
}
