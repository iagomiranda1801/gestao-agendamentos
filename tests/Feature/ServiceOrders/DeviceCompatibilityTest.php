<?php

namespace Tests\Feature\ServiceOrders;

use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Filament\App\Pages\DeviceCompatibilityPage;
use App\Services\ServiceOrders\DeviceCompatibilityService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DeviceCompatibilityTest extends TestCase
{
    private function payload(): array
    {
        return ['success' => true, 'query' => 'samsung a12', 'total' => 1, 'data' => [[
            'modelo' => 'Samsung A12', 'marca' => 'Samsung',
            'compativeis' => ['A13 5G', 'A32 5G', 'M12', 'a13 5g', ' Samsung A12 ', 'S', ''],
            'pagina_seo' => 'https://tabelapeliculas.com.br/pelicula-samsung-a12',
            'links_afiliados' => ['capa' => 'https://example.test/capa', 'tela' => 'https://example.test/tela'],
        ]]];
    }

    private function authenticateTechnician(): array
    {
        $company = $this->createCompany([
            'business_profile' => CompanyProfile::TechnicalAssistance,
            'enabled_modules' => array_map(fn ($module) => $module->value, CompanyProfile::TechnicalAssistance->defaultModules()),
        ]);
        $user = $this->createCompanyUser($company, [], CompanyRole::Employee);
        $this->authenticateForAppTenant($user, $company);

        return [$company, $user];
    }

    public function test_first_search_persists_public_data_and_normalized_repeat_uses_database(): void
    {
        Http::preventStrayRequests();
        Http::fake(['tabelapeliculas.com.br/*' => Http::response($this->payload())]);
        $service = app(DeviceCompatibilityService::class);
        $first = $service->search('film', 'Samsung A12');
        $again = $service->search('film', '  SAMSUNG   A12  ');

        $this->assertSame($first, $again);
        $this->assertSame(['A13 5G', 'A32 5G', 'M12'], $first[0]['compatible_models']);
        $this->assertArrayNotHasKey('links_afiliados', $first[0]);
        $this->assertDatabaseCount('device_compatibility_lookups', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['route'] === 'api/sistemas' && $request['modelo'] === 'Samsung A12');

        $this->travel(60)->days();
        $this->assertSame($first, $service->search('film', 'Samsung A12'));
        Http::assertSentCount(1);
    }

    public function test_case_and_screen_searches_never_reuse_film_compatibility_or_call_provider(): void
    {
        Http::fake(['*' => Http::response($this->payload())]);
        $service = app(DeviceCompatibilityService::class);
        $service->search('film', 'Samsung A12');
        $this->assertSame([], $service->search('case', 'Samsung A12'));
        $this->assertSame([], $service->search('screen', 'Samsung A12'));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('device_compatibility_lookups', 1);
    }

    public function test_empty_results_are_cached_then_checked_again_after_one_day(): void
    {
        Http::fakeSequence()->push(['success' => true, 'data' => []])->push($this->payload());
        $service = app(DeviceCompatibilityService::class);
        $this->assertSame([], $service->search('film', 'Samsung A12'));
        $this->assertSame([], $service->search('film', 'Samsung A12'));
        Http::assertSentCount(1);
        $this->travel(25)->hours();
        $this->assertNotEmpty($service->search('film', 'Samsung A12'));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('device_compatibility_lookups', 1);
    }

    public function test_api_error_is_not_saved_as_an_empty_success(): void
    {
        Http::fakeSequence()->push(['success' => false, 'data' => []])->push($this->payload());
        $service = app(DeviceCompatibilityService::class);
        try {
            $service->search('film', 'Samsung A12');
            $this->fail('Provider error should not be imported.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('device_compatibility_lookups', 0);
        }
        $this->assertNotEmpty($service->search('film', 'Samsung A12'));
        Http::assertSentCount(2);
    }

    public function test_malformed_model_does_not_persist_a_partial_response(): void
    {
        $payload = $this->payload();
        $payload['data'][] = ['modelo' => 'Bad model', 'marca' => 'Brand', 'compativeis' => 'invalid'];
        Http::fake(['*' => Http::response($payload)]);
        try {
            app(DeviceCompatibilityService::class)->search('film', 'Samsung A12');
            $this->fail('Invalid data should not be imported.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('device_compatibility_lookups', 0);
        }
    }

    public function test_explicit_variants_and_direction_are_preserved_without_transitive_inference(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            ['modelo' => 'Model A 4G', 'marca' => 'Brand', 'compativeis' => ['Model B 5G']],
            ['modelo' => 'Model B 5G', 'marca' => 'Brand', 'compativeis' => ['Model C']],
        ]])]);
        $results = app(DeviceCompatibilityService::class)->search('film', 'Model');
        $this->assertSame(['Model B 5G'], $results[0]['compatible_models']);
        $this->assertSame(['Model C'], $results[1]['compatible_models']);
    }

    public function test_technician_can_search_switch_tabs_and_read_persisted_result_without_api(): void
    {
        $this->authenticateTechnician();
        Http::fake(['*' => Http::response($this->payload())]);
        Livewire::test(DeviceCompatibilityPage::class)
            ->assertSuccessful()->assertSee('Películas')->assertSee('Capas')->assertSee('Telas')
            ->set('query', 'Samsung A12')->call('search')->assertHasNoErrors()
            ->assertSee('A32 5G')->assertDontSee('links_afiliados')->assertDontSee('fetched_at')
            ->call('selectCategory', 'case')->assertSet('results', [])->assertDontSee('A32 5G')
            ->call('search')->assertSee('Compatibilidade ainda não cadastrada')
            ->call('selectCategory', 'screen')->call('search')->assertSee('Compatibilidade ainda não cadastrada')
            ->call('selectCategory', 'film')->call('search')->assertSee('A32 5G');
        Http::assertSentCount(1);
    }

    public function test_query_validation_does_not_request_the_api(): void
    {
        $this->authenticateTechnician();
        Http::fake();
        Livewire::test(DeviceCompatibilityPage::class)->set('query', ' ')->call('search')->assertHasErrors(['query']);
        Livewire::test(DeviceCompatibilityPage::class)->set('query', 'a')->call('search')->assertHasErrors(['query']);
        Http::assertNothingSent();
    }

    public function test_outage_shows_retry_message_without_importing_or_retaining_previous_results(): void
    {
        $this->authenticateTechnician();
        Http::fakeSequence()->push($this->payload())->pushResponse(Http::failedConnection());
        Livewire::test(DeviceCompatibilityPage::class)->set('query', 'Samsung A12')->call('search')
            ->assertSee('A32 5G')->set('query', 'Moto G54')->call('search')
            ->assertSee('Não foi possível consultar agora')->assertDontSee('A32 5G')->assertSet('hasSearched', false);
        $this->assertDatabaseCount('device_compatibility_lookups', 1);
    }

    public function test_cached_result_remains_available_when_provider_is_offline(): void
    {
        $this->authenticateTechnician();
        Http::fake(['*' => Http::response($this->payload())]);
        app(DeviceCompatibilityService::class)->search('film', 'Samsung A12');
        Http::fake(fn () => throw new ConnectionException('offline'));
        Livewire::test(DeviceCompatibilityPage::class)->set('query', 'Samsung A12')->call('search')
            ->assertSee('A32 5G')->assertSet('searchError', null);
        Http::assertNothingSent();
    }

    public function test_permissions_and_revoked_membership_are_checked_on_every_action(): void
    {
        [$company, $user] = $this->authenticateTechnician();
        Http::fake();
        $page = Livewire::test(DeviceCompatibilityPage::class)->set('query', 'Samsung A12');
        $company->users()->updateExistingPivot($user->id, ['permissions' => []]);
        $page->call('search')->assertForbidden();
        $this->assertFalse(DeviceCompatibilityPage::canAccess());
        Http::assertNothingSent();
    }

    public function test_other_profiles_and_inactive_companies_cannot_access_compatibility(): void
    {
        [$company] = $this->authenticateTechnician();
        $company->update(['business_profile' => CompanyProfile::Salon]);
        $this->assertFalse(DeviceCompatibilityPage::canAccess());
        $company->update(['business_profile' => CompanyProfile::TechnicalAssistance, 'is_active' => false]);
        $this->assertFalse(DeviceCompatibilityPage::canAccess());
    }

    public function test_provider_markup_is_escaped_in_results(): void
    {
        $this->authenticateTechnician();
        $payload = $this->payload();
        $payload['data'][0]['compativeis'] = ['<script>alert(1)</script>'];
        Http::fake(['*' => Http::response($payload)]);
        Livewire::test(DeviceCompatibilityPage::class)->set('query', 'Samsung A12')->call('search')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
