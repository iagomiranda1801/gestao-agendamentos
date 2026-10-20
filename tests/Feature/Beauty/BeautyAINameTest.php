<?php

namespace Tests\Feature\Beauty;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Services\Beauty\BeautyAIConversationService;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class BeautyAINameTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    private string $phone = '5511988887777';

    private function setupBeauty(): array
    {
        $company = $this->createSchedulingCompany([
            'business_profile' => CompanyProfile::Salon,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::WhatsApp->value],
        ]);
        $setup = $this->createBookableSetup($company);
        $setup['service']->update(['name' => 'Corte feminino', 'price' => 80]);
        $this->enablePublicBooking($company, ['beauty_ai_enabled' => true, 'online_auto_confirm' => true,
            'ai_provider' => 'gemini', 'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => 'test-key']);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'beauty-name',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.gemini.api_key' => 'test-key', 'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key']);
        Http::fake(fn () => Http::response(['key' => ['id' => 'sent']]));

        return [$company, $instance];
    }

    private function send($company, $instance, string $text, string $id, ?string $pushName = null): string
    {
        app(BeautyAIConversationService::class)->handle($company, $instance, $this->phone.'@s.whatsapp.net',
            $this->phone, $text, $id, null, false, $pushName);

        return (string) TattooAiMessage::query()->where('provider_message_id', 'reply:'.$id)->value('body');
    }

    public function test_push_name_is_confirmed_instead_of_asking(): void
    {
        [$company, $instance] = $this->setupBeauty();

        $this->assertSame("Oi, Ana! Tudo bem? 😊\n\nÉ Ana mesmo?", $this->send($company, $instance, 'Oi', 'b1', 'Ana'));
        $reply = $this->send($company, $instance, 'sim', 'b2', 'Ana');

        $this->assertStringStartsWith('Prazer, Ana!', $reply);
        $data = TattooAiConversation::query()->firstOrFail()->collected_data;
        $this->assertSame('Ana', $data['name']);
        $this->assertSame('push_name', $data['name_source']);
        $this->assertDatabaseHas('clients', ['company_id' => $company->id, 'name' => 'Ana', 'phone_normalized' => $this->phone]);
    }

    public function test_correction_wins_and_invalid_push_name_asks_normally(): void
    {
        [$company, $instance] = $this->setupBeauty();

        $this->send($company, $instance, 'Oi', 'c1', 'Ana');
        $this->send($company, $instance, 'não, é Bia', 'c2', 'Ana');
        $this->assertSame('Bia', TattooAiConversation::query()->firstOrFail()->collected_data['name']);

        $this->phone = '5511977776666';
        $this->assertSame("Oi, tudo bem? 😊\n\nQual seu nome?", $this->send($company, $instance, 'Oi', 'c3', '💅 Studio'));
    }

    public function test_phrase_client_name_is_replaced_but_valid_one_is_kept(): void
    {
        [$company, $instance] = $this->setupBeauty();
        $client = Client::factory()->forCompany($company)->create(['name' => 'quero fazer as unhas', 'phone' => $this->phone, 'source' => 'whatsapp']);

        $this->send($company, $instance, 'Oi', 'p1');
        $this->send($company, $instance, 'Ana Souza', 'p2');

        $this->assertSame('Ana Souza', $client->fresh()->name);
    }
}
