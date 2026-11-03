<?php

namespace Tests\Feature\ServiceOrders;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Enums\SaleStatus;
use App\Filament\App\Pages\CompanyProfilePage;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Resources\ServiceOrders\Pages\CreateServiceOrder;
use App\Filament\App\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Filament\App\Resources\ServiceOrders\Pages\ListServiceOrders;
use App\Filament\App\Resources\ServiceOrders\ServiceOrderResource;
use App\Jobs\SendServiceOrderWhatsAppJob;
use App\Models\Client;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderQuote;
use App\Services\Company\CompanyProvisioningService;
use App\Services\Financial\CompanyFinancialSettingService;
use App\Services\ServiceOrders\ServiceOrderMessagingService;
use App\Services\ServiceOrders\ServiceOrderService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use Carbon\Carbon;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class ServiceOrderFlowTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesStockFixtures;

    private function fixture(CompanyRole $role = CompanyRole::CompanyAdmin): array
    {
        $company = $this->createCompany(['business_profile' => CompanyProfile::TechnicalAssistance, 'enabled_modules' => array_map(fn ($module) => $module->value, CompanyProfile::TechnicalAssistance->defaultModules())]);
        $user = $this->createCompanyUser($company, [], $role);
        $client = Client::factory()->forCompany($company)->create(['name' => 'Maria', 'phone' => '11987654321', 'whatsapp_confirmation_opt_in' => true]);
        $service = app(ServiceOrderService::class);
        $order = $service->receive($company, $user, ['client_id' => $client->id, 'device' => 'iPhone 13', 'problem' => 'Tela quebrada', 'notes' => 'NOTA INTERNA SECRETA', 'condition' => 'Tela trincada', 'accessories' => 'Sem acessórios']);

        return [$company, $user, $order, $service];
    }

    private function quote(ServiceOrder $order, $user, array $items = []): ServiceOrderQuote
    {
        return app(ServiceOrderService::class)->quote($order, $user, [
            'items' => $items ?: [['item_type' => 'custom', 'name' => 'Troca de tela', 'quantity' => 1, 'unit_price' => '100.00']],
            'deadline' => '2 dias úteis', 'valid_until' => today()->addDays(7)->toDateString(),
        ]);
    }

    private function approve(ServiceOrder $order, $user): void
    {
        $service = app(ServiceOrderService::class);
        $service->publish($order, $user);
        $service->decide($order->latestQuote(), 'approved');
    }

    public function test_profile_modules_and_numbering_are_integrated(): void
    {
        [$company, $user, $order, $service] = $this->fixture();
        $this->assertTrue($company->isTechnicalAssistance());
        $this->assertContains(CompanyModule::ServiceOrders, CompanyProfile::TechnicalAssistance->defaultModules());
        $this->assertNotContains(CompanyModule::Scheduling, CompanyProfile::TechnicalAssistance->defaultModules());
        $second = $service->receive($company, $user, ['client_id' => $order->client_id, 'device' => 'Samsung', 'problem' => 'Não liga']);
        $this->assertSame(1, $order->number);
        $this->assertSame(2, $second->number);
        $this->assertCount(2, $company->serviceOrders);
    }

    public function test_public_get_never_approves_and_decision_is_once_only(): void
    {
        [, $user, $order, $service] = $this->fixture();
        $quote = $this->quote($order, $user);
        $this->get(route('service-orders.quote', $quote->token))->assertNotFound();
        $service->publish($order, $user);
        $this->get(route('service-orders.quote', $quote->token))->assertOk()->assertSee('Aprovar orçamento')->assertDontSee('NOTA INTERNA SECRETA');
        $this->assertNull($quote->fresh()->decision);
        $this->post(route('service-orders.decide', $quote->token), ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $quote->fresh()->decision);
        $this->assertSame('public_link', $quote->fresh()->decision_source);
        $this->post(route('service-orders.decide', $quote->token), ['decision' => 'declined'])->assertSessionHasErrors('service_order');
        $this->assertSame('approved', $quote->fresh()->decision);
    }

    public function test_expired_and_replaced_quotes_cannot_be_approved(): void
    {
        [, $user, $order, $service] = $this->fixture();
        $old = $this->quote($order, $user);
        $service->publish($order, $user);
        $current = $this->quote($order, $user);
        $service->publish($order, $user);
        $this->post(route('service-orders.decide', $old->token), ['decision' => 'approved'])->assertSessionHasErrors('service_order');
        $this->travel(8)->days();
        $this->post(route('service-orders.decide', $current->token), ['decision' => 'approved'])->assertSessionHasErrors('service_order');
    }

    public function test_refusal_allows_requote_or_return_without_sale(): void
    {
        [, $user, $order, $service] = $this->fixture();
        $this->quote($order, $user);
        $service->publish($order, $user);
        $service->decide($order->latestQuote(), 'declined');
        $this->assertSame('analysis', $order->fresh()->status);
        $this->quote($order, $user);
        $this->assertSame(2, $order->latestQuote()->version);
        $service->transition($order, $user, 'returned');
        $this->assertNull($order->fresh()->sale_id);
    }

    public function test_published_quote_is_immutable(): void
    {
        [, $user, $order, $service] = $this->fixture();
        $this->quote($order, $user);
        $quote = $service->publish($order, $user);
        $this->expectException(ValidationException::class);
        $quote->update(['total' => '1.00']);
    }

    public function test_approval_is_required_before_repair(): void
    {
        [, $user, $order, $service] = $this->fixture();
        $this->quote($order, $user);
        $this->expectException(ValidationException::class);
        $service->transition($order, $user, 'repairing');
    }

    public function test_completion_posts_parts_and_sale_once_and_payment_is_idempotent(): void
    {
        [$company, $user, $order, $service] = $this->fixture();
        $product = $this->createTrackedProduct($company, ['type' => ProductType::Consumable, 'name' => 'Tela']);
        $this->postOpeningBalance($company, $user, [['product_id' => $product->id, 'quantity' => '3', 'unit_cost' => '20']]);
        $this->quote($order, $user, [
            ['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => '60.00'],
            ['item_type' => 'custom', 'name' => 'Mão de obra', 'quantity' => 1, 'unit_price' => '40.00'],
        ]);
        $this->approve($order, $user);
        $this->assertSame('3.0000', $product->fresh()->getCurrentStockQuantity());
        $service->transition($order, $user, 'repairing');
        $service->transition($order, $user, 'ready');
        $service->transition($order, $user, 'ready');
        $this->assertSame('2.0000', $product->fresh()->getCurrentStockQuantity());
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame('100.00', $order->fresh()->sale->final_amount);
        $account = $this->createFinancialAccount($company);
        $data = ['request_key' => (string) Str::uuid(), 'amount' => '100.00', 'method' => PaymentMethod::Pix->value, 'financial_account_id' => $account->id];
        $service->payment($order, $user, $data);
        $service->payment($order, $user, $data);
        $this->assertDatabaseCount('payments', 1);
        $service->transition($order, $user, 'delivered');
        $this->assertSame('delivered', $order->fresh()->status);
        $this->get(route('service-orders.document', $order->receipt_token))->assertOk()->assertSee('Comprovante de entrega')->assertDontSee('NOTA INTERNA SECRETA');
    }

    public function test_insufficient_stock_rolls_back_completion(): void
    {
        [$company, $user, $order, $service] = $this->fixture();
        $product = $this->createTrackedProduct($company, ['type' => ProductType::Sale]);
        $this->quote($order, $user, [['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => '60']]);
        $this->approve($order, $user);
        $service->transition($order, $user, 'repairing');
        try {
            $service->transition($order, $user, 'ready');
            $this->fail('Deveria recusar falta de estoque');
        } catch (ValidationException $e) {
            $this->assertDatabaseCount('sales', 0);
        }
        $this->assertSame('repairing', $order->fresh()->status);
    }

    public function test_outstanding_delivery_requires_manager_confirmation(): void
    {
        [$company, $user, $order, $service] = $this->fixture();
        $this->quote($order, $user);
        $this->approve($order, $user);
        $service->transition($order, $user, 'repairing');
        $service->transition($order, $user, 'ready');
        $employee = $this->createCompanyUser($company, [], CompanyRole::Employee);
        try {
            $service->transition($order, $employee, 'delivered', true);
            $this->fail('Colaborador não pode liberar saldo');
        } catch (ValidationException $e) {
            $this->assertSame('ready', $order->fresh()->status);
        }
        $service->transition($order, $user, 'delivered', true);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_public_documents_and_photos_do_not_expose_internal_data(): void
    {
        [$company, $user, $order] = $this->fixture();
        Storage::fake('local');
        Storage::disk('local')->put('service-orders/private.jpg', 'private photo');
        $photo = $order->photos()->create(['path' => 'service-orders/private.jpg']);
        $this->get(route('service-orders.document', $order->document_token))->assertOk()->assertDontSee('NOTA INTERNA SECRETA')->assertDontSee('private.jpg');
        $this->get(route('service-orders.document', $order->receipt_token))->assertNotFound();
        $this->get(route('service-orders.document', Str::random(64)))->assertNotFound();
        $this->get(route('service-orders.photo', $photo))->assertUnauthorized();
        $other = $this->createCompany();
        $otherUser = $this->createCompanyUser($other);
        $this->actingAs($otherUser)->get(route('service-orders.photo', $photo))->assertForbidden();
        $this->actingAs($user)->get(route('service-orders.photo', $photo))->assertOk();
    }

    public function test_cross_company_clients_and_permissions_are_rejected(): void
    {
        [$company, $user, $order, $service] = $this->fixture();
        $otherClient = Client::factory()->forCompany($this->createCompany())->create();
        $this->authenticateForAppTenant($user, $company);
        Livewire::test(CreateServiceOrder::class)->fillForm(['client_id' => $otherClient->id, 'device' => 'Samsung', 'problem' => 'Não liga'])->call('create')->assertHasFormErrors(['client_id']);
        $company->users()->updateExistingPivot($user->id, ['permissions' => []]);
        $this->get(ServiceOrderResource::getUrl('edit', ['record' => $order]))->assertForbidden();
    }

    public function test_filament_create_list_edit_and_actions_render(): void
    {
        [$company, $user, $order] = $this->fixture();
        $this->authenticateForAppTenant($user, $company);
        Livewire::test(ListServiceOrders::class)->assertSuccessful()->assertCanSeeTableRecords([$order]);
        Livewire::test(CreateServiceOrder::class)->assertSuccessful()->fillForm(['client_id' => $order->client_id, 'device' => 'Moto G', 'problem' => 'Bateria'])->call('create')->assertHasNoFormErrors();
        Livewire::test(EditServiceOrder::class, ['record' => $order->id])->assertSuccessful()->assertFormSet(['notes' => 'NOTA INTERNA SECRETA'])->mountAction('send_order')->assertActionMounted('send_order');
        Livewire::test(CompanyProfilePage::class)->assertSuccessful()->assertDontSee('Agendamento público');
        Livewire::test(Dashboard::class)->assertSuccessful()->assertSee('Consertos em andamento')->assertSee('iPhone 13')->assertDontSee('Agenda de hoje');
    }

    public function test_message_queue_deduplicates_and_provider_result_is_recorded(): void
    {
        Queue::fake();
        [, $user, $order] = $this->fixture();
        $messaging = app(ServiceOrderMessagingService::class);
        $body = $messaging->preview($order, 'order');
        $message = $messaging->queue($order, $user, 'order', $body);
        $same = $messaging->queue($order, $user, 'order', $body);
        $this->assertSame($message->id, $same->id);
        Queue::assertPushed(SendServiceOrderWhatsAppJob::class, 1);
        $instances = $this->mock(CompanyWhatsAppInstanceService::class);
        $instances->shouldReceive('resolvedNameForCompany')->andReturn('test');
        $client = $this->mock(EvolutionApiClient::class);
        $client->shouldReceive('sendText')->once()->andReturn(['key' => ['id' => 'provider-123']]);
        $job = new SendServiceOrderWhatsAppJob($message->id);
        $job->handle($client, $instances);
        $job->handle($client, $instances);
        $this->assertSame('sent', $message->fresh()->status);
        $this->assertSame('provider-123', $message->fresh()->provider_reference);
    }

    public function test_transport_failure_is_not_automatically_resent(): void
    {
        Queue::fake();
        [, $user, $order] = $this->fixture();
        $message = app(ServiceOrderMessagingService::class)->queue($order, $user, 'order', 'Mensagem');
        $instances = $this->mock(CompanyWhatsAppInstanceService::class);
        $instances->shouldReceive('resolvedNameForCompany')->andReturn('test');
        $client = $this->mock(EvolutionApiClient::class);
        $client->shouldReceive('sendText')->once()->andThrow(new \RuntimeException('Timeout'));
        $job = new SendServiceOrderWhatsAppJob($message->id);
        $job->handle($client, $instances);
        $job->handle($client, $instances);
        $this->assertSame('uncertain', $message->fresh()->status);
    }

    public function test_partial_payment_and_reversal_restore_stock_and_preserve_history(): void
    {
        [$company, $user, $order, $service] = $this->fixture();
        app(CompanyFinancialSettingService::class)->getOrCreate($company)->update(['allow_partial_payments' => true]);
        $product = $this->createTrackedProduct($company, ['type' => ProductType::Sale]);
        $this->postOpeningBalance($company, $user, [['product_id' => $product->id, 'quantity' => '3', 'unit_cost' => '20']]);
        $this->quote($order, $user, [['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => '100']]);
        $this->approve($order, $user);
        $service->transition($order, $user, 'repairing');
        $service->transition($order, $user, 'ready');
        $account = $this->createFinancialAccount($company);
        $service->payment($order, $user, ['request_key' => (string) Str::uuid(), 'amount' => '40.00', 'method' => 'pix', 'financial_account_id' => $account->id]);
        $this->assertSame('60.00', $order->fresh()->sale->receivable->outstanding_amount);
        $this->assertSame('40.00', $account->fresh()->getCurrentBalance());
        $employee = $this->createCompanyUser($company, [], CompanyRole::Employee);
        try {
            $service->reverse($order, $employee, 'Teste');
            $this->fail('Colaborador não pode estornar');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $service->reverse($order, $user, 'Cliente desistiu');
        $service->reverse($order, $user, 'Cliente desistiu');
        $this->assertSame('3.0000', $product->fresh()->getCurrentStockQuantity());
        $this->assertSame('0.00', $account->fresh()->getCurrentBalance());
        $this->assertSame('reversed', $order->fresh()->status);
        $this->assertSame(SaleStatus::Cancelled, $order->fresh()->sale->status);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertTrue($order->events()->where('description', 'Conserto estornado: Cliente desistiu')->exists());
    }

    public function test_validity_uses_store_timezone_until_end_of_local_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 01:00:00', 'UTC'));
        [$company, $user, $order, $service] = $this->fixture();
        $company->update(['timezone' => 'America/Sao_Paulo']);
        $quote = $service->quote($order, $user, [
            'items' => [['item_type' => 'custom', 'name' => 'Reparo', 'quantity' => 1, 'unit_price' => '100']],
            'deadline' => '1 dia', 'valid_until' => '2026-11-03',
        ]);
        $service->publish($order, $user);
        $this->assertFalse($quote->expired());
        $this->travelTo(Carbon::parse('2026-11-04 03:00:00', 'UTC'));
        $this->assertTrue($quote->expired());
    }

    public function test_filament_quote_approval_completion_and_payment_actions(): void
    {
        $this->beforeApplicationDestroyed(Repeater::fake());
        [$company, $user, $order] = $this->fixture();
        $this->authenticateForAppTenant($user, $company);
        $page = Livewire::test(EditServiceOrder::class, ['record' => $order->id]);
        $page->callAction('quote', data: [
            'items' => [['item_type' => 'custom', 'name' => 'Reparo', 'quantity' => '1', 'unit_price' => '100.00']],
            'deadline' => '1 dia', 'valid_until' => today()->addDays(7)->toDateString(),
        ])->assertHasNoActionErrors();
        $this->assertNotNull($order->latestQuote());
        $page->callAction('approveInPerson')->assertHasNoActionErrors();
        $this->assertSame('approved', $order->fresh()->status);
        $page->callAction('stage', data: ['status' => 'repairing'])->assertHasNoActionErrors();
        $page->callAction('stage', data: ['status' => 'ready', 'confirmed_parts' => true])->assertHasNoActionErrors();
        $this->assertSame('ready', $order->fresh()->status);
        $account = $this->createFinancialAccount($company);
        $page->callAction('payment', data: ['request_key' => (string) Str::uuid(), 'amount' => '100', 'method' => 'pix', 'financial_account_id' => $account->id])->assertHasNoActionErrors();
        $page->callAction('stage', data: ['status' => 'delivered'])->assertHasNoActionErrors();
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_profile_provisioning_and_dashboard_quick_actions(): void
    {
        $result = app(CompanyProvisioningService::class)->provision([
            'name' => 'Loja celular', 'business_profile' => CompanyProfile::TechnicalAssistance->value,
            'admin_name' => 'Administrador', 'admin_email' => 'assistencia@example.test', 'admin_password' => 'password123',
        ]);
        $company = $result['company'];
        $this->assertTrue($company->isTechnicalAssistance());
        $this->assertContains('service_orders', $company->enabled_modules);
        $this->assertNotContains('scheduling', $company->enabled_modules);
        $this->authenticateForAppTenant($result['user'], $company);
        Livewire::test(Dashboard::class)->assertSuccessful()->assertSee('Receber aparelho')->assertSee('Ver consertos')->assertSee('Vender produto');
    }

    public function test_pending_superseded_quote_is_not_sent_and_disconnect_does_not_block_orders(): void
    {
        Queue::fake();
        [, $user, $order] = $this->fixture();
        $this->quote($order, $user);
        $messaging = app(ServiceOrderMessagingService::class);
        $oldBody = $messaging->preview($order, 'quote');
        $message = $messaging->queue($order, $user, 'quote', $oldBody);
        $this->quote($order, $user);
        $instances = $this->mock(CompanyWhatsAppInstanceService::class);
        $client = $this->mock(EvolutionApiClient::class);
        $client->shouldNotReceive('sendText');
        (new SendServiceOrderWhatsAppJob($message->id))->handle($client, $instances);
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertSame('analysis', $order->fresh()->status);
        try {
            $messaging->queue($order, $user, 'quote', $oldBody);
            $this->fail('Link antigo deveria ser rejeitado');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }
        $orderMessage = $messaging->queue($order, $user, 'order', $messaging->preview($order, 'order'));
        $instances->shouldReceive('resolvedNameForCompany')->andReturn('');
        (new SendServiceOrderWhatsAppJob($orderMessage->id))->handle($client, $instances);
        $this->assertSame('failed', $orderMessage->fresh()->status);
        $this->assertSame('analysis', $order->fresh()->status);
    }

    public function test_retry_recovers_interrupted_job_and_keeps_audit_history(): void
    {
        Queue::fake();
        [, $user, $order] = $this->fixture();
        $messaging = app(ServiceOrderMessagingService::class);
        $message = $messaging->queue($order, $user, 'order', 'Mensagem de recebimento');
        $message->update(['status' => 'sending']);
        $this->travel(6)->minutes();
        $retry = $messaging->retry($order, $user, $message->id);
        $this->assertNotSame($message->id, $retry->id);
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertSame('queued', $retry->status);
        $this->assertTrue($order->events()->where('description', 'Nova tentativa da mensagem #'.$message->id.' confirmada')->exists());
        $again = $messaging->retry($order, $user, $message->id);
        $this->assertSame($retry->id, $again->id);
        $this->assertDatabaseCount('service_order_messages', 2);
    }

    public function test_repair_messages_never_fall_back_to_global_whatsapp_instance(): void
    {
        [$company] = $this->fixture();
        config(['services.evolution.instance' => 'global-instance-must-not-be-used']);
        $instances = app(CompanyWhatsAppInstanceService::class);
        $this->assertSame('', $instances->resolvedNameForCompany($company, allowGlobalFallback: false));
        $this->assertSame('global-instance-must-not-be-used', $instances->resolvedNameForCompany($company));
    }

    public function test_new_version_revokes_prior_approval_and_requires_approval_again(): void
    {
        [, $user, $order, $service] = $this->fixture();
        $old = $this->quote($order, $user);
        $this->approve($order, $user);
        $service->transition($order, $user, 'repairing');
        $this->quote($order, $user);
        $this->assertSame('analysis', $order->fresh()->status);
        $this->assertSame('approved', $old->fresh()->decision);
        $this->get(route('service-orders.quote', $old->token))->assertOk()->assertSee('Esta versão foi substituída');
        $this->expectException(ValidationException::class);
        $service->transition($order, $user, 'ready');
    }
}
