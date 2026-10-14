<?php

namespace App\Services\Integrations;

use App\Models\Company;
use App\Models\ExternalIntegration;
use Illuminate\Support\Facades\DB;

class IntegrationKeyService
{
    /** @return array{0: ExternalIntegration, 1: string} */
    public function create(Company $company, string $name): array
    {
        $key = 'aqi_'.bin2hex(random_bytes(32));

        $integration = $company->externalIntegrations()->create([
            'name' => $name,
            'key_prefix' => substr($key, 0, 12),
            'key_hash' => hash('sha256', $key),
            'scopes' => ['customers:create'],
            'is_active' => true,
        ]);

        return [$integration, $key];
    }

    public function regenerate(ExternalIntegration $integration): string
    {
        $key = 'aqi_'.bin2hex(random_bytes(32));
        DB::transaction(function () use ($integration, $key): void {
            $integration->update([
                'key_prefix' => substr($key, 0, 12),
                'key_hash' => hash('sha256', $key),
                'revoked_at' => null,
                'last_used_at' => null,
                'is_active' => true,
            ]);
        });

        return $key;
    }
}
