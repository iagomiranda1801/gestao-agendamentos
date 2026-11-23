<?php

namespace Tests\Feature\Filament;

use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Filament\App\Pages\SchedulingSettingsPage;
use App\Services\AI\CompanyAIService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class AIModelSelectionTest extends TestCase
{
    use CreatesSchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    private function configuredCompany()
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $this->seedStandardBusinessHours($company);
        app(CompanySchedulingSettingService::class)->getOrCreate($company)->update([
            'ai_provider' => 'openai', 'ai_model' => 'old-model', 'ai_api_key' => 'saved-secret',
        ]);
        $this->authenticateForAppTenant($this->createCompanyUser($company), $company);

        return $company;
    }

    public function test_rendering_does_not_fetch_models_or_expose_saved_key_and_opening_fetches_catalog(): void
    {
        $this->configuredCompany();
        Http::fake(fn () => Http::response(['data' => [['id' => 'gpt-4.1-mini']]]));
        $component = Livewire::test(SchedulingSettingsPage::class)
            ->assertFormSet(['ai_api_key' => '', 'ai_model' => 'old-model'])
            ->assertDontSee('saved-secret');
        Http::assertNothingSent();

        $field = $component->instance()->form->getFlatFields()['ai_model'];
        $options = $field->getOptionsForJs();
        $this->assertContains('gpt-4.1-mini', array_column($options, 'value'));
        $this->assertContains('old-model', array_column($options, 'value'));
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer saved-secret'));
    }

    public function test_new_key_search_refresh_and_provider_change_do_not_save_settings(): void
    {
        $company = $this->configuredCompany();
        Http::fake(fn () => Http::response(['data' => [['id' => 'gpt-4.1-mini'], ['id' => 'o3']]]));
        $component = Livewire::test(SchedulingSettingsPage::class)->set('data.ai_api_key', 'new-secret');
        $options = $component->instance()->aiModelOptions('mini');
        $this->assertSame(['gpt-4.1-mini'], array_keys($options));
        $component->call('aiModelOptions', '', true)->assertNotified('Lista de modelos atualizada');
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer new-secret'));
        $this->assertSame('saved-secret', $company->schedulingSetting->ai_api_key);
        $this->assertSame('old-model', $company->schedulingSetting->ai_model);

        $component->set('data.ai_api_key', '')->set('data.ai_provider', 'gemini')
            ->assertSet('data.ai_model', null)->assertSet('data.ai_model_identifier', null)
            ->call('aiModelOptions')->assertNotified('Não foi possível carregar os modelos');
        Http::assertSentCount(2);
    }

    public function test_selected_model_is_saved_and_used_for_generation(): void
    {
        $company = $this->configuredCompany();
        Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => '{"reply":"ok"}']]]]));
        Livewire::test(SchedulingSettingsPage::class)->fillForm(['ai_model' => 'gpt-4.1-mini'])
            ->call('save')->assertHasNoFormErrors()->assertNotified('Configurações salvas');
        $this->assertSame('gpt-4.1-mini', $company->schedulingSetting()->first()->ai_model);
        app(CompanyAIService::class)->structured('Instrução', 'Entrada', context: ['company_id' => $company->id]);
        Http::assertSent(fn (Request $request) => $request['model'] === 'gpt-4.1-mini'
            && $request->hasHeader('Authorization', 'Bearer saved-secret'));
    }

    public function test_manual_model_can_be_saved_without_successful_catalog_lookup(): void
    {
        $company = $this->configuredCompany();
        Http::fake(fn () => Http::response(['error' => 'secret-provider-response'], 500));
        Livewire::test(SchedulingSettingsPage::class)
            ->call('aiModelOptions')->assertNotified('Não foi possível carregar os modelos')
            ->set('data.ai_model_manual', true)->set('data.ai_model_identifier', 'custom/new-model:free')
            ->call('save')->assertHasNoFormErrors()->assertNotified('Configurações salvas');
        $this->assertSame('custom/new-model:free', $company->schedulingSetting()->first()->ai_model);
        $this->assertSame('saved-secret', $company->schedulingSetting->ai_api_key);
        Http::assertSentCount(1);
    }

    public function test_empty_catalog_preserves_old_model_and_warns(): void
    {
        $company = $this->configuredCompany();
        Http::fake(fn () => Http::response(['data' => []]));
        $component = Livewire::test(SchedulingSettingsPage::class)
            ->call('aiModelOptions')->assertNotified('Não foi possível carregar os modelos')
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('old-model', $company->schedulingSetting->ai_model);
    }

    public function test_removing_key_prevents_model_lookup(): void
    {
        $this->configuredCompany();
        Http::fake();
        Livewire::test(SchedulingSettingsPage::class)->set('data.ai_clear_key', true)
            ->call('aiModelOptions')->assertNotified('Não foi possível carregar os modelos');
        Http::assertNothingSent();
    }

    public function test_employee_cannot_call_catalog_method(): void
    {
        $company = $this->configuredCompany();
        $component = Livewire::test(SchedulingSettingsPage::class);
        $employee = $this->createCompanyUser($company, [], CompanyRole::Employee);
        $this->actingAs($employee);
        Http::fake();
        $component->call('aiModelOptions')->assertForbidden();
        Http::assertNothingSent();
    }
}
