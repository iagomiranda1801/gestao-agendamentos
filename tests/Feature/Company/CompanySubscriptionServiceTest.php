<?php

namespace Tests\Feature\Company;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use App\Enums\CompanyRole;
use App\Enums\PlatformInvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Admin\Resources\Companies\Pages\EditCompany;
use App\Filament\Admin\Resources\PlatformInvoices\Pages\ViewPlatformInvoice;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\SubscriptionExpiredPage;
use App\Models\Company;
use App\Models\ModulePrice;
use App\Services\Company\CompanyModuleService;
use App\Services\Company\CompanySubscriptionService;
use Database\Seeders\ModulePriceSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CompanySubscriptionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ModulePriceSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-04 15:00:00', 'UTC'));
    }

    public function test_quote_for_pos_only_uses_sales_price(): void
    {
        $cents = app(CompanySubscriptionService::class)->quoteCents(
            [CompanyModule::Sales],
            BillingInterval::Monthly,
        );

        $this->assertSame(3900, $cents);
    }

    public function test_quote_for_finance_only_uses_annual_price(): void
    {
        $cents = app(CompanySubscriptionService::class)->quoteCents(
            [CompanyModule::Finance],
            BillingInterval::Annual,
        );

        $this->assertSame(39000, $cents);
    }

    public function test_quote_sums_scheduling_and_finance(): void
    {
        $cents = app(CompanySubscriptionService::class)->quoteCents(
            [CompanyModule::Scheduling, CompanyModule::Finance],
            BillingInterval::Monthly,
        );

        $this->assertSame(8800, $cents);
    }

    public function test_legacy_active_company_without_period_end_is_allowed(): void
    {
        $company = Company::factory()->create([
            'subscription_status' => SubscriptionStatus::Active,
            'current_period_end' => null,
        ]);

        $this->assertTrue(app(CompanyModuleService::class)->isAccessAllowed($company));
    }

    public function test_active_company_within_period_is_allowed(): void
    {
        $company = Company::factory()->create([
            'subscription_status' => SubscriptionStatus::Active,
            'current_period_end' => now()->addDays(10),
        ]);

        $this->assertTrue(app(CompanyModuleService::class)->isAccessAllowed($company));
    }

    public function test_active_company_inside_grace_is_allowed(): void
    {
        $company = Company::factory()->create([
            'subscription_status' => SubscriptionStatus::Active,
            'current_period_end' => now()->subDays(2),
        ]);

        $this->assertTrue(app(CompanyModuleService::class)->isAccessAllowed($company));
    }

    public function test_active_company_after_grace_is_blocked(): void
    {
        $company = Company::factory()->create([
            'subscription_status' => SubscriptionStatus::Active,
            'current_period_end' => now()->subDays(4),
        ]);

        $this->assertFalse(app(CompanyModuleService::class)->isAccessAllowed($company));
    }

    public function test_expired_paid_company_is_redirected_to_subscription_page(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Sales->value],
            'subscription_status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_end' => now()->subDays(4),
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(Dashboard::getUrl(['tenant' => $company]))
            ->assertRedirect(SubscriptionExpiredPage::getUrl(['tenant' => $company]));

        $this->get(SubscriptionExpiredPage::getUrl(['tenant' => $company]))
            ->assertOk()
            ->assertSee('Vendas/PDV');
    }

    public function test_issue_invoice_for_pos_only_has_one_item_and_monthly_price(): void
    {
        $company = Company::factory()->create([
            'enabled_modules' => [CompanyModule::Sales->value],
            'subscription_status' => SubscriptionStatus::Expired,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_end' => now()->subMonth(),
        ]);

        $invoice = app(CompanySubscriptionService::class)->issueInvoice($company);

        $this->assertSame('AQ-2026-0001', $invoice->number);
        $this->assertSame(PlatformInvoiceStatus::Open, $invoice->status);
        $this->assertSame(3900, $invoice->amount_cents);
        $this->assertCount(1, $invoice->items);
        $this->assertSame(CompanyModule::Sales->value, $invoice->items[0]['module']);
        $this->assertSame('Vendas/PDV', $invoice->items[0]['label']);
        $this->assertSame(3900, $invoice->items[0]['price_cents']);
        $this->assertTrue($invoice->due_at?->equalTo(now()->addDays(3)));
        $this->assertTrue($invoice->period_start?->equalTo(now()));
        $this->assertTrue($invoice->period_end?->equalTo(now()->addMonth()));
    }

    public function test_issue_invoice_refuses_second_outstanding_invoice(): void
    {
        $company = Company::factory()->create([
            'enabled_modules' => [CompanyModule::Sales->value],
            'billing_interval' => BillingInterval::Monthly,
        ]);

        app(CompanySubscriptionService::class)->issueInvoice($company);

        try {
            app(CompanySubscriptionService::class)->issueInvoice($company);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }
    }

    public function test_admin_issue_and_pay_invoice_actions_renew_subscription(): void
    {
        $admin = $this->createSuperAdmin();
        $company = Company::factory()->create([
            'enabled_modules' => [CompanyModule::Sales->value],
            'subscription_status' => SubscriptionStatus::Expired,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_end' => now()->subMonth(),
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        Livewire::test(EditCompany::class, ['record' => $company->getKey()])
            ->callAction('issueInvoice')
            ->assertHasNoActionErrors();

        $invoice = $company->platformInvoices()->first();
        $this->assertNotNull($invoice);

        Livewire::test(ViewPlatformInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('pay')
            ->assertHasNoActionErrors();

        $company->refresh();

        $this->assertSame(SubscriptionStatus::Active, $company->subscription_status);
        $this->assertSame(3900, $company->quoted_price_cents);
        $this->assertSame(PlatformInvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertTrue($company->current_period_end?->equalTo(now()->addMonth()));
    }

    public function test_paying_invoice_reactivates_expired_company_for_a_month(): void
    {
        $company = Company::factory()->create([
            'enabled_modules' => [CompanyModule::Sales->value],
            'subscription_status' => SubscriptionStatus::Expired,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_end' => now()->subMonth(),
        ]);

        $service = app(CompanySubscriptionService::class);
        $invoice = $service->issueInvoice($company);
        $service->payInvoice($invoice);

        $company->refresh();

        $this->assertSame(SubscriptionStatus::Active, $company->subscription_status);
        $this->assertSame(3900, $company->quoted_price_cents);
        $this->assertTrue($company->current_period_end?->equalTo(now()->addMonth()));
        $this->assertTrue($invoice->fresh()->paid_at?->equalTo(now()));
        $this->assertTrue(app(CompanyModuleService::class)->isAccessAllowed($company));
    }

    public function test_paying_invoice_extends_from_remaining_period_for_annual_interval(): void
    {
        $periodEnd = now()->addDays(10);
        $company = Company::factory()->create([
            'enabled_modules' => [CompanyModule::Finance->value],
            'subscription_status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Annual,
            'current_period_end' => $periodEnd,
        ]);

        $service = app(CompanySubscriptionService::class);
        $invoice = $service->issueInvoice($company);

        $this->assertTrue($invoice->due_at?->equalTo($periodEnd));
        $this->assertSame(39000, $invoice->amount_cents);

        $service->payInvoice($invoice);
        $company->refresh();

        $this->assertTrue($company->current_period_end?->equalTo($periodEnd->copy()->addMonthsNoOverflow(12)));
        $this->assertSame(39000, $company->quoted_price_cents);
    }

    public function test_quoted_snapshot_does_not_change_when_catalog_price_changes(): void
    {
        $company = Company::factory()->create([
            'enabled_modules' => [CompanyModule::Sales->value],
            'billing_interval' => BillingInterval::Monthly,
            'subscription_status' => SubscriptionStatus::Expired,
            'current_period_end' => now()->subMonth(),
        ]);

        $service = app(CompanySubscriptionService::class);
        $service->payInvoice($service->issueInvoice($company));

        ModulePrice::query()
            ->where('module', CompanyModule::Sales->value)
            ->where('interval', BillingInterval::Monthly->value)
            ->update(['price_cents' => 9900]);

        $this->assertSame(3900, $company->fresh()->quoted_price_cents);
        $this->assertSame(9900, $service->quoteCents(
            [CompanyModule::Sales],
            BillingInterval::Monthly,
        ));
    }

    public function test_company_admin_sees_outstanding_invoice_on_subscription_page(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Sales->value],
            'subscription_status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_end' => now()->addDays(5),
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);
        $invoice = app(CompanySubscriptionService::class)->issueInvoice($company);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(SubscriptionExpiredPage::getUrl(['tenant' => $company]))
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('Vendas/PDV')
            ->assertSee('R$ 39,00')
            ->assertSee('Pague');
    }

    public function test_employee_does_not_see_invoices_on_subscription_page(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Sales->value],
            'subscription_status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_end' => now()->subDays(4),
        ]);
        $employee = $this->createCompanyUser($company, [], CompanyRole::Employee);
        $invoice = app(CompanySubscriptionService::class)->issueInvoice($company);

        $this->authenticateForAppTenant($employee, $company);

        $this->get(SubscriptionExpiredPage::getUrl(['tenant' => $company]))
            ->assertOk()
            ->assertDontSee($invoice->number)
            ->assertSee('Fale com o administrador da empresa');
    }

    public function test_expire_command_marks_open_invoices_overdue(): void
    {
        $company = Company::factory()->create([
            'enabled_modules' => [CompanyModule::Sales->value],
            'subscription_status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_end' => now()->addDays(10),
        ]);
        $invoice = app(CompanySubscriptionService::class)->issueInvoice($company);
        $invoice->update(['due_at' => now()->subDay()]);

        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->assertSame(PlatformInvoiceStatus::Overdue, $invoice->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $company->fresh()->subscription_status);
    }

    public function test_issue_due_invoices_command_creates_invoice_before_period_ends(): void
    {
        $dueSoon = Company::factory()->create([
            'enabled_modules' => [CompanyModule::Sales->value],
            'subscription_status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_end' => now()->addDays(5),
        ]);
        $tooFar = Company::factory()->create([
            'enabled_modules' => [CompanyModule::Sales->value],
            'subscription_status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_end' => now()->addDays(20),
        ]);
        $alreadyIssued = Company::factory()->create([
            'enabled_modules' => [CompanyModule::Sales->value],
            'subscription_status' => SubscriptionStatus::Active,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_end' => now()->addDays(3),
        ]);
        app(CompanySubscriptionService::class)->issueInvoice($alreadyIssued);

        $this->artisan('subscriptions:issue-due-invoices')
            ->expectsOutputToContain('Faturas emitidas: 1')
            ->assertSuccessful();

        $this->assertSame(1, $dueSoon->platformInvoices()->count());
        $this->assertSame(0, $tooFar->platformInvoices()->count());
        $this->assertSame(1, $alreadyIssued->platformInvoices()->count());
    }

    public function test_cancelled_invoice_does_not_renew_and_allows_a_new_one(): void
    {
        $company = Company::factory()->create([
            'enabled_modules' => [CompanyModule::Sales->value],
            'subscription_status' => SubscriptionStatus::Expired,
            'billing_interval' => BillingInterval::Monthly,
            'current_period_end' => now()->subMonth(),
        ]);

        $service = app(CompanySubscriptionService::class);
        $invoice = $service->issueInvoice($company);
        $service->cancelInvoice($invoice);

        $this->assertSame(PlatformInvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame(SubscriptionStatus::Expired, $company->fresh()->subscription_status);
        $this->assertNull($company->fresh()->quoted_price_cents);

        $replacement = $service->issueInvoice($company);
        $this->assertSame(PlatformInvoiceStatus::Open, $replacement->status);
        $this->assertSame('AQ-2026-0002', $replacement->number);
    }

    public function test_expire_command_marks_overdue_paid_companies(): void
    {
        $overdue = Company::factory()->create([
            'subscription_status' => SubscriptionStatus::Active,
            'current_period_end' => now()->subDays(4),
        ]);
        $current = Company::factory()->create([
            'subscription_status' => SubscriptionStatus::Active,
            'current_period_end' => now()->addDays(10),
        ]);

        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Expired, $overdue->fresh()->subscription_status);
        $this->assertSame(SubscriptionStatus::Active, $current->fresh()->subscription_status);
    }
}
