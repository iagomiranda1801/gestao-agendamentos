<?php

namespace Tests\Feature\Finance;

use App\Enums\CompanyRole;
use App\Enums\PayableOrigin;
use App\Enums\RecurrenceFrequency;
use App\Models\Payable;
use App\Models\RecurringExpenseTemplate;
use App\Services\Financial\RecurringExpenseService;
use Tests\Support\CreatesFinanceFixtures;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class RecurringExpenseTest extends TestCase
{
    use CreatesFinanceFixtures;
    use CreatesStockFixtures;

    public function test_template_generates_payable_with_recurring_origin(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);
        $category = $this->createOperationalCategory($company);

        $template = RecurringExpenseTemplate::factory()->forCompany($company)->create([
            'expense_category_id' => $category->getKey(),
            'description' => 'Aluguel mensal',
            'amount' => '1500.00',
            'frequency' => RecurrenceFrequency::Monthly,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'next_generation_date' => now()->startOfMonth()->toDateString(),
            'generate_days_in_advance' => 10,
        ]);

        $created = app(RecurringExpenseService::class)->generateDuePayables(now()->startOfDay(), $user);

        $this->assertSame(1, $created);

        $payable = Payable::query()
            ->where('recurring_expense_template_id', $template->getKey())
            ->firstOrFail();

        $this->assertSame(PayableOrigin::Recurring, $payable->origin);
        $this->assertSame('1500.00', (string) $payable->total_amount);
        $this->assertSame(
            "recurring:{$template->getKey()}:{$payable->competence_date->toDateString()}",
            $payable->reference_key,
        );
        $this->assertDatabaseCount('payable_payments', 0);
    }

    public function test_repeated_execution_does_not_duplicate_payables(): void
    {
        $company = $this->createCompany();
        $category = $this->createOperationalCategory($company);
        $competenceDate = now()->startOfMonth()->toDateString();

        $template = RecurringExpenseTemplate::factory()->forCompany($company)->create([
            'expense_category_id' => $category->getKey(),
            'amount' => '300.00',
            'starts_on' => $competenceDate,
            'next_generation_date' => $competenceDate,
            'generate_days_in_advance' => 0,
        ]);

        $service = app(RecurringExpenseService::class);

        $first = $service->generateDuePayables(now()->startOfDay());
        $second = $service->generateDuePayables(now()->startOfDay());

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, Payable::query()->where('company_id', $company->getKey())->count());
        $this->assertSame(
            "recurring:{$template->getKey()}:{$competenceDate}",
            Payable::query()->where('recurring_expense_template_id', $template->getKey())->value('reference_key'),
        );
    }

    public function test_inactive_template_does_not_generate(): void
    {
        $company = $this->createCompany();
        $category = $this->createOperationalCategory($company);

        RecurringExpenseTemplate::factory()->forCompany($company)->inactive()->create([
            'expense_category_id' => $category->getKey(),
            'starts_on' => now()->startOfMonth()->toDateString(),
            'next_generation_date' => now()->startOfMonth()->toDateString(),
        ]);

        $created = app(RecurringExpenseService::class)->generateDuePayables(now()->startOfDay());

        $this->assertSame(0, $created);
    }

    public function test_ends_on_is_respected(): void
    {
        $company = $this->createCompany();
        $category = $this->createOperationalCategory($company);

        RecurringExpenseTemplate::factory()->forCompany($company)->create([
            'expense_category_id' => $category->getKey(),
            'starts_on' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'ends_on' => now()->subMonths(2)->endOfMonth()->toDateString(),
            'next_generation_date' => now()->subMonth()->startOfMonth()->toDateString(),
            'generate_days_in_advance' => 30,
        ]);

        $created = app(RecurringExpenseService::class)->generateDuePayables(now()->startOfDay());

        $this->assertSame(0, $created);
        $this->assertSame(0, Payable::query()->where('company_id', $company->getKey())->count());
    }
}
