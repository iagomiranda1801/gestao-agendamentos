<?php

namespace Tests\Feature\ServiceOrders;

use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Filament\App\Pages\AssistanceConversationsPage;
use App\Filament\App\Pages\AssistanceFlowPage;
use App\Jobs\HandleWhatsAppInboundMessageJob;
use App\Jobs\SendAssistanceBotReplyJob;
use App\Models\AssistanceBotConversation;
use App\Models\AssistanceBotFlow;
use App\Models\AssistanceBotMessage;
use App\Models\AssistanceBotRequest;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Services\ServiceOrders\Bot\AssistanceBotService;
use App\Services\ServiceOrders\Bot\AssistanceFlowDefinition;
use App\Services\ServiceOrders\Bot\AssistanceFlowService;
use App\Services\ServiceOrders\ServiceOrderService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AssistanceBotFlowTest extends TestCase
{
    private function fixture(bool $active = true): array
    {
        Queue::fake([SendAssistanceBotReplyJob::class]);
        $company = $this->createCompany([
            'business_profile' => CompanyProfile::TechnicalAssistance,
            'enabled_modules' => array_map(fn ($module) => $module->value, CompanyProfile::TechnicalAssistance->defaultModules()),
        ]);
        $user = $this->createCompanyUser($company);
        $instance = new CompanyWhatsAppInstance(['name' => 'Loja', 'instance_name' => 'assist-'.$company->id, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        $manager = app(AssistanceFlowService::class);
        $flow = $manager->get($company, $user);
        if ($active) {
            $flow = $manager->publish($company, $user, $flow->draft, $flow->revision);
            $manager->setEnabled($company, $user, true);
        }

        return [$company, $user, $instance, $flow->fresh()];
    }

    private function say(array $fixture, string $text, string $id, string $phone = '5511988887777'): string
    {
        [$company, , $instance] = $fixture;
        app(AssistanceBotService::class)->receive($company, $instance, $phone, $text, $id);

        return (string) AssistanceBotMessage::where('provider_message_key', hash('sha256', $company->id.':'.$instance->id.':'.$id))->value('reply');
    }

    public function test_default_flow_is_editable_but_disabled_and_simulator_has_no_side_effects(): void
    {
        [$company, $user] = $this->fixture(false);
        $this->authenticateForAppTenant($user, $company);
        Livewire::test(AssistanceFlowPage::class)->assertSuccessful()->assertSee('Caminhos do atendimento')
            ->set('definition.nodes.0.text', 'Olá, {nome}! Teste de boas-vindas.')
            ->call('saveDraft')->assertHasNoErrors()
            ->call('startSimulation')->assertHasNoErrors()->assertSee('Como você se chama?')
            ->set('simulatorInput', 'João')->call('sendSimulation')->assertSee('Teste de boas-vindas')
            ->set('simulatorInput', '1')->call('sendSimulation')
            ->set('simulatorInput', 'Samsung A12')->call('sendSimulation')
            ->set('simulatorInput', 'Tela quebrada')->call('sendSimulation')
            ->set('simulatorInput', '1')->call('sendSimulation')->assertSee('DEMO-001');
        $this->assertFalse(AssistanceBotFlow::first()->is_enabled);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('assistance_bot_requests', 0);
        $this->assertDatabaseCount('assistance_bot_conversations', 0);
        Queue::assertNothingPushed();
    }

    public function test_publish_versions_restore_draft_and_stale_edits_do_not_overwrite(): void
    {
        [$company, $user, , $flow] = $this->fixture();
        $manager = app(AssistanceFlowService::class);
        $v1 = $flow->versions()->first();
        $definition = $flow->draft;
        $definition['nodes'][0]['text'] = 'Bem-vindo à versão nova.';
        $v2Flow = $manager->publish($company, $user, $definition, $flow->revision);
        $this->assertSame(2, $v2Flow->versions()->count());
        $restored = $manager->restore($company, $user, $v1->id, $v2Flow->revision);
        $this->assertSame($v1->definition, $restored->draft);
        $this->assertSame($v2Flow->published_version_id, $restored->published_version_id);
        try {
            $manager->save($company, $user, $definition, $flow->revision);
            $this->fail('Must reject stale editor.');
        } catch (ValidationException) {
            $this->assertSame($v1->definition, $restored->fresh()->draft);
        }
        $this->expectException(ValidationException::class);
        $v1->update(['definition' => $definition]);
    }

    public function test_invalid_links_unreachable_blocks_and_automatic_cycles_cannot_publish(): void
    {
        $validator = app(AssistanceFlowDefinition::class);
        foreach (['missing', 'unreachable', 'cycle', 'unsafe_action'] as $case) {
            $definition = $validator->defaults();
            match ($case) {
                'missing' => $definition['nodes'][0]['next'] = 'does_not_exist',
                'unreachable' => $definition['nodes'][] = ['id' => 'unused', 'label' => 'Sem ligação', 'type' => 'end', 'text' => 'Fim'],
                'cycle' => $definition['nodes'][0]['next'] = 'welcome',
                'unsafe_action' => $definition['nodes'][5]['action'] = 'arbitrary_php',
            };
            try {
                $validator->validate($definition);
                $this->fail('Must reject '.$case);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_new_contact_name_number_and_repair_request_are_saved_once_without_opening_os(): void
    {
        $fixture = $this->fixture();
        $this->assertStringContainsString('Como você se chama', $this->say($fixture, 'oi', 'a1'));
        $this->assertDatabaseCount('clients', 0);
        $this->assertStringContainsString('apenas seu nome', $this->say($fixture, 'preciso trocar a tela', 'a2'));
        $this->assertStringContainsString('João', $this->say($fixture, 'João', 'a3'));
        $this->say($fixture, '1', 'a4');
        $this->say($fixture, 'Samsung A12', 'a5');
        $this->assertStringContainsString('Tela quebrada', $this->say($fixture, 'Tela quebrada', 'a6'));
        $this->assertDatabaseCount('assistance_bot_requests', 0);
        $this->assertStringContainsString('Protocolo AT-', $this->say($fixture, '1', 'a7'));
        $this->say($fixture, '1', 'a7');
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('assistance_bot_requests', 1);
        $this->assertDatabaseCount('service_orders', 0);
        $client = Client::first();
        $this->assertSame('5511988887777', $client->phone_normalized);
        $this->assertFalse((bool) $client->whatsapp_marketing_opt_in);
        $this->assertFalse((bool) $client->whatsapp_confirmation_opt_in);
        $order = app(AssistanceBotService::class)->receiveDevice($fixture[0], $fixture[1], AssistanceBotRequest::first()->id);
        $again = app(AssistanceBotService::class)->receiveDevice($fixture[0], $fixture[1], AssistanceBotRequest::first()->id);
        $this->assertSame($order->id, $again->id);
        $this->assertDatabaseCount('service_orders', 1);
    }

    public function test_known_customer_status_is_scoped_and_never_exposes_internal_notes(): void
    {
        $fixture = $this->fixture();
        [$company, $user] = $fixture;
        $client = Client::factory()->forCompany($company)->create(['name' => 'João', 'phone' => '11988887777']);
        app(ServiceOrderService::class)->receive($company, $user, ['client_id' => $client->id, 'device' => 'Samsung A12', 'problem' => 'Tela', 'notes' => 'SEGREDO INTERNO']);
        $other = Client::factory()->forCompany($company)->create(['phone' => '11977776666']);
        app(ServiceOrderService::class)->receive($company, $user, ['client_id' => $other->id, 'device' => 'OUTRO CLIENTE', 'problem' => 'Bateria']);
        $this->assertStringContainsString('Como podemos ajudar', $this->say($fixture, 'oi', 's1'));
        $reply = $this->say($fixture, '2', 's2');
        $this->assertStringContainsString('Samsung A12', $reply);
        $this->assertStringNotContainsString('SEGREDO INTERNO', $reply);
        $this->assertStringNotContainsString('OUTRO CLIENTE', $reply);
    }

    public function test_conversations_pin_published_version_and_new_contact_uses_latest(): void
    {
        $fixture = $this->fixture();
        $this->say($fixture, 'oi', 'v1');
        $oldVersion = AssistanceBotConversation::first()->assistance_bot_flow_version_id;
        $flow = $fixture[3];
        $definition = $flow->draft;
        $definition['nodes'][0]['text'] = 'NOVA VERSÃO';
        app(AssistanceFlowService::class)->publish($fixture[0], $fixture[1], $definition, $flow->revision);
        $this->assertStringNotContainsString('NOVA VERSÃO', $this->say($fixture, 'João', 'v2'));
        $this->assertSame($oldVersion, AssistanceBotConversation::first()->assistance_bot_flow_version_id);
        $this->say($fixture, 'oi', 'v3', '5511977776666');
        $this->assertStringContainsString('NOVA VERSÃO', $this->say($fixture, 'Maria', 'v4', '5511977776666'));
    }

    public function test_takeover_is_persistent_and_cancels_queued_messages_even_after_resume(): void
    {
        $fixture = $this->fixture();
        $this->say($fixture, 'oi', 'h1');
        $message = AssistanceBotMessage::first();
        $takeover = app(WhatsAppHumanTakeover::class);
        $takeover->pause($fixture[2]->instance_name, '11988887777');
        $this->assertTrue(AssistanceBotConversation::first()->human_takeover);
        $this->assertSame('cancelled', $message->fresh()->status);
        $this->travel(3)->hours();
        $this->assertFalse($takeover->isPaused($fixture[2]->instance_name, '11988887777'));
        $this->assertSame('', $this->say($fixture, 'João', 'h2'));
        app(AssistanceBotService::class)->setHuman($fixture[0], $fixture[1], AssistanceBotConversation::first()->id, false);
        $this->assertStringContainsString('João', $this->say($fixture, 'João', 'h3'));
        $client = $this->mock(EvolutionApiClient::class);
        $client->shouldNotReceive('sendText');
        (new SendAssistanceBotReplyJob($message->id))->handle($client, app(AssistanceBotService::class));
    }

    public function test_manual_takeover_before_first_incoming_message_stays_silent(): void
    {
        $fixture = $this->fixture();
        app(WhatsAppHumanTakeover::class)->pause($fixture[2]->instance_name, '5511988887777');
        $this->assertSame('', $this->say($fixture, 'oi', 'first'));
        $this->assertDatabaseCount('clients', 0);
        Queue::assertNotPushed(SendAssistanceBotReplyJob::class);
    }

    public function test_transport_failure_is_uncertain_and_not_retried_automatically(): void
    {
        $fixture = $this->fixture();
        $this->say($fixture, 'oi', 'transport');
        $message = AssistanceBotMessage::first();
        $client = $this->mock(EvolutionApiClient::class);
        $client->shouldReceive('sendText')->once()->andThrow(new \RuntimeException('timeout'));
        $job = new SendAssistanceBotReplyJob($message->id);
        $job->handle($client, app(AssistanceBotService::class));
        $job->handle($client, app(AssistanceBotService::class));
        $this->assertSame('uncertain', $message->fresh()->status);
    }

    public function test_accessory_records_interest_without_promising_stock_or_creating_sale(): void
    {
        $fixture = $this->fixture();
        $this->say($fixture, 'oi', 'p1');
        $this->say($fixture, 'Maria', 'p2');
        $this->say($fixture, '3', 'p3');
        $this->say($fixture, '1', 'p4');
        $reply = $this->say($fixture, 'Samsung A12', 'p5');
        $this->assertStringContainsString('não é uma reserva', $reply);
        $request = AssistanceBotRequest::first();
        $this->assertSame('accessory', $request->kind);
        $this->assertSame('film', $request->data['accessory_category']);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_inbound_router_does_not_require_agenda_for_assistance(): void
    {
        $fixture = $this->fixture();
        $job = new HandleWhatsAppInboundMessageJob($fixture[2]->instance_name, '5511988887777@s.whatsapp.net', '5511988887777', 'oi', 'router');
        app()->call([$job, 'handle']);
        $this->assertDatabaseCount('assistance_bot_messages', 1);
        Queue::assertPushed(SendAssistanceBotReplyJob::class);
    }

    public function test_employee_can_handle_conversations_but_cannot_edit_flow_and_cross_tenant_ids_are_rejected(): void
    {
        [$company, $user] = $fixture = $this->fixture();
        $this->say($fixture, 'oi', 'scope');
        $employee = $this->createCompanyUser($company, [], CompanyRole::Employee);
        $this->authenticateForAppTenant($employee, $company);
        $this->assertFalse(AssistanceFlowPage::canAccess());
        Livewire::test(AssistanceConversationsPage::class)->assertSuccessful()->call('selectConversation', AssistanceBotConversation::first()->id)->assertSee('Assumir conversa');
        $other = $this->createCompany(['business_profile' => CompanyProfile::TechnicalAssistance, 'enabled_modules' => $company->enabled_modules]);
        $otherUser = $this->createCompanyUser($other);
        $this->authenticateForAppTenant($otherUser, $other);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(AssistanceConversationsPage::class)->call('selectConversation', AssistanceBotConversation::first()->id);
    }

    public function test_editor_can_edit_each_block_type_and_publish_without_automatically_enabling(): void
    {
        [$company, $user] = $this->fixture(false);
        $this->authenticateForAppTenant($user, $company);
        $page = Livewire::test(AssistanceFlowPage::class);
        $page->call('selectNode', 1)->assertSee('Adicionar opção')->call('addOption')
            ->assertSet('definition.nodes.1.options.3.label', 'Nova opção')
            ->call('removeOption', 3)->call('selectNode', 2)->assertSee('Salvar a resposta em')
            ->call('selectNode', 5)->assertSee('Ação protegida do sistema')
            ->call('selectNode', 6)->assertSee('Se sim, seguir para')->call('publish')->assertHasNoErrors();
        $flow = AssistanceBotFlow::first();
        $this->assertFalse($flow->is_enabled);
        $this->assertSame(1, $flow->versions()->count());
    }

    public function test_bot_is_disabled_until_explicit_activation_and_does_not_process_missing_message_id(): void
    {
        $fixture = $this->fixture(false);
        $this->say($fixture, 'oi', 'disabled');
        $this->assertDatabaseCount('assistance_bot_conversations', 0);
        $this->expectException(ValidationException::class);
        app(AssistanceFlowService::class)->setEnabled($fixture[0], $fixture[1], true);
    }

    public function test_a_name_given_in_first_message_is_not_requested_again(): void
    {
        $fixture = $this->fixture();
        $reply = $this->say($fixture, 'Meu nome é Maria', 'name-first');
        $this->assertStringContainsString('Olá, Maria', $reply);
        $this->assertStringNotContainsString('Como você se chama', $reply);
        $this->assertDatabaseHas('clients', ['name' => 'Maria', 'phone_normalized' => '5511988887777']);
    }
}
