<?php

namespace App\Services\Financial;

use App\DataTransferObjects\Financial\PayablePaymentData;
use App\Enums\ExpenseCategoryType;
use App\Enums\PayableOrigin;
use App\Enums\PayableStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockDocumentType;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\RecurringExpenseTemplate;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayableService
{
    public function __construct() {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(
        Company $company,
        ExpenseCategory $category,
        array $data,
        ?User $user = null,
        ?Supplier $supplier = null,
    ): Payable {
        return DB::transaction(function () use ($company, $category, $data, $user, $supplier): Payable {
            $this->ensureCategoryBelongsToCompany($company, $category);

            if (! $category->isActive()) {
                throw ValidationException::withMessages([
                    'expense_category_id' => 'A categoria de despesa precisa estar ativa.',
                ]);
            }

            if ($supplier !== null) {
                $this->ensureSupplierBelongsToCompany($company, $supplier);
            }

            $totalAmount = (string) ($data['total_amount'] ?? '0');

            if (bccomp($totalAmount, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    'total_amount' => 'O valor total precisa ser maior que zero.',
                ]);
            }

            $payable = new Payable([
                'origin' => $data['origin'] ?? PayableOrigin::Manual,
                'status' => PayableStatus::Draft,
                'description' => $data['description'],
                'document_number' => $data['document_number'] ?? null,
                'external_reference' => $data['external_reference'] ?? null,
                'reference_key' => $data['reference_key'] ?? null,
                'issue_date' => $data['issue_date'] ?? now()->toDateString(),
                'competence_date' => $data['competence_date'] ?? now()->toDateString(),
                'total_amount' => $totalAmount,
                'notes' => $data['notes'] ?? null,
                'stock_document_id' => $data['stock_document_id'] ?? null,
                'recurring_expense_template_id' => $data['recurring_expense_template_id'] ?? null,
                'attendance_id' => $data['attendance_id'] ?? null,
                'professional_id' => $data['professional_id'] ?? null,
            ]);
            $payable->company()->associate($company);
            $payable->expenseCategory()->associate($category);

            if ($supplier !== null) {
                $payable->supplier()->associate($supplier);
            }

            if ($user !== null) {
                $payable->creator()->associate($user);
            }

            if ($payable->reference_key !== null) {
                $this->ensureReferenceKeyIsUnique($company, $payable->reference_key);
            }

            $payable->save();

            return $payable->refresh();
        });
    }

    /**
     * @param  list<array{installment_number?: int, due_date: string|Carbon, amount: string, notes?: string|null}>  $installments
     */
    public function createInstallments(Company $company, Payable $payable, array $installments): Collection
    {
        return DB::transaction(function () use ($company, $payable, $installments): Collection {
            $locked = $this->lockPayable($payable);
            $this->ensurePayableBelongsToCompany($company, $locked);

            if ($locked->status !== PayableStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Somente contas em rascunho podem receber parcelas.',
                ]);
            }

            if ($locked->installments()->exists()) {
                throw ValidationException::withMessages([
                    'installments' => 'Esta conta já possui parcelas cadastradas.',
                ]);
            }

            $created = collect();
            $number = 1;

            foreach ($installments as $installmentData) {
                $amount = (string) $installmentData['amount'];

                if (bccomp($amount, '0', 2) <= 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'O valor da parcela precisa ser maior que zero.',
                    ]);
                }

                $installment = new PayableInstallment([
                    'installment_number' => $installmentData['installment_number'] ?? $number,
                    'due_date' => Carbon::parse($installmentData['due_date'])->toDateString(),
                    'original_amount' => $amount,
                    'settled_principal_amount' => '0.00',
                    'outstanding_amount' => $amount,
                    'status' => PayableStatus::Open,
                    'notes' => $installmentData['notes'] ?? null,
                ]);
                $installment->company()->associate($company);
                $installment->payable()->associate($locked);
                $installment->save();

                $created->push($installment->refresh());
                $number++;
            }

            $this->validateInstallmentSumEqualsTotal($locked->refresh()->load('installments'));

            return $created;
        });
    }

    public function launch(Company $company, Payable $payable): Payable
    {
        return DB::transaction(function () use ($company, $payable): Payable {
            $locked = $this->lockPayable($payable);
            $this->ensurePayableBelongsToCompany($company, $locked);

            if ($locked->status !== PayableStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Somente contas em rascunho podem ser lançadas.',
                ]);
            }

            $locked->load('installments');

            if ($locked->installments->isEmpty()) {
                throw ValidationException::withMessages([
                    'installments' => 'Cadastre as parcelas antes de lançar a conta.',
                ]);
            }

            $this->validateInstallmentSumEqualsTotal($locked);

            $locked->forceFill(['status' => PayableStatus::Open])->save();

            return $locked->refresh();
        });
    }

    public function cancel(
        Company $company,
        Payable $payable,
        User $user,
        ?string $reason = null,
    ): Payable {
        return DB::transaction(function () use ($company, $payable, $user, $reason): Payable {
            $locked = $this->lockPayable($payable);
            $this->ensurePayableBelongsToCompany($company, $locked);

            if ($locked->status === PayableStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status === PayableStatus::Paid) {
                throw ValidationException::withMessages([
                    'status' => 'Não é possível cancelar uma conta já quitada.',
                ]);
            }

            $hasConfirmedPayments = $locked->payments()
                ->where('status', 'confirmed')
                ->exists();

            if ($hasConfirmedPayments) {
                throw ValidationException::withMessages([
                    'status' => 'Cancele os pagamentos antes de cancelar a conta.',
                ]);
            }

            $locked->forceFill([
                'status' => PayableStatus::Cancelled,
                'cancelled_by' => $user->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            return $locked->refresh();
        });
    }

    public function cancelManualExpense(
        Company $company,
        Payable $payable,
        User $user,
        string $reason,
    ): Payable {
        return DB::transaction(function () use ($company, $payable, $user, $reason): Payable {
            $locked = $this->lockPayable($payable);
            $this->ensurePayableBelongsToCompany($company, $locked);

            if (trim($reason) === '') {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'Informe o motivo da exclusão da despesa.',
                ]);
            }

            if ($locked->origin !== PayableOrigin::Manual) {
                throw ValidationException::withMessages([
                    'origin' => 'Somente despesas lançadas manualmente podem ser excluídas.',
                ]);
            }

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => 'Esta despesa já foi cancelada.',
                ]);
            }

            $payments = $locked->payments()
                ->where('status', 'confirmed')
                ->orderBy('id')
                ->get();

            foreach ($payments as $payment) {
                app(PayablePaymentService::class)->cancel($company, $payment, $user, $reason);
            }

            $locked->refresh();
            $locked->forceFill([
                'status' => PayableStatus::Cancelled,
                'cancelled_by' => $user->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            return $locked->refresh();
        });
    }

    public function recalculateStatus(Payable $payable): Payable
    {
        if (in_array($payable->status, [PayableStatus::Draft, PayableStatus::Cancelled], true)) {
            return $payable;
        }

        $payable->loadMissing('installments');

        if ($payable->installments->isEmpty()) {
            return $payable;
        }

        $allPaid = $payable->installments->every(
            fn (PayableInstallment $installment): bool => $installment->status === PayableStatus::Paid,
        );
        $anyPaid = $payable->installments->contains(
            fn (PayableInstallment $installment): bool => bccomp((string) $installment->settled_principal_amount, '0', 2) > 0,
        );

        $status = match (true) {
            $allPaid => PayableStatus::Paid,
            $anyPaid => PayableStatus::Partial,
            default => PayableStatus::Open,
        };

        if ($payable->status !== $status) {
            $payable->forceFill(['status' => $status])->save();
        }

        return $payable->refresh();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function createFromStockPurchase(
        Company $company,
        StockDocument $stockDocument,
        ExpenseCategory $category,
        User $user,
        array $options = [],
    ): Payable {
        return DB::transaction(function () use ($company, $stockDocument, $category, $user, $options): Payable {
            $lockedDocument = StockDocument::query()
                ->whereKey($stockDocument->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedDocument->type !== StockDocumentType::Purchase) {
                throw ValidationException::withMessages([
                    'type' => 'Somente compras podem gerar contas a pagar.',
                ]);
            }

            if (! $lockedDocument->isPosted()) {
                throw ValidationException::withMessages([
                    'status' => 'A compra precisa estar lançada.',
                ]);
            }

            $totalAmount = bcadd((string) $lockedDocument->total_amount, '0', 2);

            if (bccomp($totalAmount, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    'total_amount' => 'A compra não possui valor para gerar conta a pagar.',
                ]);
            }

            if (Payable::query()->where('stock_document_id', $lockedDocument->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'stock_document_id' => 'Esta compra já possui uma conta a pagar.',
                ]);
            }

            $referenceKey = "stock-purchase:{$lockedDocument->getKey()}:payable";
            $supplierLabel = $lockedDocument->supplier?->name ?? $lockedDocument->document_number ?? (string) $lockedDocument->getKey();
            $description = "Compra de estoque — {$supplierLabel}";

            $installmentCount = max(1, (int) ($options['installment_count'] ?? 1));
            $firstDueDate = Carbon::parse($options['first_due_date'] ?? now()->addDays(30));
            $intervalDays = max(1, (int) ($options['installment_interval_days'] ?? 30));

            $payable = $this->createDraft($company, $category, [
                'origin' => PayableOrigin::StockPurchase,
                'description' => $description,
                'reference_key' => $referenceKey,
                'issue_date' => $options['issue_date'] ?? now()->toDateString(),
                'competence_date' => $options['competence_date'] ?? now()->toDateString(),
                'total_amount' => $totalAmount,
                'notes' => $options['notes'] ?? null,
                'stock_document_id' => $lockedDocument->getKey(),
            ], $user, $lockedDocument->supplier);

            $installments = $this->buildEqualInstallments(
                $totalAmount,
                $installmentCount,
                $firstDueDate,
                $intervalDays,
            );

            $this->createInstallments($company, $payable, $installments);

            return $this->launch($company, $payable->refresh());
        });
    }

    public function createFromAttendanceCommission(
        Company $company,
        Attendance $attendance,
        User $user,
    ): ?Payable {
        return DB::transaction(function () use ($company, $attendance, $user): ?Payable {
            $lockedAttendance = Attendance::query()
                ->whereKey($attendance->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $commissionAmount = (string) $lockedAttendance->commission_amount;

            if (bccomp($commissionAmount, '0', 2) <= 0) {
                return null;
            }

            $referenceKey = "attendance:{$lockedAttendance->getKey()}:professional-commission";

            $existing = Payable::query()
                ->where('company_id', $company->getKey())
                ->where(function ($query) use ($lockedAttendance, $referenceKey): void {
                    $query
                        ->where('attendance_id', $lockedAttendance->getKey())
                        ->orWhere('reference_key', $referenceKey);
                })
                ->first();

            if ($existing !== null) {
                return $existing->refresh()->load(['installments', 'professional', 'attendance']);
            }

            $category = $this->getOrCreateProfessionalCommissionCategory($company);
            $completedAt = Carbon::parse($lockedAttendance->completed_at ?? now());
            $professionalName = $lockedAttendance->professional_name_snapshot ?: $lockedAttendance->professional?->name;
            $description = "Comissão profissional - Atendimento #{$lockedAttendance->getKey()}";

            if (filled($professionalName)) {
                $description .= " - {$professionalName}";
            }

            $payable = $this->createDraft($company, $category, [
                'origin' => PayableOrigin::ProfessionalCommission,
                'description' => $description,
                'reference_key' => $referenceKey,
                'issue_date' => $completedAt->toDateString(),
                'competence_date' => $completedAt->toDateString(),
                'total_amount' => $commissionAmount,
                'notes' => 'Conta gerada automaticamente a partir da comissão do atendimento.',
                'attendance_id' => $lockedAttendance->getKey(),
                'professional_id' => $lockedAttendance->professional_id,
            ], $user);

            $this->createInstallments($company, $payable, [[
                'due_date' => $completedAt->toDateString(),
                'amount' => $commissionAmount,
            ]]);

            return $this->launch($company, $payable->refresh())
                ->load(['installments', 'professional', 'attendance']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createQuickExpense(
        Company $company,
        ExpenseCategory $category,
        array $data,
        User $user,
        ?Supplier $supplier = null,
        ?FinancialAccount $account = null,
    ): Payable {
        return DB::transaction(function () use ($company, $category, $data, $user, $supplier, $account): Payable {
            $paidNow = (bool) ($data['paid_now'] ?? false);
            $totalAmount = (string) $data['total_amount'];
            $dueDate = Carbon::parse($data['due_date'] ?? now())->toDateString();

            $payable = $this->createDraft($company, $category, [
                'origin' => PayableOrigin::Manual,
                'description' => $data['description'],
                'issue_date' => $data['issue_date'] ?? now()->toDateString(),
                'competence_date' => $data['competence_date'] ?? now()->toDateString(),
                'total_amount' => $totalAmount,
                'notes' => $data['notes'] ?? null,
            ], $user, $supplier);

            $this->createInstallments($company, $payable, [[
                'due_date' => $dueDate,
                'amount' => $totalAmount,
            ]]);

            $payable = $this->launch($company, $payable->refresh());

            if (! $paidNow) {
                return $payable;
            }

            if ($account === null) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'Informe a conta financeira para despesas pagas agora.',
                ]);
            }

            $installment = $payable->installments()->firstOrFail();

            app(PayablePaymentService::class)->record(
                $company,
                $installment,
                $account,
                $user,
                new PayablePaymentData(
                    settledPrincipalAmount: $totalAmount,
                    method: $data['method'] ?? PaymentMethod::Pix,
                    paidAt: Carbon::parse($data['paid_at'] ?? now()),
                    interestAmount: (string) ($data['interest_amount'] ?? '0.00'),
                    penaltyAmount: (string) ($data['penalty_amount'] ?? '0.00'),
                    feeAmount: (string) ($data['fee_amount'] ?? '0.00'),
                    discountAmount: (string) ($data['discount_amount'] ?? '0.00'),
                    reference: $data['reference'] ?? null,
                    notes: $data['notes'] ?? null,
                ),
            );

            return $payable->refresh()->load(['installments', 'payments']);
        });
    }

    public function validateInstallmentSumEqualsTotal(Payable $payable): void
    {
        $payable->loadMissing('installments');

        $sum = '0.00';

        foreach ($payable->installments as $installment) {
            $sum = bcadd($sum, (string) $installment->original_amount, 2);
        }

        if (bccomp($sum, (string) $payable->total_amount, 2) !== 0) {
            throw ValidationException::withMessages([
                'installments' => 'A soma das parcelas precisa ser igual ao valor total da conta.',
            ]);
        }
    }

    public function createFromRecurringTemplate(
        Company $company,
        RecurringExpenseTemplate $template,
        Carbon $competenceDate,
        Carbon $dueDate,
        ?User $user = null,
    ): Payable {
        return DB::transaction(function () use ($company, $template, $competenceDate, $dueDate, $user): Payable {
            $this->ensureTemplateBelongsToCompany($company, $template);

            $referenceKey = "recurring:{$template->getKey()}:{$competenceDate->toDateString()}";

            $existing = Payable::query()
                ->where('company_id', $company->getKey())
                ->where('reference_key', $referenceKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $payable = $this->createDraft($company, $template->expenseCategory, [
                'origin' => PayableOrigin::Recurring,
                'description' => $template->description,
                'reference_key' => $referenceKey,
                'issue_date' => $competenceDate->toDateString(),
                'competence_date' => $competenceDate->toDateString(),
                'total_amount' => (string) $template->amount,
                'notes' => $template->notes,
                'recurring_expense_template_id' => $template->getKey(),
            ], $user, $template->supplier);

            $this->createInstallments($company, $payable, [[
                'due_date' => $dueDate->toDateString(),
                'amount' => (string) $template->amount,
            ]]);

            return $this->launch($company, $payable->refresh());
        });
    }

    /**
     * @return list<array{due_date: string, amount: string}>
     */
    protected function buildEqualInstallments(
        string $totalAmount,
        int $count,
        Carbon $firstDueDate,
        int $intervalDays,
    ): array {
        $baseAmount = bcdiv($totalAmount, (string) $count, 2);
        $installments = [];
        $allocated = '0.00';

        for ($i = 0; $i < $count; $i++) {
            $amount = ($i === $count - 1)
                ? bcsub($totalAmount, $allocated, 2)
                : $baseAmount;

            $installments[] = [
                'due_date' => $firstDueDate->copy()->addDays($intervalDays * $i)->toDateString(),
                'amount' => $amount,
            ];

            $allocated = bcadd($allocated, $amount, 2);
        }

        return $installments;
    }

    protected function lockPayable(Payable $payable): Payable
    {
        return Payable::query()
            ->whereKey($payable->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function ensurePayableBelongsToCompany(Company $company, Payable $payable): void
    {
        if ((int) $payable->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'payable_id' => 'A conta a pagar informada não pertence a esta empresa.',
            ]);
        }
    }

    protected function ensureCategoryBelongsToCompany(Company $company, ExpenseCategory $category): void
    {
        if ((int) $category->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'expense_category_id' => 'A categoria informada não pertence a esta empresa.',
            ]);
        }
    }

    protected function ensureSupplierBelongsToCompany(Company $company, Supplier $supplier): void
    {
        if ((int) $supplier->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'supplier_id' => 'O fornecedor informado não pertence a esta empresa.',
            ]);
        }
    }

    protected function ensureTemplateBelongsToCompany(Company $company, RecurringExpenseTemplate $template): void
    {
        if ((int) $template->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'recurring_expense_template_id' => 'O template informado não pertence a esta empresa.',
            ]);
        }
    }

    protected function ensureReferenceKeyIsUnique(Company $company, string $referenceKey): void
    {
        if (
            Payable::query()
                ->where('company_id', $company->getKey())
                ->where('reference_key', $referenceKey)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'reference_key' => 'Já existe uma conta a pagar com esta referência.',
            ]);
        }
    }

    protected function getOrCreateProfessionalCommissionCategory(Company $company): ExpenseCategory
    {
        $category = ExpenseCategory::query()
            ->where('company_id', $company->getKey())
            ->where('code', 'professional-commissions')
            ->first();

        $category ??= ExpenseCategory::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Comissões profissionais')
            ->whereNull('code')
            ->first();

        if ($category !== null) {
            $category->forceFill([
                'name' => 'Comissões profissionais',
                'code' => 'professional-commissions',
                'type' => ExpenseCategoryType::Personnel,
                'affects_managerial_result' => false,
                'is_system' => true,
                'is_active' => true,
            ])->save();

            return $category->refresh();
        }

        $category = new ExpenseCategory([
            'name' => 'Comissões profissionais',
            'code' => 'professional-commissions',
            'type' => ExpenseCategoryType::Personnel,
            'description' => 'Comissões geradas automaticamente na finalização de atendimentos.',
            'affects_managerial_result' => false,
            'is_system' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $category->company()->associate($company);
        $category->save();

        return $category->refresh();
    }
}
