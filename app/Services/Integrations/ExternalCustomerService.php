<?php

namespace App\Services\Integrations;

use App\Models\Client;
use App\Models\Company;
use App\Models\ExternalIntegration;
use App\Services\Client\ClientService;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ExternalCustomerService
{
    public function __construct(private readonly ClientService $clients) {}

    /** @param array<string, mixed> $data
     * @return array{0: Client, 1: bool}
     */
    public function register(ExternalIntegration $integration, array $data): array
    {
        return DB::transaction(function () use ($integration, $data): array {
            // Serialize API registrations within a company, including those without CPF.
            $company = Company::query()->whereKey($integration->company_id)->lockForUpdate()->firstOrFail();
            $phone = PhoneNormalizer::normalize($data['phone']);
            $document = $data['cpf'] ?? null;
            $email = $data['email'] ?? null;
            $base = Client::query()->where('company_id', $company->getKey());

            $byDocument = $document ? (clone $base)->where('document', $document)->get() : collect();
            $byPhone = (clone $base)->where('phone_normalized', $phone)->get();
            $byEmail = $email ? (clone $base)->whereRaw('LOWER(email) = ?', [$email])->get() : collect();
            $matches = $byDocument->concat($byPhone)->concat($byEmail)->unique('id');

            if ($byDocument->count() > 1 || $matches->count() > 1) {
                throw new ConflictHttpException('Identificadores correspondem a clientes diferentes.');
            }

            $client = $matches->first();

            if ($client !== null) {
                if (! $client->is_active
                    || ($document && filled($client->document) && $client->document !== $document)
                    || ($document && blank($client->document) && self::nameKey($client->name) !== self::nameKey($data['name']))
                    || (! $document && self::nameKey($client->name) !== self::nameKey($data['name']))) {
                    throw new ConflictHttpException('Identificadores conflitantes para este cliente.');
                }

                $created = false;
            } else {
                $client = $this->clients->create($company, [
                    'name' => $data['name'],
                    'phone' => $phone,
                    'email' => $email,
                    'document' => $document,
                    'source' => $data['source'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'is_active' => true,
                    'whatsapp_marketing_opt_in' => false,
                    'whatsapp_confirmation_opt_in' => false,
                ]);
                $created = true;
            }

            DB::table('external_customer_submissions')->insert([
                'company_id' => $company->getKey(),
                'client_id' => $client->getKey(),
                'external_integration_id' => $integration->getKey(),
                'source' => $data['source'] ?? 'external',
                'notes' => $data['notes'] ?? null,
                'metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$client, $created];
        }, 3);
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }
}
