<?php

namespace Tests\Feature\Beauty;

use App\Enums\AppointmentOrigin;
use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Filament\App\Resources\TattooAiConversations\TattooAiConversationResource;
use App\Jobs\HandleWhatsAppInboundMessageJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\Professional;
use App\Models\Service;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Services\Beauty\BeautyAIConversationService;
use App\Services\PublicBooking\OnlineBookingCatalogService;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class BeautyAIFlowTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    private string $phone = '5511988887777';

    /** @return array{0: Company, 1: CompanyWhatsAppInstance, 2: Service, 3: Professional} */
    private function setupBeauty(array $settings = []): array
    {
        $company = $this->createSchedulingCompany([
            'business_profile' => CompanyProfile::Salon,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::WhatsApp->value],
        ]);
        $setup = $this->createBookableSetup($company);
        $setup['service']->update(['name' => 'Corte feminino', 'price' => 80]);
        $this->enablePublicBooking($company, array_merge(['beauty_ai_enabled' => true, 'online_auto_confirm' => true,
            'ai_provider' => 'gemini', 'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => 'test-key'], $settings));
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'beauty-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.gemini.api_key' => 'test-key', 'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key']);

        return [$company, $instance, $setup['service']->fresh(), $setup['professional']];
    }

    /** @param  list<array<string, mixed>>  $responses */
    private function fakeAi(array $responses): void
    {
        $queue = $responses;
        Http::fake(function (Request $request) use (&$queue) {
            if (str_contains($request->url(), 'generateContent')) {
                $next = array_shift($queue) ?? ['action' => 'ask', 'details' => [], 'reply' => ''];

                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($next)]]]]]]);
            }

            return Http::response(['key' => ['id' => 'sent-'.uniqid()]]);
        });
    }

    private function send($company, $instance, string $text, string $id): string
    {
        app(BeautyAIConversationService::class)->handle($company, $instance, $this->phone.'@s.whatsapp.net',
            $this->phone, $text, $id, null);

        return (string) TattooAiMessage::query()->where('provider_message_id', 'reply:'.$id)->value('body');
    }

    private function geminiCalls(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'generateContent'))->count();
    }

    public function test_phrase_is_not_used_as_name_and_real_name_registers_client(): void
    {
        [$company, $instance, $service] = $this->setupBeauty();
        $this->fakeAi([
            ['action' => 'save_details', 'details' => ['name' => 'Quero fazer um corte', 'service_id' => $service->id], 'reply' => ''],
            ['action' => 'save_details', 'details' => ['name' => 'Ana Souza'], 'reply' => ''],
        ]);

        $reply = $this->send($company, $instance, 'Oi, quero fazer um corte', 'n1');
        $this->assertStringContainsString('Qual seu nome?', $reply);
        $this->assertStringContainsString('Corte feminino', $reply);
        $this->assertDatabaseMissing('clients', ['phone_normalized' => $this->phone]);

        $reply = $this->send($company, $instance, 'Ana Souza', 'n2');
        $this->assertStringStartsWith('Prazer, Ana!', $reply);
        $conversation = TattooAiConversation::query()->firstOrFail();
        $this->assertSame('Ana Souza', $conversation->collected_data['name']);
        $this->assertDatabaseHas('clients', ['id' => $conversation->client_id, 'company_id' => $company->id,
            'name' => 'Ana Souza', 'phone_normalized' => $this->phone]);
    }

    public function test_service_is_matched_from_real_catalog_and_real_slots_are_offered(): void
    {
        [$company, $instance, $service, $professional] = $this->setupBeauty();
        Client::factory()->forCompany($company)->create(['name' => 'Ana Souza', 'phone' => $this->phone]);
        $this->fakeAi([
            ['action' => 'save_details', 'details' => ['service_id' => 99999], 'reply' => ''],
            ['action' => 'save_details', 'details' => ['service_id' => $service->id], 'reply' => ''],
        ]);

        $reply = $this->send($company, $instance, 'Quero fazer luzes', 's1');
        $this->assertStringContainsString('Qual serviço', $reply);
        $this->assertStringContainsString('Corte feminino', $reply);
        $this->assertArrayNotHasKey('service_id', TattooAiConversation::query()->firstOrFail()->collected_data);

        $reply = $this->send($company, $instance, 'Então um corte feminino', 's2');
        $conversation = TattooAiConversation::query()->firstOrFail();
        $offered = $conversation->collected_data['offered_slots'];
        $this->assertSame($service->id, $conversation->collected_data['service_id']);
        $this->assertSame('offering_slots', $conversation->status);
        $this->assertCount(3, $offered);
        $this->assertStringContainsString('Pra Corte feminino', $reply);
        $this->assertStringContainsString('tenho estes horários:', $reply);
        $catalog = app(OnlineBookingCatalogService::class);
        foreach ($offered as $slot) {
            $this->assertStringContainsString($slot['label'], $reply);
            $this->assertSame($professional->id, $slot['professional_id']);
            $date = CarbonImmutable::createFromFormat('Y-m-d', substr($slot['value'], 0, 10), 'America/Sao_Paulo')->startOfDay();
            $available = $catalog->getAvailableSlots($company, $service, $professional->id, $date)
                ->map(fn (CarbonImmutable $item) => $item->format('Y-m-d H:i'))->all();
            $this->assertContains($slot['value'], $available);
        }
    }

    public function test_confirmed_slot_creates_online_appointment_for_the_whatsapp_client(): void
    {
        [$company, $instance, $service, $professional] = $this->setupBeauty();
        $this->fakeAi([
            ['action' => 'save_details', 'details' => ['name' => 'Ana', 'service_id' => $service->id], 'reply' => ''],
            ['action' => 'choose_slot', 'details' => ['slot_option' => 2], 'reply' => ''],
        ]);

        $this->send($company, $instance, 'Oi, sou a Ana e quero cortar o cabelo', 'b1');
        $offered = TattooAiConversation::query()->firstOrFail()->collected_data['offered_slots'];
        $reply = $this->send($company, $instance, 'O segundo', 'b2');
        $this->assertStringContainsString('Posso confirmar?', $reply);
        $this->assertStringContainsString($offered[1]['label'], $reply);
        $this->assertStringContainsString('R$ 80,00', $reply);
        $this->assertDatabaseCount('appointments', 0);

        $reply = $this->send($company, $instance, 'Sim', 'b3');
        $this->assertSame(2, $this->geminiCalls(), 'A confirmação "sim" não precisa chamar a IA.');
        $this->assertStringStartsWith('Prontinho, Ana!', $reply);
        $appointment = Appointment::query()->where('company_id', $company->id)->sole();
        $this->assertSame(AppointmentOrigin::Online, $appointment->origin);
        $this->assertSame($professional->id, $appointment->professional_id);
        $this->assertSame($service->id, $appointment->service_id);
        $this->assertTrue((bool) $appointment->send_whatsapp_confirmation);
        $this->assertSame($offered[1]['value'], CompanyDateTime::utcToLocal($company, $appointment->start_at)->format('Y-m-d H:i'));
        $this->assertSame(1, Client::query()->where('company_id', $company->id)->where('phone_normalized', $this->phone)->count());
        $conversation = TattooAiConversation::query()->firstOrFail();
        $this->assertSame('converted_to_appointment', $conversation->status);
        $this->assertSame($appointment->id, $conversation->appointment_id);
        $this->assertSame($appointment->client_id, $conversation->client_id);
        $this->assertArrayNotHasKey('offered_slots', $conversation->collected_data);
    }

    public function test_slot_taken_meanwhile_is_not_booked_and_new_real_slots_are_offered(): void
    {
        [$company, $instance, $service, $professional] = $this->setupBeauty();
        $this->fakeAi([
            ['action' => 'save_details', 'details' => ['name' => 'Ana', 'service_id' => $service->id], 'reply' => ''],
        ]);
        $this->send($company, $instance, 'Sou a Ana, quero um corte', 't1');
        $this->send($company, $instance, '1', 't2');
        $taken = TattooAiConversation::query()->firstOrFail()->collected_data['selected_slot'];
        app(OnlineBookingService::class)->create($this->makeOnlineBookingData($company, $service->id, $professional->id,
            CompanyDateTime::parseLocal($company, substr($taken['value'], 0, 10), substr($taken['value'], 11, 5))));

        $reply = $this->send($company, $instance, 'pode confirmar', 't3');
        $this->assertStringContainsString('acabou de ser preenchido', $reply);
        $this->assertDatabaseCount('appointments', 1);
        $conversation = TattooAiConversation::query()->firstOrFail();
        $this->assertSame('offering_slots', $conversation->status);
        $this->assertNotContains($taken['value'], array_column($conversation->collected_data['offered_slots'], 'value'));
    }

    public function test_invented_price_or_time_from_model_is_never_sent_but_real_price_is(): void
    {
        [$company, $instance, $service] = $this->setupBeauty();
        Client::factory()->forCompany($company)->create(['name' => 'Ana Souza', 'phone' => $this->phone]);
        $this->fakeAi([
            ['action' => 'ask', 'details' => [], 'reply' => 'Fica R$ 50 e tenho amanhã às 10h!'],
            ['action' => 'info', 'details' => ['info_topic' => 'prices', 'service_id' => $service->id], 'reply' => 'Custa R$ 30'],
        ]);

        $reply = $this->send($company, $instance, 'Quanto é e quando tem?', 'p1');
        $this->assertStringNotContainsString('R$ 50', $reply);
        $this->assertStringNotContainsString('10h', $reply);
        $this->assertStringContainsString('Qual serviço', $reply);

        $reply = $this->send($company, $instance, 'Quanto custa o corte?', 'p2');
        $this->assertStringContainsString('Corte feminino fica R$ 80,00', $reply);
        $this->assertStringNotContainsString('R$ 30', $reply);
    }

    public function test_handoff_pauses_ai_and_following_messages_get_no_reply(): void
    {
        [$company, $instance] = $this->setupBeauty();
        Client::factory()->forCompany($company)->create(['name' => 'Ana Souza', 'phone' => $this->phone]);
        $this->fakeAi([['action' => 'handoff', 'details' => [], 'reply' => '']]);

        $reply = $this->send($company, $instance, 'Quero remarcar meu horário de sexta', 'h1');
        $this->assertStringStartsWith('Beleza, vou chamar a equipe', $reply);
        $this->assertDatabaseHas('tattoo_ai_messages', ['provider_message_id' => 'reply:h1', 'status' => 'sent']);
        $this->assertTrue(TattooAiConversation::query()->firstOrFail()->human_takeover);

        $this->send($company, $instance, 'Alô?', 'h2');
        $this->assertDatabaseMissing('tattoo_ai_messages', ['provider_message_id' => 'reply:h2']);
        $this->assertSame(1, $this->geminiCalls());
    }

    public function test_unknown_model_action_is_ignored_and_flow_continues(): void
    {
        [$company, $instance] = $this->setupBeauty();
        $this->fakeAi([['action' => 'delete_appointments', 'details' => [], 'reply' => 'ok']]);

        $reply = $this->send($company, $instance, 'Quero agendar', 'u1');
        $this->assertSame("Oi, tudo bem? 😊\n\nQual seu nome?", $reply);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertFalse((bool) TattooAiConversation::query()->firstOrFail()->human_takeover);
    }

    public function test_job_routes_salon_to_ai_and_respects_whatsapp_takeover_pause(): void
    {
        [$company, $instance] = $this->setupBeauty();
        $this->fakeAi([['action' => 'ask', 'details' => [], 'reply' => '']]);
        app(WhatsAppHumanTakeover::class)->pause($instance->instance_name, $this->phone);

        app()->call([new HandleWhatsAppInboundMessageJob('beauty-test', $this->phone.'@s.whatsapp.net', $this->phone, 'Oi', 'job-1'), 'handle']);
        $this->assertDatabaseHas('tattoo_ai_messages', ['provider_message_id' => 'job-1', 'status' => 'processed']);
        $this->assertDatabaseMissing('tattoo_ai_messages', ['provider_message_id' => 'reply:job-1']);
        Http::assertNothingSent();

        app(WhatsAppHumanTakeover::class)->resume($instance->instance_name, $this->phone);
        app()->call([new HandleWhatsAppInboundMessageJob('beauty-test', $this->phone.'@s.whatsapp.net', $this->phone, 'Oi', 'job-2'), 'handle']);
        $this->assertStringContainsString('Qual seu nome?', (string) TattooAiMessage::query()->where('provider_message_id', 'reply:job-2')->value('body'));
    }

    public function test_salon_without_ai_enabled_keeps_the_current_bot(): void
    {
        [$company] = $this->setupBeauty(['beauty_ai_enabled' => false, 'whatsapp_bot_enabled' => true]);
        $this->fakeAi([]);

        app()->call([new HandleWhatsAppInboundMessageJob('beauty-test', $this->phone.'@s.whatsapp.net', $this->phone, 'oi', 'old-1'), 'handle']);
        $this->assertDatabaseCount('tattoo_ai_conversations', 0);
        $this->assertDatabaseHas('whatsapp_bot_conversations', ['company_id' => $company->id, 'phone_normalized' => $this->phone]);
        $this->assertSame(0, $this->geminiCalls());
    }

    public function test_salon_admin_sees_ai_conversations_with_appointment_card(): void
    {
        [$company, $instance] = $this->setupBeauty();
        $user = $this->createCompanyUser($company);
        $conversation = TattooAiConversation::query()->create(['company_id' => $company->id,
            'company_whatsapp_instance_id' => $instance->id, 'phone_normalized' => $this->phone,
            'remote_jid' => $this->phone.'@s.whatsapp.net', 'collected_data' => ['service_name' => 'Corte feminino']]);
        $this->authenticateForAppTenant($user, $company);

        $this->get(TattooAiConversationResource::getUrl('index'))->assertOk()->assertSee($this->phone);
        $this->get(TattooAiConversationResource::getUrl('view', ['record' => $conversation]))
            ->assertOk()->assertSee('Agendamento')->assertSee('Corte feminino')->assertDontSee('Pedido de tatuagem');
    }
}
