<?php

namespace Tests\Feature\CarWash;

use App\Enums\CompanyProfile;
use App\Filament\App\Pages\CarWashFlowPage;
use App\Jobs\HandleWhatsAppInboundMessageJob;
use App\Jobs\SendCarWashBotReplyJob;
use App\Models\Appointment;
use App\Models\CarWashBotConversation;
use App\Models\CarWashBotMessage;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\Service;
use App\Models\User;
use App\Services\CarWash\CarWashBotService;
use App\Services\CarWash\CarWashFlowService;
use App\Services\Company\CompanyModuleService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotService;
use App\Services\WhatsApp\Bot\WhatsAppOrderLinkBotService;
use App\Services\WhatsApp\EvolutionApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class CarWashBotFlowTest extends TestCase
{
    use CreatesSchedulingFixtures;

    /** @return array{0: Company, 1: User, 2: CompanyWhatsAppInstance, 3: Service} */
    private function fixture(): array
    {
        Queue::fake([SendCarWashBotReplyJob::class]);
        $company = $this->createCompany([
            'business_profile' => CompanyProfile::CarWash,
            'enabled_modules' => ['scheduling', 'whatsapp', 'sales'],
        ]);
        $setup = $this->createBookableSetup($company);
        $setup['service']->update(['name' => 'Lavagem completa', 'price' => 80]);
        app(CompanySchedulingSettingService::class)->update($company, [
            'public_booking_enabled' => true,
            'show_service_price' => true,
            'ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash',
            'ai_api_key' => 'test-key',
        ]);
        $instance = new CompanyWhatsAppInstance(['name' => 'Lava', 'instance_name' => 'lava-'.$company->id, 'status' => 'open', 'is_default' => true]);
        $instance->company_id = $company->id;
        $instance->save();
        $manager = app(CarWashFlowService::class);
        $flow = $manager->get($company, $setup['admin']);
        $flow = $manager->publish($company, $setup['admin'], $flow->draft, $flow->revision);
        $manager->setEnabled($company, $setup['admin'], true);

        return [$company, $setup['admin'], $instance, $setup['service']->fresh()];
    }

    private function say(array $fixture, string $text, string $id, string $phone = '5511988887777'): string
    {
        [$company, , $instance] = $fixture;
        app(CarWashBotService::class)->receive($company, $instance, $phone, $text, $id);

        return (string) CarWashBotMessage::where('provider_message_key', hash('sha256', $company->id.':'.$instance->id.':'.$id))->value('reply');
    }

    public function test_menu_and_question_do_not_call_the_model(): void
    {
        $fixture = $this->fixture();
        Http::fake(['*' => Http::response(['key' => ['id' => 'sent']])]);

        $this->assertStringContainsString('Como você se chama?', $this->say($fixture, 'oi', 'cw-1'));
        $menu = $this->say($fixture, 'Ana Souza', 'cw-2');
        $this->assertStringContainsString('Agendar lavagem', $menu);
        $this->assertStringContainsString('Qual lavagem você quer fazer?', $this->say($fixture, '1', 'cw-3'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generateContent'));
    }

    public function test_ai_block_advances_only_for_an_allowed_outcome(): void
    {
        $fixture = $this->fixture();
        $this->reachPackageQuestion($fixture);
        $step = 0;
        Http::fake(function ($request) use (&$step, $fixture) {
            $step++;
            $payload = $step === 1
                ? ['outcome' => 'inventei', 'service_id' => $fixture[3]->id, 'reply' => '']
                : ['outcome' => 'entendeu', 'service_id' => $fixture[3]->id, 'reply' => 'Fica R$ 80 às 10h'];

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($payload)]]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]);
        });

        $rejected = $this->say($fixture, 'quero lavar', 'cw-ai-1');
        $this->assertStringContainsString('só ajudo com lavagem', $rejected);
        $this->assertStringNotContainsString('Qual é a placa', $rejected);

        $accepted = $this->say($fixture, 'lavagem completa', 'cw-ai-2');
        $this->assertStringContainsString('Qual é a placa', $accepted);
        $this->assertStringNotContainsString('R$ 80', $accepted);
        $this->assertSame($fixture[3]->id, CarWashBotConversation::query()->firstOrFail()->state['data']['service_id']);
    }

    public function test_off_topic_replies_with_the_configured_warning_and_notifies_the_team(): void
    {
        [$company, $user, $instance] = $fixture = $this->fixture();
        $this->reachPackageQuestion($fixture);
        $this->fakeModel(['outcome' => 'fora_do_assunto', 'reply' => 'Claro, vamos falar de futebol.']);

        $reply = $this->say($fixture, 'quem ganha o jogo hoje?', 'cw-off');

        $this->assertStringContainsString('só ajudo com lavagem', $reply);
        $this->assertStringNotContainsString('futebol', $reply);
        $this->assertSame('package', CarWashBotConversation::query()->firstOrFail()->state['cursor']);
        $this->assertTrue($user->notifications()->get()->contains(
            fn ($notification) => str_contains(json_encode($notification->data, JSON_UNESCAPED_UNICODE), 'saiu do assunto')
                && str_contains(json_encode($notification->data, JSON_UNESCAPED_UNICODE), 'quem ganha o jogo'),
        ));
        $this->assertSame(0, Appointment::query()->count());
        $this->assertNotNull($instance->id);
        $this->assertSame($company->id, CarWashBotConversation::query()->first()->company_id);
    }

    public function test_missing_ai_key_warns_the_customer_and_tells_the_team(): void
    {
        [$company, $user] = $fixture = $this->fixture();
        $company->schedulingSetting->update(['ai_api_key' => null]);
        $this->reachPackageQuestion($fixture);
        Http::fake();

        $reply = $this->say($fixture, 'quero lavar o carro', 'cw-nokey');

        $this->assertStringContainsString('só ajudo com lavagem', $reply);
        Http::assertNothingSent();
        $this->assertTrue($user->notifications()->get()->contains(
            fn ($notification) => str_contains(json_encode($notification->data, JSON_UNESCAPED_UNICODE), 'não respondeu'),
        ));
    }

    public function test_invalid_plate_does_not_book_and_a_free_slot_books_with_the_plate(): void
    {
        $fixture = $this->fixture();
        $this->reachPackageQuestion($fixture);
        $this->fakeModel(['outcome' => 'entendeu', 'service_id' => $fixture[3]->id, 'reply' => '']);
        $this->say($fixture, 'lavagem completa', 'cw-book-1');

        $invalid = $this->say($fixture, 'carro', 'cw-book-2');
        $this->assertStringContainsString('placa válida', $invalid);
        $this->assertSame(0, Appointment::query()->count());

        $slots = $this->say($fixture, 'ABC1D23', 'cw-book-3');
        $this->assertStringContainsString('1.', $slots);
        $this->assertStringNotContainsString('não achei horário', $slots);

        $confirm = $this->say($fixture, '1', 'cw-book-4');
        $this->assertStringContainsString('Posso marcar?', $confirm);
        $booked = $this->say($fixture, '1', 'cw-book-5');
        $this->assertStringContainsString('tá marcado', $booked);
        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame('ABC1D23', Client::query()->where('phone_normalized', '5511988887777')->firstOrFail()->vehicle_plate);
    }

    public function test_other_profiles_cannot_open_the_editor_or_enter_the_bot(): void
    {
        $salon = $this->createCompany([
            'business_profile' => CompanyProfile::Salon,
            'enabled_modules' => ['scheduling', 'whatsapp'],
        ]);
        $user = $this->createCompanyUser($salon);
        $this->authenticateForAppTenant($user, $salon);
        $this->assertFalse(CarWashFlowPage::canAccess());

        $instance = new CompanyWhatsAppInstance(['name' => 'Salao', 'instance_name' => 'salao-'.$salon->id, 'status' => 'open', 'is_default' => true]);
        $instance->company_id = $salon->id;
        $instance->save();
        $job = new HandleWhatsAppInboundMessageJob($instance->instance_name, '5511977776666@s.whatsapp.net', '5511977776666', 'oi', 'salon-1');
        $job->handle(
            app(WhatsAppBookingBotService::class),
            app(EvolutionApiClient::class),
            app(CompanyModuleService::class),
            app(CompanySchedulingSettingService::class),
            app(WhatsAppOrderLinkBotService::class),
        );
        $this->assertSame(0, CarWashBotConversation::query()->count());
    }

    public function test_car_wash_page_edits_the_draft_and_the_active_flow_receives_whatsapp(): void
    {
        [$company, $user, $instance] = $this->fixture();
        $this->authenticateForAppTenant($user, $company);
        Livewire::test(CarWashFlowPage::class)->assertSuccessful()->assertSee('Onde entra a IA')
            ->set('definition.off_context_message', 'Aqui é só lavagem da {empresa}.')
            ->call('saveDraft')->assertHasNoErrors()
            ->set('simulateKnownCustomer', true)
            ->call('startSimulation')
            ->assertSee('Como posso te ajudar?')
            ->set('simulatorInput', '2')
            ->call('sendSimulation')
            ->assertSee('Simulação: os preços saem do cadastro');

        $job = new HandleWhatsAppInboundMessageJob($instance->instance_name, '5511966665555@s.whatsapp.net', '5511966665555', 'oi', 'lava-job');
        $job->handle(
            app(WhatsAppBookingBotService::class),
            app(EvolutionApiClient::class),
            app(CompanyModuleService::class),
            app(CompanySchedulingSettingService::class),
            app(WhatsAppOrderLinkBotService::class),
        );
        $this->assertSame(1, CarWashBotConversation::query()->where('company_id', $company->id)->count());
        $this->assertNotNull(CarWashBotMessage::query()->where('incoming', 'oi')->value('reply'));
    }

    /** @param  array<string, mixed>  $payload */
    private function fakeModel(array $payload): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode($payload)]]],
            ]],
        ])]);
    }

    /** @param  array{0: Company, 1: User, 2: CompanyWhatsAppInstance, 3: Service}  $fixture */
    private function reachPackageQuestion(array $fixture): void
    {
        $this->say($fixture, 'oi', 'cw-reach-1');
        $this->say($fixture, 'Ana Souza', 'cw-reach-2');
        $this->say($fixture, '1', 'cw-reach-3');
    }
}
