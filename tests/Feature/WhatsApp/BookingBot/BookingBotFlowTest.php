<?php

namespace Tests\Feature\WhatsApp\BookingBot;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\CompanyModule;
use App\Enums\WhatsAppBotConversationState;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\WhatsAppBotConversation;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotService;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class BookingBotFlowTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    public function test_full_booking_flow_creates_appointment(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $company->update([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::WhatsApp->value,
            ],
        ]);

        $this->enablePublicBooking($company, [
            'whatsapp_bot_enabled' => true,
        ]);

        $instance = $this->createInstance($company);
        $phone = '5511977776666';
        $jid = "{$phone}@s.whatsapp.net";

        $bot = app(WhatsAppBookingBotService::class);

        $greeting = $bot->handleIncoming($company, $instance, $jid, $phone, 'oi', 'msg-1');
        $this->assertStringContainsString('Agendar um horário', (string) $greeting);

        $serviceMenu = $bot->handleIncoming($company, $instance, $jid, $phone, '1', 'msg-2');
        $this->assertStringContainsString('serviço', (string) $serviceMenu);
        $this->assertStringContainsString($setup['service']->name, (string) $serviceMenu);

        $professionalMenu = $bot->handleIncoming($company, $instance, $jid, $phone, '1', 'msg-3');
        $this->assertStringContainsString('profissional', (string) $professionalMenu);
        $this->assertStringContainsString($setup['professional']->name, (string) $professionalMenu);

        $dateMenu = $bot->handleIncoming($company, $instance, $jid, $phone, '1', 'msg-4');
        $this->assertStringContainsString('data', (string) $dateMenu);

        $timeMenu = $bot->handleIncoming($company, $instance, $jid, $phone, '1', 'msg-5');
        $this->assertStringContainsString('horário', (string) $timeMenu);

        $nameReply = $bot->handleIncoming($company, $instance, $jid, $phone, '1', 'msg-6');
        $this->assertStringContainsString('nome', (string) $nameReply);

        $confirmation = $bot->handleIncoming($company, $instance, $jid, $phone, 'Ana Souza', 'msg-7');
        $this->assertStringContainsString('Confirme', (string) $confirmation);
        $this->assertStringContainsString($setup['service']->name, (string) $confirmation);
        $this->assertStringContainsString('Ana Souza', (string) $confirmation);

        $success = $bot->handleIncoming($company, $instance, $jid, $phone, '1', 'msg-8');
        $this->assertStringContainsString('Agendamento confirmado', (string) $success);

        $appointment = Appointment::query()
            ->where('company_id', $company->getKey())
            ->first();

        $this->assertNotNull($appointment);
        $this->assertSame(AppointmentOrigin::Online, $appointment->origin);
        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertSame($phone, $appointment->client_phone_snapshot);

        $conversation = WhatsAppBotConversation::query()
            ->where('company_id', $company->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertNotNull($conversation->finished_at);
        $this->assertSame('completed', $conversation->finished_reason);
        $this->assertSame($appointment->getKey(), $conversation->appointment_id);
    }

    public function test_menu_command_resets_conversation_to_greeting(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $this->enableBot($company);

        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511977776600';
        $jid = "{$phone}@s.whatsapp.net";

        $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'a-1');
        $bot->handleIncoming($company, null, $jid, $phone, '1', 'a-2');

        $conversation = WhatsAppBotConversation::query()
            ->where('company_id', $company->getKey())
            ->where('phone_normalized', $phone)
            ->firstOrFail();

        $this->assertSame(WhatsAppBotConversationState::ChoosingService, $conversation->state);

        $reply = $bot->handleIncoming($company, null, $jid, $phone, 'menu', 'a-3');
        $this->assertStringContainsString('Agendar um horário', (string) $reply);

        $conversation->refresh();
        $this->assertSame(WhatsAppBotConversationState::Greeting, $conversation->state);
    }

    public function test_handoff_finishes_conversation(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $this->enableBot($company);

        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511977776500';
        $jid = "{$phone}@s.whatsapp.net";

        $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'h-1');
        $reply = $bot->handleIncoming($company, null, $jid, $phone, '0', 'h-2');

        $this->assertStringContainsString('atendente', (string) $reply);

        $conversation = WhatsAppBotConversation::query()
            ->where('company_id', $company->getKey())
            ->where('phone_normalized', $phone)
            ->firstOrFail();

        $this->assertNotNull($conversation->finished_at);
        $this->assertSame('handoff', $conversation->finished_reason);
    }

    public function test_auto_assigns_professional_when_selection_is_disabled(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $this->enableBot($company, [
            'allow_professional_selection' => false,
            'allow_no_professional_preference' => false,
        ]);

        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511977776300';
        $jid = "{$phone}@s.whatsapp.net";

        $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'p-1');
        $dates = $bot->handleIncoming($company, null, $jid, $phone, '1', 'p-2');
        $this->assertStringContainsString('serviço', (string) $dates);

        $afterService = $bot->handleIncoming($company, null, $jid, $phone, '1', 'p-3');
        $this->assertStringContainsString('data', (string) $afterService);
        $this->assertStringNotContainsString('Selecione um profissional', (string) $afterService);

        $conversation = WhatsAppBotConversation::query()
            ->where('company_id', $company->getKey())
            ->where('phone_normalized', $phone)
            ->firstOrFail();

        $this->assertSame(WhatsAppBotConversationState::ChoosingDate, $conversation->state);
        $this->assertSame($setup['professional']->getKey(), (int) ($conversation->data['professional_id'] ?? 0));
    }

    public function test_reuses_existing_client_by_whatsapp_phone(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $this->enableBot($company);

        Client::factory()->forCompany($company)->active()->create([
            'name' => 'Maria Silva',
            'phone' => '(11) 97777-6200',
            'email' => 'maria@example.com',
        ]);

        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511977776200';
        $jid = "{$phone}@s.whatsapp.net";

        $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'c-1');
        $bot->handleIncoming($company, null, $jid, $phone, '1', 'c-2');
        $bot->handleIncoming($company, null, $jid, $phone, '1', 'c-3');
        $bot->handleIncoming($company, null, $jid, $phone, '1', 'c-4');
        $bot->handleIncoming($company, null, $jid, $phone, '1', 'c-5');
        $reply = $bot->handleIncoming($company, null, $jid, $phone, '1', 'c-6');

        $this->assertStringContainsString('Maria Silva', (string) $reply);
        $this->assertStringContainsString('Confirme', (string) $reply);
    }

    public function test_invalid_option_keeps_state_and_prompts_again(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $this->enableBot($company);

        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511977776400';
        $jid = "{$phone}@s.whatsapp.net";

        $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'i-1');
        $ignored = $bot->handleIncoming($company, null, $jid, $phone, 'abc', 'i-2');
        $this->assertNull($ignored);

        $bot->handleIncoming($company, null, $jid, $phone, '1', 'i-3');
        $reply = $bot->handleIncoming($company, null, $jid, $phone, 'abc', 'i-4');

        $this->assertStringContainsString('Não entendi', (string) $reply);

        $conversation = WhatsAppBotConversation::query()
            ->where('company_id', $company->getKey())
            ->where('phone_normalized', $phone)
            ->firstOrFail();

        $this->assertSame(WhatsAppBotConversationState::ChoosingService, $conversation->state);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function enableBot(Company $company, array $overrides = []): void
    {
        $company->update([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::WhatsApp->value,
            ],
        ]);
        $this->enablePublicBooking($company, array_merge([
            'whatsapp_bot_enabled' => true,
        ], $overrides));
    }

    protected function createInstance(Company $company): CompanyWhatsAppInstance
    {
        $instance = new CompanyWhatsAppInstance([
            'name' => 'Principal',
            'instance_name' => 'bot-test-'.$company->getKey(),
            'sender_phone' => '5511900000000',
            'status' => 'open',
            'is_default' => true,
            'connected_at' => now(),
        ]);
        $instance->company()->associate($company);
        $instance->save();

        return $instance->refresh();
    }
}
