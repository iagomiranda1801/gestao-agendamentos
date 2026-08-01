<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\CompanySchedulingSetting;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompanyWhatsAppInstanceService
{
    public function __construct(
        protected EvolutionApiClient $client,
        protected CompanySchedulingSettingService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): CompanyWhatsAppInstance
    {
        return DB::transaction(function () use ($company, $data): CompanyWhatsAppInstance {
            $payload = $this->preparePayload($data);

            if (($payload['is_default'] ?? false) === true) {
                $this->clearDefault($company);
            }

            $instance = new CompanyWhatsAppInstance($payload);
            $instance->company()->associate($company);
            $instance->save();

            if ($instance->is_default) {
                $this->syncDefaultToSchedulingSettings($instance);
            }

            return $instance->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, CompanyWhatsAppInstance $instance, array $data): CompanyWhatsAppInstance
    {
        $this->ensureBelongsToCompany($company, $instance);

        return DB::transaction(function () use ($company, $instance, $data): CompanyWhatsAppInstance {
            $payload = $this->preparePayload($data, $instance);

            if (($payload['is_default'] ?? false) === true) {
                $this->clearDefault($company, $instance);
            }

            $instance->fill($payload);
            $instance->save();

            if ($instance->is_default) {
                $this->syncDefaultToSchedulingSettings($instance);
            }

            return $instance->refresh();
        });
    }

    public function createOrRefreshQrCode(Company $company, CompanyWhatsAppInstance $instance): CompanyWhatsAppInstance
    {
        $this->ensureBelongsToCompany($company, $instance);

        $instanceName = filled($instance->instance_name)
            ? (string) $instance->instance_name
            : $this->defaultInstanceName($company);
        $token = filled($instance->instance_token)
            ? (string) $instance->instance_token
            : Str::random(48);

        $existing = $this->findExistingInEvolution($instanceName, $instance->sender_phone);

        if ($existing !== null) {
            $existingName = $this->extractInstanceName($existing) ?: $instanceName;
            $response = $this->isConnected($existing)
                ? []
                : $this->client->connectInstance($existingName);

            $instance->fill([
                'instance_name' => $existingName,
                'status' => $this->extractState($response)
                    ?: $this->extractState($existing)
                    ?: 'qrcode',
                'connected_at' => $this->isConnected($existing)
                    ? ($instance->connected_at ?? now())
                    : $instance->connected_at,
                'qr_code' => $this->extractQrCode($response)
                    ?: $this->extractQrCode($existing)
                    ?: $instance->qr_code,
            ]);
        } elseif (blank($instance->status) || $instance->status === 'error') {
            $response = $this->client->createInstance($instanceName, $token, true);

            $instance->fill([
                'instance_name' => $instanceName,
                'instance_token' => $token,
                'status' => $this->extractState($response) ?: 'qrcode',
                'qr_code' => $this->extractQrCode($response),
            ]);
        } else {
            $response = $this->client->connectInstance($instanceName);

            $instance->fill([
                'instance_name' => $instanceName,
                'instance_token' => $token,
                'status' => $this->extractState($response) ?: 'qrcode',
                'qr_code' => $this->extractQrCode($response),
            ]);
        }

        $instance->save();

        if ($instance->is_default) {
            $this->syncDefaultToSchedulingSettings($instance);
        }

        return $instance->refresh();
    }

    public function refreshConnectionState(Company $company, CompanyWhatsAppInstance $instance): CompanyWhatsAppInstance
    {
        $this->ensureBelongsToCompany($company, $instance);
        $instanceName = $this->client->resolveInstance($instance->instance_name);

        $response = $this->client->connectionState($instanceName);
        $state = $this->extractState($response);

        $instance->fill([
            'status' => $state,
            'connected_at' => in_array($state, ['open', 'connected'], true) ? now() : $instance->connected_at,
        ]);
        $instance->save();

        if ($instance->is_default) {
            $this->syncDefaultToSchedulingSettings($instance);
        }

        return $instance->refresh();
    }

    public function delete(Company $company, CompanyWhatsAppInstance $instance): void
    {
        $this->ensureBelongsToCompany($company, $instance);

        $instanceName = trim((string) $instance->instance_name);

        if ($instanceName !== '') {
            try {
                $this->client->deleteInstance($instanceName);
            } catch (RequestException $exception) {
                if ($exception->response->status() !== 404) {
                    throw $exception;
                }
            }
        }

        DB::transaction(function () use ($company, $instance, $instanceName): void {
            $wasDefault = $instance->is_default;
            $instance->delete();

            $setting = $this->settings->getOrCreate($company);
            $pointsToDeletedInstance = $instanceName !== ''
                && $setting->whatsapp_instance === $instanceName;

            if (! $wasDefault && ! $pointsToDeletedInstance) {
                return;
            }

            $replacement = CompanyWhatsAppInstance::query()
                ->where('company_id', $company->getKey())
                ->latest('updated_at')
                ->first();

            if ($replacement) {
                $this->clearDefault($company);
                $replacement->update(['is_default' => true]);
                $this->syncDefaultToSchedulingSettings($replacement);

                return;
            }

            $setting->fill([
                'whatsapp_notifications_enabled' => false,
                'whatsapp_instance' => null,
                'whatsapp_instance_token' => null,
                'whatsapp_instance_status' => null,
                'whatsapp_instance_qr_code' => null,
                'whatsapp_instance_connected_at' => null,
                'whatsapp_sender_phone' => null,
            ]);
            $setting->save();
        });
    }

    public function defaultForCompany(Company $company): ?CompanyWhatsAppInstance
    {
        return CompanyWhatsAppInstance::query()
            ->where('company_id', $company->getKey())
            ->default()
            ->first();
    }

    protected function defaultInstanceName(Company $company): string
    {
        return Str::of("company-{$company->getKey()}-{$company->slug}")
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9_-]+/', '-')
            ->trim('-')
            ->limit(80, '')
            ->toString();
    }

    protected function ensureBelongsToCompany(Company $company, CompanyWhatsAppInstance $instance): void
    {
        if ((int) $instance->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data, ?CompanyWhatsAppInstance $instance = null): array
    {
        unset($data['company_id']);

        $data['name'] = filled($data['name'] ?? null) ? trim((string) $data['name']) : 'WhatsApp principal';
        $data['instance_name'] = filled($data['instance_name'] ?? null)
            ? trim((string) $data['instance_name'])
            : ($instance?->instance_name ?? '');
        $data['sender_phone'] = PhoneNormalizer::normalize(
            filled($data['sender_phone'] ?? null) ? (string) $data['sender_phone'] : null,
        );
        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        if (blank($data['instance_name'])) {
            $data['instance_name'] = Str::slug($data['name']);
        }

        return $data;
    }

    protected function clearDefault(Company $company, ?CompanyWhatsAppInstance $except = null): void
    {
        CompanyWhatsAppInstance::query()
            ->where('company_id', $company->getKey())
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->update(['is_default' => false]);
    }

    protected function syncDefaultToSchedulingSettings(CompanyWhatsAppInstance $instance): CompanySchedulingSetting
    {
        $setting = $this->settings->getOrCreate($instance->company);

        $setting->fill([
            'whatsapp_instance' => $instance->instance_name,
            'whatsapp_instance_token' => $instance->instance_token,
            'whatsapp_instance_status' => $instance->status,
            'whatsapp_instance_qr_code' => $instance->qr_code,
            'whatsapp_instance_connected_at' => $instance->connected_at,
            'whatsapp_sender_phone' => $instance->sender_phone ?: $setting->whatsapp_sender_phone,
        ]);
        $setting->save();

        return $setting->refresh();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function findExistingInEvolution(string $instanceName, ?string $senderPhone): ?array
    {
        foreach ($this->client->fetchInstances() as $existing) {
            $existingName = $this->extractInstanceName($existing);

            if ($existingName === $instanceName || $this->matchesPhone($existing, $senderPhone)) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    protected function extractInstanceName(array $instance): ?string
    {
        $name = Arr::get($instance, 'name')
            ?: Arr::get($instance, 'instanceName')
            ?: Arr::get($instance, 'instance.instanceName')
            ?: Arr::get($instance, 'instance.name');

        return filled($name) ? (string) $name : null;
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    protected function matchesPhone(array $instance, ?string $senderPhone): bool
    {
        $phone = $this->phoneCandidates((string) $senderPhone);

        if ($phone === []) {
            return false;
        }

        $owner = (string) (
            Arr::get($instance, 'owner')
            ?: Arr::get($instance, 'ownerJid')
            ?: Arr::get($instance, 'number')
            ?: Arr::get($instance, 'instance.owner')
        );
        $ownerCandidates = $this->phoneCandidates($owner);

        return count(array_intersect($phone, $ownerCandidates)) > 0;
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

        $candidates = [$digits];

        // Evolution/WhatsApp can expose Brazilian mobile numbers with or
        // without the extra ninth digit after the area code.
        if (strlen($digits) === 11 && $digits[2] === '9') {
            $candidates[] = substr($digits, 0, 2).substr($digits, 3);
        } elseif (strlen($digits) === 10) {
            $candidates[] = substr($digits, 0, 2).'9'.substr($digits, 2);
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    protected function isConnected(array $instance): bool
    {
        $state = Arr::get($instance, 'connectionStatus')
            ?: Arr::get($instance, 'state')
            ?: Arr::get($instance, 'instance.state');

        return in_array(strtolower((string) $state), ['open', 'connected'], true);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function extractQrCode(array $response): ?string
    {
        return Arr::get($response, 'qrcode.base64')
            ?: Arr::get($response, 'base64')
            ?: Arr::get($response, 'qrcode.code');
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function extractState(array $response): ?string
    {
        $state = Arr::get($response, 'instance.state')
            ?: Arr::get($response, 'connectionStatus')
            ?: Arr::get($response, 'state')
            ?: Arr::get($response, 'instance.connectionStatus')
            ?: Arr::get($response, 'instance.status')
            ?: Arr::get($response, 'status');

        return filled($state) ? (string) $state : null;
    }
}
