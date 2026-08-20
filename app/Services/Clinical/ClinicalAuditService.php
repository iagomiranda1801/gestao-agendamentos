<?php

namespace App\Services\Clinical;

use App\Models\Client;
use App\Models\ClinicalAuditEvent;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ClinicalAuditService
{
    /** @param array<string, mixed> $metadata */
    public function record(
        Company $company,
        ?Client $client,
        ?User $user,
        string $action,
        Model $entity,
        array $metadata = [],
    ): ClinicalAuditEvent {
        $event = new ClinicalAuditEvent([
            'action' => $action,
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => $entity->getKey(),
            'ip_address' => app()->bound('request') ? request()->ip() : null,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
        $event->company_id = $company->getKey();
        $event->client_id = $client?->getKey();
        $event->user_id = $user?->getKey();
        $event->save();

        return $event;
    }
}
