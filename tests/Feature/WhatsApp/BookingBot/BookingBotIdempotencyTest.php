<?php

namespace Tests\Feature\WhatsApp\BookingBot;

use App\Enums\CompanyModule;
use App\Enums\WhatsAppBotConversationState;
use App\Models\Company;
use App\Models\WhatsAppBotConversation;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotService;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class BookingBotIdempotencyTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    public function test_duplicate_message_id_is_ignored(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $this->enableBot($company);

        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511988884444';
        $jid = "{$phone}@s.whatsapp.net";

        $first = $bot->handleIncoming($company, null, $jid, $phone, '1', 'dup-msg-1');
        $second = $bot->handleIncoming($company, null, $jid, $phone, '1', 'dup-msg-1');

        $this->assertNotNull($first);
        $this->assertNull($second);

        $conversation = WhatsAppBotConversation::query()
            ->where('company_id', $company->getKey())
            ->where('phone_normalized', $phone)
            ->firstOrFail();

        // Deve estar no menu de serviços (avançou uma vez, e a segunda foi ignorada)
        $this->assertSame(WhatsAppBotConversationState::ChoosingService, $conversation->state);
        $this->assertSame('dup-msg-1', $conversation->last_incoming_message_id);
    }

    public function test_new_message_id_on_same_state_is_processed(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $this->enableBot($company);

        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511988883333';
        $jid = "{$phone}@s.whatsapp.net";

        $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'a-1');
        $bot->handleIncoming($company, null, $jid, $phone, 'abc', 'a-2');
        $reply = $bot->handleIncoming($company, null, $jid, $phone, 'xyz', 'a-3');

        $this->assertStringContainsString('Não entendi', (string) $reply);

        $conversation = WhatsAppBotConversation::query()
            ->where('company_id', $company->getKey())
            ->where('phone_normalized', $phone)
            ->firstOrFail();

        $this->assertSame('a-3', $conversation->last_incoming_message_id);
    }

    protected function enableBot(Company $company): void
    {
        $company->update([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::WhatsApp->value,
            ],
        ]);
        $this->enablePublicBooking($company, ['whatsapp_bot_enabled' => true]);
    }
}
