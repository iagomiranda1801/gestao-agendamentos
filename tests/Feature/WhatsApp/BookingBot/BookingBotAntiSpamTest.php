<?php

namespace Tests\Feature\WhatsApp\BookingBot;

use App\Enums\CompanyModule;
use App\Enums\WhatsAppBotConversationState;
use App\Models\Company;
use App\Models\WhatsAppBotConversation;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotService;
use Carbon\Carbon;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class BookingBotAntiSpamTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    public function test_first_oi_sends_greeting_and_second_oi_does_not_resend(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', 'America/Sao_Paulo'));

        $company = $this->enableCompany();
        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511981110001';
        $jid = "{$phone}@s.whatsapp.net";

        $first = $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'spam-1');
        $second = $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'spam-2');

        $this->assertStringContainsString('Agendar um horário', (string) $first);
        $this->assertNull($second);
        $this->assertSame(1, WhatsAppBotConversation::query()->where('phone_normalized', $phone)->count());
    }

    public function test_casual_text_after_greeting_does_not_send_nao_entendi(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', 'America/Sao_Paulo'));

        $company = $this->enableCompany();
        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511981110002';
        $jid = "{$phone}@s.whatsapp.net";

        $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'casual-1');
        $reply = $bot->handleIncoming($company, null, $jid, $phone, 'quero saber o preço da limpeza', 'casual-2');

        $this->assertNull($reply);

        $conversation = WhatsAppBotConversation::query()
            ->where('phone_normalized', $phone)
            ->firstOrFail();

        $this->assertSame(WhatsAppBotConversationState::Greeting, $conversation->state);
        $this->assertNull($conversation->finished_at);
    }

    public function test_handoff_then_casual_message_does_not_restart_greeting(): void
    {
        $logged = $this->captureLogMessages();
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', 'America/Sao_Paulo'));

        $company = $this->enableCompany();
        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511981110003';
        $jid = "{$phone}@s.whatsapp.net";

        $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'hand-1');
        $bot->handleIncoming($company, null, $jid, $phone, '0', 'hand-2');
        $reply = $bot->handleIncoming($company, null, $jid, $phone, 'ok, obrigado', 'hand-3');

        $this->assertNull($reply);
        $this->assertSame(1, WhatsAppBotConversation::query()->where('phone_normalized', $phone)->count());
        $this->assertTrue(
            $logged->contains(fn (string $message): bool => str_contains($message, 'WhatsApp booking bot: suppressed')),
            'Expected a booking-bot suppression log. Got: '.$logged->implode(' | '),
        );
    }

    public function test_same_day_oi_after_handoff_does_not_restart_greeting(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', 'America/Sao_Paulo'));

        $company = $this->enableCompany();
        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511981110004';
        $jid = "{$phone}@s.whatsapp.net";

        $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'day-1');
        $bot->handleIncoming($company, null, $jid, $phone, '0', 'day-2');
        $this->travelTo(Carbon::parse('2026-09-15 12:20:00', 'America/Sao_Paulo'));
        $reply = $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'day-3');

        $this->assertNull($reply);
        $this->assertSame(1, WhatsAppBotConversation::query()->where('phone_normalized', $phone)->count());
    }

    public function test_menu_after_cooldown_can_restart_finished_conversation(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', 'America/Sao_Paulo'));

        $company = $this->enableCompany();
        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511981110005';
        $jid = "{$phone}@s.whatsapp.net";

        $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'menu-1');
        $bot->handleIncoming($company, null, $jid, $phone, '0', 'menu-2');
        $this->travelTo(Carbon::parse('2026-09-15 12:20:00', 'America/Sao_Paulo'));
        $reply = $bot->handleIncoming($company, null, $jid, $phone, 'agendar', 'menu-3');

        $this->assertStringContainsString('Agendar um horário', (string) $reply);
        $this->assertSame(2, WhatsAppBotConversation::query()->where('phone_normalized', $phone)->count());
    }

    public function test_next_calendar_day_oi_can_greet_again(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 23:50:00', 'America/Sao_Paulo'));

        $company = $this->enableCompany();
        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511981110006';
        $jid = "{$phone}@s.whatsapp.net";

        $first = $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'next-1');
        $bot->handleIncoming($company, null, $jid, $phone, '0', 'next-2');
        $this->travelTo(Carbon::parse('2026-09-16 00:20:00', 'America/Sao_Paulo'));
        $second = $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'next-3');

        $this->assertStringContainsString('Agendar um horário', (string) $first);
        $this->assertStringContainsString('Agendar um horário', (string) $second);
    }

    public function test_expired_conversation_does_not_auto_greet_on_casual_inbound(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', 'America/Sao_Paulo'));

        $company = $this->enableCompany();
        $bot = app(WhatsAppBookingBotService::class);
        $phone = '5511981110007';
        $jid = "{$phone}@s.whatsapp.net";

        $bot->handleIncoming($company, null, $jid, $phone, 'oi', 'exp-1');
        $this->travelTo(Carbon::parse('2026-09-15 12:35:00', 'America/Sao_Paulo'));
        $reply = $bot->handleIncoming($company, null, $jid, $phone, 'ok', 'exp-2');

        $this->assertNull($reply);

        $expired = WhatsAppBotConversation::query()
            ->where('phone_normalized', $phone)
            ->latest('id')
            ->firstOrFail();

        $this->assertNotNull($expired->finished_at);
        $this->assertSame('expired', $expired->finished_reason);
        $this->assertSame(1, WhatsAppBotConversation::query()->where('phone_normalized', $phone)->count());
    }

    protected function enableCompany(): Company
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $company->update([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::WhatsApp->value,
            ],
        ]);
        $this->enablePublicBooking($company, ['whatsapp_bot_enabled' => true]);

        return $company;
    }

    /**
     * @return Collection<int, string>
     */
    protected function captureLogMessages(): Collection
    {
        $logged = collect();

        Log::listen(function (MessageLogged $event) use ($logged): void {
            $logged->push((string) $event->message);
        });

        return $logged;
    }
}
