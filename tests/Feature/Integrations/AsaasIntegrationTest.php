<?php

namespace Tests\Feature\Integrations;

use App\Enums\AsaasBillingType;
use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Enums\ReceivableStatus;
use App\Filament\App\Pages\AsaasSettingsPage;
use App\Models\AsaasCharge;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyAsaasSetting;
use App\Models\CompanyWhatsAppInstance;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Services\Asaas\AsaasChargeService;
use App\Services\Asaas\CompanyAsaasSettingService;
use App\Services\Tattoo\TattooAIConversationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class AsaasIntegrationTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_each_company_uses_its_own_asaas_key(): void
    {
        $first = $this->createSchedulingCompany();
        $second = $this->createSchedulingCompany();
        $this->enableAsaas($first, 'key-company-a');
        $this->enableAsaas($second, 'key-company-b');
        $this->fakeAsaas();

        app(AsaasChargeService::class)->createForReceivable(
            $first,
            $this->openReceivable($first),
            AsaasBillingType::Pix,
            '100.00',
        );
        app(AsaasChargeService::class)->createForReceivable(
            $second,
            $this->openReceivable($second),
            AsaasBillingType::Boleto,
            '80.00',
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v3/payments')
            && ! str_contains($request->url(), 'pixQrCode')
            && $request->hasHeader('access_token', 'key-company-a')
            && ($request->data()['billingType'] ?? null) === 'PIX');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v3/payments')
            && ! str_contains($request->url(), 'pixQrCode')
            && $request->hasHeader('access_token', 'key-company-b')
            && ($request->data()['billingType'] ?? null) === 'BOLETO');

        $stored = DB::table('company_asaas_settings')->pluck('api_key');
        $this->assertFalse($stored->contains(fn (string $value): bool => str_contains($value, 'key-company-a')));
        $this->assertFalse($stored->contains(fn (string $value): bool => str_contains($value, 'key-company-b')));
    }

    public function test_webhook_settles_receivable_once_and_ignores_another_company(): void
    {
        $company = $this->createSchedulingCompany();
        $other = $this->createSchedulingCompany();
        $account = FinancialAccount::factory()->forCompany($company)->create();
        $setting = $this->enableAsaas($company, 'key-company-a', $account);
        $otherSetting = $this->enableAsaas($other, 'key-company-b');
        $receivable = $this->openReceivable($company, '150.00');
        $this->fakeAsaas();

        $charge = app(AsaasChargeService::class)->createForReceivable(
            $company,
            $receivable,
            AsaasBillingType::Pix,
            '150.00',
        );

        $this->postJson(route('webhooks.asaas', ['company' => $company->slug]), $this->webhookBody($charge), [
            'asaas-access-token' => $otherSetting->webhook_token,
        ])->assertUnauthorized();

        $this->postJson(route('webhooks.asaas', ['company' => $other->slug]), $this->webhookBody($charge, 150, 147.5), [
            'asaas-access-token' => $otherSetting->webhook_token,
        ])->assertOk();

        $this->assertSame(ReceivableStatus::Open, $receivable->fresh()->status);
        $this->assertNull($charge->fresh()->settled_at);

        $this->postJson(route('webhooks.asaas', ['company' => $company->slug]), $this->webhookBody($charge, 150, 147.5), [
            'asaas-access-token' => $setting->webhook_token,
        ])->assertOk();

        $receivable->refresh();
        $this->assertSame(ReceivableStatus::Paid, $receivable->status);
        $this->assertSame('0.00', (string) $receivable->outstanding_amount);
        $this->assertSame(1, Payment::query()->where('company_id', $company->id)->count());
        $this->assertSame('2.50', (string) Payment::query()->where('company_id', $company->id)->value('fee_amount'));
        $this->assertSame('147.50', $account->fresh()->getCurrentBalance());

        $this->postJson(route('webhooks.asaas', ['company' => $company->slug]), [
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => [
                'id' => $charge->asaas_payment_id,
                'value' => 150,
                'netValue' => 147.5,
            ],
        ], [
            'asaas-access-token' => $setting->webhook_token,
        ])->assertOk();

        $this->assertSame(1, Payment::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, FinancialTransaction::query()
            ->where('company_id', $company->id)
            ->where('reference_key', 'like', 'customer-payment:%:inbound')
            ->count());
    }

    public function test_whatsapp_deposit_uses_asaas_pix_and_confirms_without_receipt(): void
    {
        [$company, $instance, $phone, $client, $request] = $this->tattooSetup();
        $account = FinancialAccount::factory()->forCompany($company)->create([
            'pix_key' => 'pix@estudio.test',
            'pix_recipient_name' => 'Estudio',
            'is_default_receipt_account' => true,
        ]);
        $setting = $this->enableAsaas($company, 'key-studio', $account);
        $this->quote($company, $request);
        $this->fakeAsaas();

        $this->send($company, $instance, $phone, 'pix', 'pix-1');

        $reply = TattooAiMessage::query()->where('provider_message_id', 'reply:pix-1')->firstOrFail();
        $this->assertStringContainsString('000201PIXkey-studio', $reply->body);
        $this->assertStringNotContainsString('pix@estudio.test', $reply->body);

        $charge = AsaasCharge::query()->where('company_id', $company->id)->firstOrFail();
        $this->postJson(route('webhooks.asaas', ['company' => $company->slug]), $this->webhookBody($charge, 100, 98), [
            'asaas-access-token' => $setting->webhook_token,
        ])->assertOk();

        $conversation = TattooAiConversation::query()->where('phone_normalized', $phone)->firstOrFail();
        $this->assertSame('payment_confirmed', $conversation->status);
        $this->assertSame(0, $conversation->receipts()->count());
        $this->assertSame('98.00', $account->fresh()->getCurrentBalance());

        $this->postJson(route('webhooks.asaas', ['company' => $company->slug]), $this->webhookBody($charge, 100, 98), [
            'asaas-access-token' => $setting->webhook_token,
        ])->assertOk();
        $this->assertSame(1, FinancialTransaction::query()
            ->where('reference_key', $charge->ledgerReferenceKey())
            ->count());
    }

    public function test_whatsapp_deposit_keeps_static_pix_without_asaas(): void
    {
        [$company, $instance, $phone, $client, $request] = $this->tattooSetup();
        FinancialAccount::factory()->forCompany($company)->create([
            'pix_key' => 'pix@estudio.test',
            'pix_recipient_name' => 'Estudio',
            'is_default_receipt_account' => true,
        ]);
        $this->quote($company, $request);
        Http::fake(fn () => Http::response(['key' => ['id' => 'sent']]));

        $this->send($company, $instance, $phone, 'pix', 'pix-static');

        $reply = TattooAiMessage::query()->where('provider_message_id', 'reply:pix-static')->firstOrFail();
        $this->assertStringContainsString('Chave PIX: pix@estudio.test', $reply->body);
        $this->assertStringContainsString('comprovante', $reply->body);
        $this->assertSame(0, AsaasCharge::query()->count());
    }

    public function test_whatsapp_deposit_falls_back_to_static_pix_when_asaas_fails(): void
    {
        [$company, $instance, $phone, $client, $request] = $this->tattooSetup();
        FinancialAccount::factory()->forCompany($company)->create([
            'pix_key' => 'pix@estudio.test',
            'pix_recipient_name' => 'Estudio',
        ]);
        $this->enableAsaas($company, 'key-studio');
        $this->quote($company, $request);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'asaas.com')) {
                return Http::response(['errors' => [['description' => 'chave inválida']]], 401);
            }

            return Http::response(['key' => ['id' => 'sent']]);
        });

        $this->send($company, $instance, $phone, 'pix', 'pix-fallback');

        $reply = TattooAiMessage::query()->where('provider_message_id', 'reply:pix-fallback')->firstOrFail();
        $this->assertStringContainsString('Chave PIX: pix@estudio.test', $reply->body);
        $this->assertSame(0, AsaasCharge::query()->count());
    }

    public function test_asaas_settings_page_is_available_without_finance_module(): void
    {
        $company = $this->createSchedulingCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value],
        ]);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        $this->assertTrue(AsaasSettingsPage::canAccess());
        $this->get(route('filament.app.pages.configuracoes-asaas', ['tenant' => $company]))
            ->assertOk();
    }

    public function test_only_company_admin_can_open_asaas_settings(): void
    {
        $company = $this->createSchedulingCompany();
        $manager = $this->createCompanyUser($company, [], CompanyRole::Manager);
        $this->authenticateForAppTenant($manager, $company);

        $this->assertFalse(AsaasSettingsPage::canAccess());
        $this->get(route('filament.app.pages.configuracoes-asaas', ['tenant' => $company]))
            ->assertForbidden();
    }

    public function test_enabling_asaas_requires_an_api_key(): void
    {
        $company = $this->createSchedulingCompany();

        $this->expectException(ValidationException::class);

        app(CompanyAsaasSettingService::class)->update($company, [
            'environment' => 'sandbox',
            'api_key' => '',
            'is_enabled' => true,
        ]);
    }

    private function enableAsaas(Company $company, string $key, ?FinancialAccount $account = null): CompanyAsaasSetting
    {
        return app(CompanyAsaasSettingService::class)->update($company, [
            'environment' => 'sandbox',
            'api_key' => $key,
            'is_enabled' => true,
            'financial_account_id' => $account?->getKey(),
        ]);
    }

    private function openReceivable(Company $company, string $amount = '100.00'): Receivable
    {
        $client = Client::factory()->forCompany($company)->create([
            'name' => 'Ana Cliente',
            'document' => '52998224725',
        ]);

        return Receivable::factory()->forCompany($company)->create([
            'client_id' => $client->getKey(),
            'original_amount' => $amount,
            'paid_amount' => '0.00',
            'outstanding_amount' => $amount,
            'status' => ReceivableStatus::Open,
        ]);
    }

    /**
     * @return array{0: Company, 1: CompanyWhatsAppInstance, 2: string, 3: Client, 4: TattooRequest}
     */
    private function tattooSetup(): array
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $company->schedulingSetting()->updateOrCreate([], []);
        $instance = new CompanyWhatsAppInstance([
            'name' => 'Principal',
            'instance_name' => 'asaas-test',
            'is_default' => true,
            'status' => 'open',
        ]);
        $instance->company_id = $company->id;
        $instance->save();
        $phone = '5511988887777';
        $client = Client::factory()->forCompany($company)->create(['name' => 'Bia', 'phone' => $phone]);
        $request = new TattooRequest([
            'client_id' => $client->id,
            'description' => 'Rosa',
            'body_placement' => 'Braço',
            'status' => 'accepted',
        ]);
        $request->company_id = $company->id;
        $request->save();
        TattooAiConversation::query()->create([
            'company_id' => $company->id,
            'company_whatsapp_instance_id' => $instance->id,
            'phone_normalized' => $phone,
            'remote_jid' => $phone.'@s.whatsapp.net',
            'client_id' => $client->id,
            'tattoo_request_id' => $request->id,
            'status' => 'waiting_payment_receipt',
        ]);
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
        ]);

        return [$company, $instance, $phone, $client, $request];
    }

    private function quote(Company $company, TattooRequest $request): TattooQuote
    {
        $quote = new TattooQuote([
            'version' => 1,
            'price_type' => 'fixed',
            'amount_min' => 400,
            'deposit_amount' => 100,
            'message_snapshot' => 'Orçamento',
            'accepted_at' => now(),
            'sent_at' => now(),
        ]);
        $quote->company_id = $company->id;
        $quote->tattoo_request_id = $request->id;
        $quote->save();

        return $quote;
    }

    private function send(Company $company, CompanyWhatsAppInstance $instance, string $phone, string $text, string $id): void
    {
        app(TattooAIConversationService::class)->handle(
            $company,
            $instance,
            $phone.'@s.whatsapp.net',
            $phone,
            $text,
            $id,
            null,
        );
    }

    private function fakeAsaas(): void
    {
        Http::fake(function ($request) {
            $token = $request->header('access_token')[0] ?? 'none';
            if (str_contains($request->url(), '/customers')) {
                return Http::response(['id' => 'cus_'.$token]);
            }
            if (str_contains($request->url(), '/pixQrCode')) {
                return Http::response(['payload' => '000201PIX'.$token]);
            }
            if (str_contains($request->url(), '/payments')) {
                return Http::response([
                    'id' => 'pay_'.$token,
                    'invoiceUrl' => 'https://sandbox.asaas.com/i/'.$token,
                    'value' => $request['value'],
                    'netValue' => $request['value'],
                ]);
            }

            return Http::response(['key' => ['id' => 'sent']]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookBody(AsaasCharge $charge, float $value = 100, float $net = 98.5): array
    {
        return [
            'event' => 'PAYMENT_RECEIVED',
            'payment' => [
                'id' => $charge->asaas_payment_id,
                'value' => $value,
                'netValue' => $net,
                'billingType' => 'PIX',
                'paymentDate' => '2026-11-04',
            ],
        ];
    }
}
