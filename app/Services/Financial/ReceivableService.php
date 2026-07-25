<?php

namespace App\Services\Financial;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\ReceivableStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivableService
{
    public function __construct(
        protected AttendanceFinancialCalculator $calculator,
        protected CompanyFinancialSettingService $financialSettingService,
        protected FinancialLedgerService $ledgerService,
        protected CashSessionService $cashSessionService,
    ) {}

    public function createForAttendance(
        Company $company,
        Attendance $attendance,
        ?User $user = null,
        ?Carbon $dueDate = null,
    ): Receivable {
        return DB::transaction(function () use ($company, $attendance, $user, $dueDate): Receivable {
            $this->ensureAttendanceBelongsToCompany($company, $attendance);

            if ($attendance->receivable()->exists()) {
                throw ValidationException::withMessages([
                    'attendance_id' => 'Este atendimento já possui uma conta a receber.',
                ]);
            }

            $settings = $this->financialSettingService->getOrCreate($company);
            $originalAmount = (string) $attendance->final_amount;

            if (bccomp($originalAmount, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    'final_amount' => 'O valor final do atendimento deve ser maior que zero.',
                ]);
            }

            if ($dueDate === null && $settings->default_payment_due_days > 0) {
                $dueDate = now()->addDays($settings->default_payment_due_days)->startOfDay();
            }

            $receivable = new Receivable([
                'client_id' => $attendance->client_id,
                'original_amount' => $originalAmount,
                'paid_amount' => '0.00',
                'outstanding_amount' => $originalAmount,
                'status' => ReceivableStatus::Open,
                'due_date' => $dueDate,
                'settled_at' => null,
            ]);
            $receivable->company()->associate($company);
            $receivable->attendance()->associate($attendance);

            if ($user !== null) {
                $receivable->creator()->associate($user);
            }

            $receivable->save();

            return $receivable->refresh();
        });
    }

    public function recalculateValues(Receivable $receivable): Receivable
    {
        return DB::transaction(function () use ($receivable): Receivable {
            $locked = $this->lockReceivable($receivable);

            if ($locked->status === ReceivableStatus::Cancelled) {
                return $locked;
            }

            $paidAmount = $this->calculator->sumConfirmedPaymentNetAmounts(
                $locked->payments()->get(),
            );

            $outstandingAmount = $this->calculator->calculateOutstandingAmount(
                (string) $locked->original_amount,
                $paidAmount,
            );

            $locked->forceFill([
                'paid_amount' => $paidAmount,
                'outstanding_amount' => $outstandingAmount,
            ])->save();

            $this->updateStatus($locked->refresh());
            $this->updateSettledAt($locked->refresh());

            return $locked->refresh();
        });
    }

    public function registerPayment(
        Company $company,
        Receivable $receivable,
        PaymentData $data,
        User $user,
    ): Payment {
        return DB::transaction(function () use ($company, $receivable, $data, $user): Payment {
            $lockedReceivable = $this->lockReceivable($receivable);
            $this->ensureReceivableBelongsToCompany($company, $lockedReceivable);

            if ($lockedReceivable->status === ReceivableStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => 'Não é possível registrar pagamentos em uma conta cancelada.',
                ]);
            }

            if ($lockedReceivable->status === ReceivableStatus::Paid) {
                throw ValidationException::withMessages([
                    'status' => 'Esta conta a receber já está quitada.',
                ]);
            }

            $account = FinancialAccount::query()
                ->whereKey($data->financialAccountId)
                ->where('company_id', $company->getKey())
                ->first();

            if ($account === null) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'A conta financeira informada não pertence a esta empresa.',
                ]);
            }

            if (! $account->isActive()) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'A conta financeira precisa estar ativa.',
                ]);
            }

            $settings = $this->financialSettingService->getOrCreate($company);
            $netAmount = $this->calculator->calculateNetAmount($data->amount, $data->feeAmount);
            $outstandingAmount = (string) $lockedReceivable->outstanding_amount;

            if (bccomp($netAmount, $outstandingAmount, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'O pagamento não pode ser maior que o saldo em aberto.',
                ]);
            }

            if (
                ! $settings->allow_partial_payments
                && bccomp($netAmount, $outstandingAmount, 2) !== 0
            ) {
                throw ValidationException::withMessages([
                    'amount' => 'Pagamentos parciais não são permitidos para esta empresa.',
                ]);
            }

            $cashSessionId = $this->cashSessionService->resolveCashSessionIdForTransaction(
                $company,
                $account,
                $data->method,
            );

            $payment = new Payment([
                'amount' => $data->amount,
                'fee_amount' => $data->feeAmount,
                'net_amount' => $netAmount,
                'method' => $data->method,
                'status' => PaymentStatus::Confirmed,
                'paid_at' => $data->paidAt,
                'notes' => $data->notes,
            ]);
            $payment->company()->associate($company);
            $payment->receivable()->associate($lockedReceivable);
            $payment->attendance()->associate($lockedReceivable->attendance);
            $payment->financialAccount()->associate($account);
            $payment->registrar()->associate($user);
            $payment->save();

            $this->ledgerService->postInbound(
                $company,
                $account,
                $data->amount,
                FinancialTransactionType::CustomerPayment,
                CarbonImmutable::parse($data->paidAt),
                'Recebimento de cliente',
                $payment->inboundLedgerReferenceKey(),
                $payment,
                $user,
                $cashSessionId,
            );

            if (bccomp($data->feeAmount, '0', 2) > 0) {
                $this->ledgerService->postOutbound(
                    $company,
                    $account,
                    $data->feeAmount,
                    FinancialTransactionType::PaymentFee,
                    CarbonImmutable::parse($data->paidAt),
                    'Taxa do pagamento',
                    $payment->feeLedgerReferenceKey(),
                    $payment,
                    $user,
                    $cashSessionId,
                );
            }

            $this->recalculateValues($lockedReceivable->refresh());
            $this->recalculateAttendanceFinancials($lockedReceivable->attendance()->firstOrFail());

            return $payment->refresh();
        });
    }

    public function cancelPayment(
        Company $company,
        Payment $payment,
        User $user,
        ?string $reason = null,
    ): Payment {
        return DB::transaction(function () use ($company, $payment, $user, $reason): Payment {
            $lockedPayment = Payment::query()
                ->whereKey($payment->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status !== PaymentStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'status' => 'Somente pagamentos confirmados podem ser cancelados.',
                ]);
            }

            $this->reversePaymentLedgerTransactions($company, $lockedPayment, $user, $reason ?? 'Cancelamento do pagamento');

            $lockedPayment->forceFill([
                'status' => PaymentStatus::Cancelled,
                'cancelled_by' => $user->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $receivable = $lockedPayment->receivable()->firstOrFail();
            $this->recalculateValues($receivable);
            $this->recalculateAttendanceFinancials($receivable->attendance);

            return $lockedPayment->refresh();
        });
    }

    public function cancel(Company $company, Receivable $receivable): Receivable
    {
        return DB::transaction(function () use ($company, $receivable): Receivable {
            $locked = $this->lockReceivable($receivable);
            $this->ensureReceivableBelongsToCompany($company, $locked);

            if ($locked->status === ReceivableStatus::Paid) {
                throw ValidationException::withMessages([
                    'status' => 'Não é possível cancelar uma conta já quitada.',
                ]);
            }

            if (bccomp((string) $locked->paid_amount, '0', 2) > 0) {
                throw ValidationException::withMessages([
                    'status' => 'Não é possível cancelar uma conta com pagamentos registrados.',
                ]);
            }

            $locked->forceFill([
                'status' => ReceivableStatus::Cancelled,
                'outstanding_amount' => '0.00',
                'settled_at' => null,
            ])->save();

            return $locked->refresh();
        });
    }

    public function updateStatus(Receivable $receivable): Receivable
    {
        if ($receivable->status === ReceivableStatus::Cancelled) {
            return $receivable;
        }

        $originalAmount = (string) $receivable->original_amount;
        $paidAmount = (string) $receivable->paid_amount;

        $status = match (true) {
            bccomp($paidAmount, '0', 2) === 0 => ReceivableStatus::Open,
            bccomp($paidAmount, $originalAmount, 2) >= 0 => ReceivableStatus::Paid,
            default => ReceivableStatus::Partial,
        };

        if ($receivable->status !== $status) {
            $receivable->forceFill(['status' => $status])->save();
        }

        return $receivable->refresh();
    }

    public function updateSettledAt(Receivable $receivable): Receivable
    {
        $settledAt = $receivable->status === ReceivableStatus::Paid
            ? ($receivable->settled_at ?? now())
            : null;

        if ($receivable->settled_at != $settledAt) {
            $receivable->forceFill(['settled_at' => $settledAt])->save();
        }

        return $receivable->refresh();
    }

    protected function recalculateAttendanceFinancials(Attendance $attendance): Attendance
    {
        $attendance->refresh()->load('payments');

        $paymentFeeAmount = $this->calculator->sumConfirmedPaymentFees($attendance->payments);
        $operationalResult = $this->calculator->calculateOperationalResult(
            (string) $attendance->final_amount,
            (string) $attendance->actual_material_cost,
            (string) $attendance->commission_amount,
            $paymentFeeAmount,
        );

        $attendance->forceFill([
            'payment_fee_amount' => $paymentFeeAmount,
            'operational_result' => $operationalResult,
        ])->save();

        return $attendance->refresh();
    }

    protected function reversePaymentLedgerTransactions(
        Company $company,
        Payment $payment,
        User $user,
        string $reason,
    ): void {
        if ($payment->financial_account_id === null) {
            return;
        }

        if (bccomp((string) $payment->fee_amount, '0', 2) > 0) {
            $fee = FinancialTransaction::query()
                ->where('company_id', $company->getKey())
                ->where('reference_key', $payment->feeLedgerReferenceKey())
                ->first();

            if ($fee !== null && ! $fee->isReversed()) {
                $this->ledgerService->reverse($company, $fee, $user, $reason);
            }
        }

        $inbound = FinancialTransaction::query()
            ->where('company_id', $company->getKey())
            ->where('reference_key', $payment->inboundLedgerReferenceKey())
            ->first();

        if ($inbound !== null && ! $inbound->isReversed()) {
            $this->ledgerService->reverse($company, $inbound, $user, $reason);
        }
    }

    protected function lockReceivable(Receivable $receivable): Receivable
    {
        return Receivable::query()
            ->whereKey($receivable->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function ensureAttendanceBelongsToCompany(Company $company, Attendance $attendance): void
    {
        if ((int) $attendance->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'attendance_id' => 'O atendimento informado não pertence a esta empresa.',
            ]);
        }
    }

    protected function ensureReceivableBelongsToCompany(Company $company, Receivable $receivable): void
    {
        if ((int) $receivable->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'receivable_id' => 'A conta a receber informada não pertence a esta empresa.',
            ]);
        }
    }
}
