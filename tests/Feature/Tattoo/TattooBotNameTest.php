<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyProfile;
use App\Models\CompanyWhatsAppInstance;
use App\Services\Tattoo\TattooWhatsAppBotService;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class TattooBotNameTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_bot_does_not_use_a_sentence_as_the_client_name(): void
    {
        $send = $this->bot('tattoo-name-1', '5511966660001');

        $send('oi', 'n1');
        $reply = (string) $send('Quero fazer uma tatuagem de leão no braço', 'n2');
        $this->assertStringNotContainsString('Prazer', $reply);
        $this->assertStringContainsString('como posso te chamar', $reply);

        $reply = (string) $send('meu nome é ana', 'n3');
        $this->assertStringContainsString('Prazer, Ana!', $reply);
        $this->assertStringContainsString('parte do corpo', $reply);
    }

    public function test_bot_keeps_going_without_a_name_after_second_try(): void
    {
        $send = $this->bot('tattoo-name-2', '5511966660002');

        $send('oi', 'm1');
        $send('quanto custa?', 'm2');
        $reply = (string) $send('quanto custa uma pequena?', 'm3');
        $this->assertStringNotContainsString('Prazer', $reply);
        $this->assertStringContainsString('Me conta como', $reply);
    }

    public function test_plain_names_still_work(): void
    {
        $send = $this->bot('tattoo-name-3', '5511966660003');

        $send('oi', 'p1');
        $this->assertStringContainsString('Prazer, Ana Silva!', (string) $send('Ana Silva', 'p2'));
    }

    protected function bot(string $instanceName, string $phone): \Closure
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => $instanceName, 'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        $bot = app(TattooWhatsAppBotService::class);

        return fn (string $body, string $id) => $bot->handleIncoming($company, $instance, $phone.'@s.whatsapp.net', $phone, $body, $id, null);
    }
}
