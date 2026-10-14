<?php

namespace Tests\Feature\Integrations;

use App\Enums\CompanyRole;
use App\Filament\App\Pages\ExternalIntegrationsPage;
use App\Models\Client;
use App\Models\Company;
use App\Models\ExternalIntegration;
use App\Services\Integrations\IntegrationKeyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ExternalCustomerIntegrationTest extends TestCase
{
    private Company $company;

    private ExternalIntegration $integration;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany(['name' => 'Vitis Blend']);
        [$this->integration, $this->key] = app(IntegrationKeyService::class)->create($this->company, 'GRAN.Wine Landing Page');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'João da Silva',
            'phone' => '(34) 99999-9999',
            'cpf' => '123.456.789-09',
            'email' => 'JOAO@example.com',
            'source' => 'gran_wine',
            'notes' => 'Solicitação de admissão',
            'metadata' => ['admission_status' => 'pending', 'club' => 'GRAN.Wine'],
        ], $overrides);
    }

    private function postCustomer(array $payload = [], ?string $key = null)
    {
        return $this->withToken($key ?? $this->key)
            ->postJson('/api/v1/integrations/customers', $this->payload($payload));
    }

    public function test_creates_customer_in_key_company_and_keeps_admission_separate(): void
    {
        $other = $this->createCompany();
        $this->postCustomer(['company_id' => $other->id])->assertStatus(422);
        $this->assertDatabaseCount('clients', 0);

        $response = $this->postCustomer()->assertCreated()->assertJsonPath('data.created', true);
        $client = Client::findOrFail($response->json('data.id'));
        $this->assertSame($this->company->id, $client->company_id);
        $this->assertSame('12345678909', $client->document);
        $this->assertSame('joao@example.com', $client->email);
        $this->assertFalse($client->whatsapp_marketing_opt_in);
        $this->assertFalse($client->whatsapp_confirmation_opt_in);
        $this->assertSame('pending', json_decode(DB::table('external_customer_submissions')->first()->metadata, true)['admission_status']);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertNotNull($this->integration->fresh()->last_used_at);
    }

    public function test_repeated_request_returns_existing_customer_without_overwriting_it(): void
    {
        $first = $this->postCustomer()->assertCreated()->json('data.id');
        $this->postCustomer(['notes' => 'Outro pedido'])->assertOk()->assertJsonPath('data.created', false);
        $this->assertDatabaseCount('clients', 1);
        $this->assertSame('Solicitação de admissão', Client::findOrFail($first)->notes);
        $this->assertDatabaseCount('external_customer_submissions', 2);
    }

    public function test_rejects_missing_revoked_inactive_and_unscoped_keys(): void
    {
        $this->postJson('/api/v1/integrations/customers', $this->payload())->assertUnauthorized();
        $this->postCustomer([], 'aqi_'.str_repeat('a', 64))->assertUnauthorized();
        $this->integration->update(['revoked_at' => now()]);
        $this->postCustomer()->assertUnauthorized();
        $this->integration->update(['revoked_at' => null, 'is_active' => false]);
        $this->postCustomer()->assertForbidden();
        $this->integration->update(['is_active' => true, 'scopes' => []]);
        $this->postCustomer()->assertForbidden();
    }

    public function test_validates_required_fields_cpf_email_and_metadata(): void
    {
        $this->withToken($this->key)->postJson('/api/v1/integrations/customers', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'phone']);
        $this->postCustomer(['cpf' => '11111111111'])->assertStatus(422)->assertJsonValidationErrors('cpf');
        $this->postCustomer(['email' => 'invalid'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postCustomer(['metadata' => ['unexpected' => 'value']])->assertStatus(422)->assertJsonValidationErrors('metadata');
    }

    public function test_conflicting_identifiers_are_rejected(): void
    {
        Client::factory()->forCompany($this->company)->create(['name' => 'João da Silva', 'phone' => '34999999999', 'document' => '98765432100', 'email' => 'other@example.com']);
        $this->postCustomer()->assertStatus(409);

        Client::factory()->forCompany($this->company)->create(['name' => 'Outra Pessoa', 'phone' => '34888888888', 'document' => '12345678909', 'email' => 'different@example.com']);
        $this->postCustomer()->assertStatus(409);
        $this->assertDatabaseCount('clients', 2);
    }

    public function test_key_is_company_scoped_even_when_another_company_has_same_identifiers(): void
    {
        $other = $this->createCompany();
        Client::factory()->forCompany($other)->create(['phone' => '34999999999', 'document' => '12345678909']);
        $this->postCustomer()->assertCreated();
        $this->assertDatabaseCount('clients', 2);
    }

    public function test_without_cpf_uses_phone_or_email_only_for_same_named_customer(): void
    {
        $client = Client::factory()->forCompany($this->company)->create([
            'name' => 'João da Silva',
            'phone' => '34999999999',
            'document' => null,
            'email' => 'joao@example.com',
        ]);

        $this->postCustomer(['cpf' => null])->assertOk()->assertJsonPath('data.id', $client->id);
        $this->postCustomer(['cpf' => null, 'name' => 'Outra Pessoa'])->assertStatus(409);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_key_regeneration_revokes_old_secret(): void
    {
        $old = $this->key;
        $new = app(IntegrationKeyService::class)->regenerate($this->integration);
        $this->assertNotSame($old, $new);
        $this->assertSame(hash('sha256', $new), $this->integration->fresh()->key_hash);
        $this->assertStringNotContainsString($new, json_encode($this->integration->fresh()));
        $this->postCustomer([], $old)->assertUnauthorized();
        $this->postCustomer([], $new)->assertCreated();
    }

    public function test_rate_limit_is_enforced(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postCustomer(['cpf' => '11111111111'])->assertStatus(422);
        }
        $this->postCustomer()->assertStatus(429)->assertJsonPath('success', false);
    }

    public function test_only_company_admin_can_manage_own_integration(): void
    {
        $manager = $this->createCompanyUser($this->company, [], CompanyRole::Manager);
        $this->authenticateForAppTenant($manager, $this->company);
        $this->assertFalse(ExternalIntegrationsPage::canAccess());

        $admin = $this->createCompanyUser($this->company);
        $this->authenticateForAppTenant($admin, $this->company);
        Livewire::test(ExternalIntegrationsPage::class)
            ->assertSuccessful()
            ->set('name', 'Outra integração')
            ->call('createIntegration')
            ->assertSet('name', '');
        $this->assertSame(2, $this->company->externalIntegrations()->count());

        $other = $this->createCompany();
        [$foreign] = app(IntegrationKeyService::class)->create($other, 'Privada');
        try {
            Livewire::test(ExternalIntegrationsPage::class)->call('revoke', $foreign->id);
            $this->fail('A credencial de outra empresa não pode ser administrada.');
        } catch (ModelNotFoundException) {
            // Tenant-scoped lookup rejects the foreign credential.
        }
        $this->assertNull($foreign->fresh()->revoked_at);
    }
}
