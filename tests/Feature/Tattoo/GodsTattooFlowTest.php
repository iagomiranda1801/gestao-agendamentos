<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyProfile;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Models\Professional;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Models\WhatsAppBotConversation;
use App\Models\CompanyBusinessHour;
use App\Models\ProfessionalWorkingHour;
use App\Services\Scheduling\AppointmentService;
use App\Services\Tattoo\TattooAIConversationService;
use App\Services\Tattoo\TattooStudioMenu;
use App\Services\Tattoo\TattooWhatsAppBotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class GodsTattooFlowTest extends TestCase
{
    use CreatesSchedulingFixtures;

    private function studio(): array
    {
        $company = $this->createSchedulingCompany(['name' => 'GODS TATOO',
            'business_profile' => CompanyProfile::TattooStudio]);
        $company->schedulingSetting()->updateOrCreate([], ['tattoo_custom_flow_enabled' => true]);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'gods-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.evolution.url' => 'https://evolution.test', 'services.evolution.key' => 'test-key']);
        Http::fake(['evolution.test/*' => Http::response(['key' => ['id' => 'sent']])]);

        return [$company, $instance, '5511999999999'];
    }

    public function test_legacy_menu_routes_quote_and_handoffs(): void
    {
        [$company, $instance, $phone] = $this->studio();
        $bot = app(TattooWhatsAppBotService::class);
        $send = fn (string $text, string $id) => $bot->handleIncoming($company, $instance,
            $phone.'@s.whatsapp.net', $phone, $text, $id, null);

        $this->assertSame(TattooStudioMenu::TEXT, $send('Quero orçamento', 'menu-1'));
        $this->assertStringContainsString('Como posso te chamar?', (string) $send('1', 'menu-2'));
        $this->assertSame(1, WhatsAppBotConversation::query()->firstOrFail()->menu_choice);

        foreach ([2, 3, 4] as $choice) {
            $other = '55119999999'.$choice;
            $bot->handleIncoming($company, $instance, $other.'@s.whatsapp.net', $other, 'oi', 'start-'.$choice, null);
            $this->assertSame(TattooStudioMenu::HANDOFF, $bot->handleIncoming($company, $instance,
                $other.'@s.whatsapp.net', $other, (string) $choice, 'choice-'.$choice, null));
            $this->assertNull($bot->handleIncoming($company, $instance,
                $other.'@s.whatsapp.net', $other, 'oi', 'after-'.$choice, null));
        }
    }

    public function test_ai_menu_handoff_and_followup_once(): void
    {
        [$company, $instance, $phone] = $this->studio();
        $bot = app(TattooAIConversationService::class);
        $send = fn (string $text, string $id) => $bot->handle($company, $instance,
            $phone.'@s.whatsapp.net', $phone, $text, $id, null);

        $send('Quero orçamento', 'ai-menu-1');
        $this->assertStringContainsString('1 Fazer orçamento', (string) TattooAiMessage::query()
            ->where('provider_message_id', 'reply:ai-menu-1')->value('body'));
        $send('1', 'ai-menu-2');
        $conversation = TattooAiConversation::query()->firstOrFail();
        $this->assertSame(1, $conversation->menu_choice);
        $this->assertNotNull($conversation->last_bot_question_at, 'AI question timestamp missing');

        $this->travelTo(now()->setTime(19, 0)); // 16h em America/Sao_Paulo
        $conversation->update(['last_bot_question_at' => now()->subHours(97),
            'last_interaction_at' => now()->subHours(98)]);
        $this->artisan('tattoo:send-quote-followups')->assertSuccessful();
        $this->assertNotNull($conversation->fresh()->quote_followup_sent_at, 'Follow-up timestamp missing');
        $this->artisan('tattoo:send-quote-followups')->assertSuccessful();
        Http::assertSentCount(3); // menu, pergunta e um retorno

        $other = '5511888888888';
        $bot->handle($company, $instance, $other.'@s.whatsapp.net', $other, 'oi', 'other-1', null);
        $bot->handle($company, $instance, $other.'@s.whatsapp.net', $other, '4', 'other-2', null);
        $this->assertSame(TattooStudioMenu::HANDOFF, TattooAiMessage::query()
            ->where('provider_message_id', 'reply:other-2')->value('body'));
        $this->assertTrue((bool) TattooAiConversation::query()->where('phone_normalized', $other)->value('human_takeover'));
    }

    public function test_one_tattoo_per_professional_per_day_and_cancellation_reopens_day(): void
    {
        $setup = $this->createBookableSetup($this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]));
        ['company' => $company, 'admin' => $admin, 'client' => $client,
            'professional' => $professional, 'localStart' => $start] = $setup;
        $company->schedulingSetting()->update(['tattoo_custom_flow_enabled' => true]);
        $appointments = app(AppointmentService::class);
        $first = $appointments->createInternalAppointment($company, $admin, $client, $professional, null,
            $start, ['service_selection_mode' => 'to_be_defined', 'duration_minutes_snapshot' => 60, 'is_tattoo' => true]);
        try {
            $appointments->createInternalAppointment($company, $admin, $client, $professional, null,
                $start->addHours(2), ['service_selection_mode' => 'to_be_defined', 'duration_minutes_snapshot' => 60, 'is_tattoo' => true]);
            $this->fail('Second tattoo must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('appointments', 1);
        }
        $secondProfessional = Professional::factory()->forCompany($company)->bookable()->active()->create();
        $this->seedStandardWorkingHours($company, $secondProfessional);
        $appointments->createInternalAppointment($company, $admin, $client, $secondProfessional, null,
            $start, ['service_selection_mode' => 'to_be_defined', 'duration_minutes_snapshot' => 60, 'is_tattoo' => true]);
        $first->update(['status' => 'cancelled']);
        $appointments->createInternalAppointment($company, $admin, $client, $professional, null,
            $start->addHours(2), ['service_selection_mode' => 'to_be_defined', 'duration_minutes_snapshot' => 60, 'is_tattoo' => true]);
        $this->assertDatabaseCount('appointments', 3);
    }

    public function test_configuration_targets_only_gods_tattoo(): void
    {
        [$company] = $this->studio();
        Professional::factory()->forCompany($company)->bookable()->active()->create();
        $other = $this->createSchedulingCompany(['name' => 'Outro Estúdio',
            'business_profile' => CompanyProfile::TattooStudio]);
        $this->artisan('tattoo:configure-gods')->assertSuccessful();

        $this->assertTrue((bool) $company->schedulingSetting->fresh()->tattoo_custom_flow_enabled);
        $this->assertSame(7, CompanyBusinessHour::query()->where('company_id', $company->id)->count());
        $this->assertSame(7, ProfessionalWorkingHour::query()->where('company_id', $company->id)->count());
        $this->assertDatabaseMissing('company_scheduling_settings', ['company_id' => $other->id,
            'tattoo_custom_flow_enabled' => true]);
    }

    public function test_legacy_followup_waits_four_days_and_survives_normal_cleanup(): void
    {
        [$company, $instance, $phone] = $this->studio();
        $bot = app(TattooWhatsAppBotService::class);
        $bot->handleIncoming($company, $instance, $phone.'@s.whatsapp.net', $phone, 'oi', 'old-1', null);
        $bot->handleIncoming($company, $instance, $phone.'@s.whatsapp.net', $phone, '1', 'old-2', null);
        $conversation = WhatsAppBotConversation::query()->firstOrFail();
        $this->travelTo(now()->setTime(19, 0));
        $conversation->update(['last_bot_question_at' => now()->subHours(97),
            'last_activity_at' => now()->subHours(98), 'expires_at' => now()->addDays(3)]);
        $this->artisan('whatsapp:cleanup-bot-conversations')->assertSuccessful();
        $this->assertNull($conversation->fresh()->finished_at);
        $this->artisan('tattoo:send-quote-followups')->assertSuccessful();
        $this->assertNotNull($conversation->fresh()->quote_followup_sent_at);
        $this->artisan('tattoo:send-quote-followups')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertStringContainsString('Prazer', (string) $bot->handleIncoming($company, $instance,
            $phone.'@s.whatsapp.net', $phone, 'Ana', 'old-3', null));
    }
}
