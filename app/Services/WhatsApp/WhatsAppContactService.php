<?php

namespace App\Services\WhatsApp;

use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\WhatsAppContact;
use App\Services\Client\ClientService;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WhatsAppContactService
{
    public function __construct(
        protected EvolutionApiClient $client,
        protected ClientService $clientService,
    ) {}

    public function sync(Company $company, CompanyWhatsAppInstance $instance): int
    {
        $this->ensureBelongsToCompany($company, $instance);

        if (! in_array($instance->status, ['open', 'connected'], true)) {
            throw ValidationException::withMessages([
                'instance' => 'Conecte a instância antes de sincronizar os contatos.',
            ]);
        }

        $count = 0;
        $skip = 0;
        $take = 200;
        $instanceName = (string) $instance->instance_name;

        WhatsAppContact::query()
            ->where('company_whatsapp_instance_id', $instance->getKey())
            ->whereNull('imported_as_client_at')
            ->whereRaw('LENGTH(phone_normalized) < 10')
            ->delete();

        do {
            $contacts = $this->client->findContacts($instanceName, $take, $skip);

            foreach ($contacts as $payload) {
                $phone = $this->contactPhone($payload);

                if (blank($phone)) {
                    continue;
                }

                $contact = WhatsAppContact::query()->firstOrNew([
                    'company_whatsapp_instance_id' => $instance->getKey(),
                    'phone_normalized' => $phone,
                ]);
                $contact->company()->associate($company);
                $contact->fill([
                    'external_id' => filled($payload['id'] ?? null) ? (string) $payload['id'] : null,
                    'name' => $this->contactName($payload, $phone),
                    'phone' => $phone,
                    'profile_picture_url' => $payload['profilePictureUrl'] ?? $payload['profilePicUrl'] ?? null,
                    'last_synced_at' => now(),
                ]);

                $existingClient = $this->findClient($company, $phone);

                if ($existingClient !== null) {
                    $contact->client()->associate($existingClient);
                }

                $contact->save();
                $count++;
            }

            $received = count($contacts);
            $skip += $received;
        } while ($received === $take);

        return $count;
    }

    /**
     * @param  Collection<int, WhatsAppContact>  $contacts
     * @return array{created: int, linked: int, skipped: int}
     */
    public function importAsClients(Company $company, Collection $contacts, bool $grantMarketingConsent = false): array
    {
        $created = 0;
        $linked = 0;
        $skipped = 0;

        DB::transaction(function () use ($company, $contacts, $grantMarketingConsent, &$created, &$linked, &$skipped): void {
            foreach ($contacts as $contact) {
                if ((int) $contact->company_id !== (int) $company->getKey()) {
                    abort(404);
                }

                $client = $contact->client ?: $this->findClient($company, $contact->phone_normalized);

                if ($client === null) {
                    $client = $this->clientService->create($company, [
                        'name' => $contact->name ?: "Contato {$contact->phone_normalized}",
                        'phone' => $contact->phone_normalized,
                        'is_active' => true,
                        'whatsapp_marketing_opt_in' => false,
                        'source' => 'whatsapp',
                        'source_imported_at' => now(),
                    ]);
                    $created++;
                } else {
                    if ($company->isDentalClinic()) {
                        $this->clientService->ensureDentalProfile($company, $client);
                    }
                    $linked++;
                }

                if ($grantMarketingConsent && ! $client->whatsapp_marketing_opt_in) {
                    $client->forceFill(['whatsapp_marketing_opt_in' => true])->save();
                }

                $contact->client()->associate($client);
                $contact->imported_as_client_at = $contact->imported_as_client_at ?? now();
                $contact->save();
            }
        });

        return compact('created', 'linked', 'skipped');
    }

    protected function ensureBelongsToCompany(Company $company, CompanyWhatsAppInstance $instance): void
    {
        if ((int) $instance->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function contactName(array $payload, string $phone): string
    {
        // O campo "name" é o nome salvo na agenda do número conectado. Já o
        // "pushName" é o nome público informado pelo próprio contato no WhatsApp.
        return Str::squish((string) ($payload['name'] ?? $payload['pushName'] ?? '')) ?: $phone;
    }

    /**
     * Evolution versions may return the phone as number or inside
     * remoteJid; internal contact ids must never be used as phones.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function contactPhone(array $payload): ?string
    {
        if (($payload['isGroup'] ?? false) === true) {
            return null;
        }

        $remoteJid = (string) ($payload['remoteJid'] ?? '');

        if ($remoteJid !== '' && ! str_ends_with($remoteJid, '@s.whatsapp.net')) {
            return null;
        }

        $rawPhone = $payload['number'] ?? ($remoteJid !== '' ? Str::before($remoteJid, '@') : null);
        $phone = PhoneNormalizer::normalize(filled($rawPhone) ? (string) $rawPhone : null);

        return $phone !== null && strlen($phone) >= 10 ? $phone : null;
    }

    protected function findClient(Company $company, string $phone): ?Client
    {
        foreach ($this->phoneCandidates($phone) as $candidate) {
            $client = Client::query()
                ->where('company_id', $company->getKey())
                ->where('phone_normalized', $candidate)
                ->first();

            if ($client !== null) {
                return $client;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function phoneCandidates(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        $candidates = [$digits, '55'.$digits];

        if (strlen($digits) === 11 && $digits[2] === '9') {
            $withoutNinth = substr($digits, 0, 2).substr($digits, 3);
            $candidates[] = $withoutNinth;
            $candidates[] = '55'.$withoutNinth;
        } elseif (strlen($digits) === 10) {
            $withNinth = substr($digits, 0, 2).'9'.substr($digits, 2);
            $candidates[] = $withNinth;
            $candidates[] = '55'.$withNinth;
        }

        return array_values(array_unique(array_filter($candidates)));
    }
}
