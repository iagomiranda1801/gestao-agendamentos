<?php

namespace App\Services\Financial;

use App\DataTransferObjects\Financial\PayablePaymentData;
use App\Enums\FinancialTransactionType;
use App\Enums\PayablePaymentStatus;
use App\Enums\PayableStatus;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\PayablePayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayablePaymentService
{
    public function __construct(
        protected PayableInstallmentCalculator $calculator,
        protected FinancialLedgerService $ledgerService,
    ) {}

    public function record(
        Company $company,
        PayableInstallment $installment,
        FinancialAccount $account,
        User $user,
        PayablePaymentData $data,
    ): PayablePayment {
        return DB::transaction(function () use ($company, $installment, $account, $user, $data): PayablePayment {
            $lockedInstallment = PayableInstallment::query()
                ->whereKey($installment->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $payable = Payable::query()
                ->whereKey($lockedInstallment->payable_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payable->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => 'Não é possível registrar pagamentos em uma conta cancelada.',
                ]);
            }

            if ($payable->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => 'Lance a conta antes de registrar pagamentos.',
                ]);
            }

            if ($lockedInstallment->status === PayableStatus::Paid) {
                throw ValidationException::withMessages([
                    'status' => 'Esta parcela já está quitada.',
                ]);
            }

            $this->ensureAccountBelongsToCompany($company, $account);

            if (! $account->isActive()) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'A conta financeira precisa estar ativa.',
                ]);
            }

            $this->validatePaymentAmounts($data, $lockedInstallment);

            $cashOutflow = $this->calculator->calculateCashOutflow(
                $data->settledPrincipalAmount,
                $data->interestAmount,
                $data->penaltyAmount,
                $data->feeAmount,
                $data->discountAmount,
            );

            if (bccomp($cashOutflow, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    'discount_amount' => 'O desconto não pode ser maior que o valor total do pagamento.',
                ]);
            }

            $payment = new PayablePayment([
                'method' => $data->method,
                'status' => PayablePaymentStatus::Confirmed,
                'settled_principal_amount' => $data->settledPrincipalAmount,
                'interest_amount' => $data->interestAmount,
                'penalty_amount' => $data->penaltyAmount,
                'fee_amount' => $data->feeAmount,
                'discount_amount' => $data->discountAmount,
                'cash_outflow_amount' => $cashOutflow,
                'paid_at' => $data->paidAt,
                'reference' => $data->reference,
                'notes' => $data->notes,
            ]);
            $payment->company()->associate($company);
            $payment->payable()->associate($payable);
            $payment->installment()->associate($lockedInstallment);
            $payment->financialAccount()->associate($account);
            $payment->creator()->associate($user);
            $payment->save();

            $cashSessionId = app(CashSessionService::class)->resolveCashSessionIdForTransaction(
                $company,
                $account,
                $data->method,
            );

            $this->ledgerService->postOutbound(
                $company,
                $account,
                $cashOutflow,
                FinancialTransactionType::ExpensePayment,
                CarbonImmutable::parse($data->paidAt),
                "Pagamento: {$payable->description}",
                $payment->ledgerReferenceKey(),
                $payment,
                $user,
                $cashSessionId,
            );

            $this->recalculateInstallment($lockedInstallment->refresh());
            app(PayableService::class)->recalculateStatus($payable->refresh());

            return $payment->refresh();
        });
    }

    public function cancel(
        Company $company,
        PayablePayment $payment,
        User $user,
        string $reason,
    ): PayablePayment {
        return DB::transaction(function () use ($company, $payment, $user, $reason): PayablePayment {
            if (trim($reason) === '') {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'Informe o motivo do cancelamento.',
                ]);
            }

            $lockedPayment = PayablePayment::query()
                ->whereKey($payment->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedPayment->isConfirmed()) {
                throw ValidationException::withMessages([
                    'status' => 'Somente pagamentos confirmados podem ser cancelados.',
                ]);
            }

            $transaction = FinancialTransaction::query()
                ->where('company_id', $company->getKey())
                ->where('reference_key', $lockedPayment->ledgerReferenceKey())
                ->firstOrFail();

            $this->ledgerService->reverse($company, $transaction, $user, $reason);

            $lockedPayment->forceFill([
                'status' => PayablePaymentStatus::Cancelled,
                'cancelled_by' => $user->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $installment = PayableInstallment::query()
                ->whereKey($lockedPayment->payable_installment_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->recalculateInstallment($installment);
            app(PayableService::class)->recalculateStatus($lockedPayment->payable()->firstOrFail()->refresh());

            return $lockedPayment->refresh();
        });
    }

    protected function recalculateInstallment(PayableInstallment $installment): PayableInstallment
    {
        $payments = $installment->payments()->get();
        $settledPrincipal = $this->calculator->sumConfirmedSettledPrincipal($payments);
        $outstanding = $this->calculator->calculateOutstandingAmount(
            (string) $installment->original_amount,
            $settledPrincipal,
        );

        $status = match (true) {
            bccomp($settledPrincipal, '0', 2) === 0 => PayableStatus::Open,
            bccomp($outstanding, '0', 2) <= 0 => PayableStatus::Paid,
            default => PayableStatus::Partial,
        };

        $settledAt = $status === PayableStatus::Paid
            ? ($installment->settled_at ?? now())
            : null;

        if (bccomp($outstanding, '0', 2) < 0) {
            $outstanding = '0.00';
        }

        $installment->forceFill([
            'settled_principal_amount' => $settledPrincipal,
            'outstanding_amount' => $outstanding,
            'status' => $status,
            'settled_at' => $settledAt,
        ])->save();

        return $installment->refresh();
    }

    protected function validatePaymentAmounts(PayablePaymentData $data, PayableInstallment $installment): void
    {
        foreach ([
            'settled_principal_amount' => $data->settledPrincipalAmount,
            'interest_amount' => $data->interestAmount,
            'penalty_amount' => $data->penaltyAmount,
            'fee_amount' => $data->feeAmount,
            'discount_amount' => $data->discountAmount,
        ] as $field => $value) {
            if (bccomp($value, '0', 2) < 0) {
                throw ValidationException::withMessages([
                    $field => 'Valores negativos não são permitidos.',
                ]);
            }
        }

        if (bccomp($data->settledPrincipalAmount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'settled_principal_amount' => 'O principal liquidado precisa ser maior que zero.',
            ]);
        }

        $outstanding = (string) $installment->outstanding_amount;

        if (bccomp($data->settledPrincipalAmount, $outstanding, 2) > 0) {
            throw ValidationException::withMessages([
                'settled_principal_amount' => 'O pagamento não pode ser maior que o saldo da parcela.',
            ]);
        }
    }

    protected function ensureAccountBelongsToCompany(Company $company, FinancialAccount $account): void
    {
        if ((int) $account->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'A conta financeira informada não pertence a esta empresa.',
            ]);
        }
    }
}
