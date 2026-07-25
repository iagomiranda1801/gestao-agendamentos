<?php

namespace App\Services\Financial;

use App\Enums\FinancialTransactionDirection;
use App\Enums\FinancialTransactionType;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountBalance;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialLedgerService
{
    public function postInbound(
        Company $company,
        FinancialAccount $account,
        string $amount,
        FinancialTransactionType $type,
        CarbonInterface $occurredAt,
        string $description,
        ?string $referenceKey = null,
        ?Model $source = null,
        ?User $user = null,
        ?int $cashSessionId = null,
    ): FinancialTransaction {
        return $this->post(
            company: $company,
            account: $account,
            amount: $amount,
            direction: FinancialTransactionDirection::Inbound,
            type: $type,
            occurredAt: $occurredAt,
            description: $description,
            referenceKey: $referenceKey,
            source: $source,
            user: $user,
            cashSessionId: $cashSessionId,
        );
    }

    public function postOutbound(
        Company $company,
        FinancialAccount $account,
        string $amount,
        FinancialTransactionType $type,
        CarbonInterface $occurredAt,
        string $description,
        ?string $referenceKey = null,
        ?Model $source = null,
        ?User $user = null,
        ?int $cashSessionId = null,
    ): FinancialTransaction {
        return $this->post(
            company: $company,
            account: $account,
            amount: $amount,
            direction: FinancialTransactionDirection::Outbound,
            type: $type,
            occurredAt: $occurredAt,
            description: $description,
            referenceKey: $referenceKey,
            source: $source,
            user: $user,
            cashSessionId: $cashSessionId,
        );
    }

    public function reverse(
        Company $company,
        FinancialTransaction $transaction,
        User $user,
        string $reason,
    ): FinancialTransaction {
        return DB::transaction(function () use ($company, $transaction, $user, $reason): FinancialTransaction {
            $transaction = FinancialTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureTransactionBelongsToCompany($company, $transaction);

            if ($transaction->isReversed() || $transaction->reversalTransaction()->exists()) {
                throw ValidationException::withMessages([
                    'transaction' => 'Esta transação financeira já foi estornada.',
                ]);
            }

            $referenceKey = $this->reversalReferenceKey($transaction);

            if ($this->referenceKeyExists($company, $referenceKey)) {
                throw ValidationException::withMessages([
                    'reference_key' => 'Esta transação financeira já foi estornada.',
                ]);
            }

            $inverseDirection = $transaction->direction === FinancialTransactionDirection::Inbound
                ? FinancialTransactionDirection::Outbound
                : FinancialTransactionDirection::Inbound;

            $reversal = $this->post(
                company: $company,
                account: $transaction->financialAccount,
                amount: (string) $transaction->amount,
                direction: $inverseDirection,
                type: FinancialTransactionType::Reversal,
                occurredAt: now()->toImmutable(),
                description: 'Estorno: '.$transaction->description,
                referenceKey: $referenceKey,
                source: $transaction,
                user: $user,
                originalTransaction: $transaction,
                cashSessionId: $transaction->cash_session_id,
            );

            $transaction->update([
                'reversed_at' => now(),
                'reversed_by' => $user->getKey(),
                'reversal_reason' => $reason,
            ]);

            return $reversal;
        });
    }

    protected function post(
        Company $company,
        FinancialAccount $account,
        string $amount,
        FinancialTransactionDirection $direction,
        FinancialTransactionType $type,
        CarbonInterface $occurredAt,
        string $description,
        ?string $referenceKey = null,
        ?Model $source = null,
        ?User $user = null,
        ?FinancialTransaction $originalTransaction = null,
        ?int $cashSessionId = null,
    ): FinancialTransaction {
        return DB::transaction(function () use (
            $company,
            $account,
            $amount,
            $direction,
            $type,
            $occurredAt,
            $description,
            $referenceKey,
            $source,
            $user,
            $originalTransaction,
            $cashSessionId,
        ): FinancialTransaction {
            $this->ensureAccountBelongsToCompany($company, $account);
            $this->assertPositiveAmount($amount);

            $account = FinancialAccount::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'A conta financeira precisa estar ativa.',
                ]);
            }

            if ($referenceKey !== null && $this->referenceKeyExists($company, $referenceKey)) {
                throw ValidationException::withMessages([
                    'reference_key' => 'Esta referência financeira já foi utilizada.',
                ]);
            }

            $balance = $this->lockOrCreateBalance($company, $account);
            $normalizedAmount = DecimalMoney::round($amount);

            if ($direction === FinancialTransactionDirection::Outbound && ! $account->allow_negative_balance) {
                if (bccomp((string) $balance->current_balance, $normalizedAmount, 2) < 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'Saldo insuficiente na conta financeira.',
                    ]);
                }
            }

            $transaction = new FinancialTransaction([
                'direction' => $direction,
                'type' => $type,
                'amount' => $normalizedAmount,
                'occurred_at' => $occurredAt,
                'description' => $description,
                'reference_key' => $referenceKey,
                'original_transaction_id' => $originalTransaction?->getKey(),
                'cash_session_id' => $cashSessionId,
            ]);
            $transaction->company()->associate($company);
            $transaction->financialAccount()->associate($account);

            if ($source !== null) {
                $transaction->source()->associate($source);
            }

            if ($user !== null) {
                $transaction->creator()->associate($user);
            }

            $transaction->save();

            $newBalance = $direction === FinancialTransactionDirection::Inbound
                ? DecimalMoney::round(bcadd((string) $balance->current_balance, $normalizedAmount, 4))
                : DecimalMoney::round(bcsub((string) $balance->current_balance, $normalizedAmount, 4));

            $balance->update([
                'current_balance' => $newBalance,
                'last_transaction_at' => $occurredAt,
            ]);

            return $transaction->refresh();
        });
    }

    protected function lockOrCreateBalance(Company $company, FinancialAccount $account): FinancialAccountBalance
    {
        $balance = FinancialAccountBalance::query()
            ->where('company_id', $company->getKey())
            ->where('financial_account_id', $account->getKey())
            ->lockForUpdate()
            ->first();

        if ($balance !== null) {
            return $balance;
        }

        $balance = new FinancialAccountBalance([
            'current_balance' => '0.00',
            'last_transaction_at' => null,
        ]);
        $balance->company()->associate($company);
        $balance->financialAccount()->associate($account);
        $balance->save();

        return FinancialAccountBalance::query()
            ->whereKey($balance->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function assertPositiveAmount(string $amount): void
    {
        if (bccomp(DecimalMoney::round($amount), '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'O valor da transação deve ser maior que zero.',
            ]);
        }
    }

    protected function referenceKeyExists(Company $company, string $referenceKey): bool
    {
        return FinancialTransaction::query()
            ->where('company_id', $company->getKey())
            ->where('reference_key', $referenceKey)
            ->exists();
    }

    protected function ensureAccountBelongsToCompany(Company $company, FinancialAccount $account): void
    {
        if ((int) $account->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'A conta financeira não pertence a esta empresa.',
            ]);
        }
    }

    protected function ensureTransactionBelongsToCompany(Company $company, FinancialTransaction $transaction): void
    {
        if ((int) $transaction->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    protected function reversalReferenceKey(FinancialTransaction $transaction): string
    {
        return 'financial-transaction:'.$transaction->getKey().':reversal';
    }
}
