<?php

namespace App\Services\Financial;

use App\DataTransferObjects\Financial\FinancialTransferData;
use App\Enums\FinancialTransactionType;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\FinancialTransfer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialTransferService
{
    public function __construct(
        protected FinancialLedgerService $ledgerService,
        protected FinancialAccountService $accountService,
    ) {}

    public function transfer(
        Company $company,
        User $user,
        FinancialTransferData $data,
    ): FinancialTransfer {
        return DB::transaction(function () use ($company, $user, $data): FinancialTransfer {
            if ((int) $data->fromFinancialAccountId === (int) $data->toFinancialAccountId) {
                throw ValidationException::withMessages([
                    'to_financial_account_id' => 'A conta de origem e destino precisam ser diferentes.',
                ]);
            }

            [$fromAccount, $toAccount] = $this->lockAccountsInOrder(
                $company,
                $data->fromFinancialAccountId,
                $data->toFinancialAccountId,
            );

            if (! $fromAccount->isActive() || ! $toAccount->isActive()) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'As contas financeiras precisam estar ativas.',
                ]);
            }

            if (bccomp($data->amount, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'O valor da transferência deve ser maior que zero.',
                ]);
            }

            if (bccomp($data->feeAmount, '0', 2) < 0) {
                throw ValidationException::withMessages([
                    'fee_amount' => 'A taxa não pode ser negativa.',
                ]);
            }

            $transfer = new FinancialTransfer([
                'amount' => $data->amount,
                'fee_amount' => $data->feeAmount,
                'occurred_at' => $data->occurredAt,
                'description' => $data->description,
                'reference_key' => $data->referenceKey,
            ]);
            $transfer->company()->associate($company);
            $transfer->fromFinancialAccount()->associate($fromAccount);
            $transfer->toFinancialAccount()->associate($toAccount);
            $transfer->creator()->associate($user);
            $transfer->save();

            $this->ledgerService->postOutbound(
                $company,
                $fromAccount,
                $data->amount,
                FinancialTransactionType::TransferOut,
                CarbonImmutable::parse($data->occurredAt),
                $data->description,
                $transfer->outboundReferenceKey(),
                $transfer,
                $user,
            );

            $this->ledgerService->postInbound(
                $company,
                $toAccount,
                $data->amount,
                FinancialTransactionType::TransferIn,
                CarbonImmutable::parse($data->occurredAt),
                $data->description,
                $transfer->inboundReferenceKey(),
                $transfer,
                $user,
            );

            if (bccomp($data->feeAmount, '0', 2) > 0) {
                $this->ledgerService->postOutbound(
                    $company,
                    $fromAccount,
                    $data->feeAmount,
                    FinancialTransactionType::TransferOut,
                    CarbonImmutable::parse($data->occurredAt),
                    'Taxa da transferência: '.$data->description,
                    $transfer->feeReferenceKey(),
                    $transfer,
                    $user,
                );
            }

            return $transfer->refresh();
        });
    }

    public function reverse(
        Company $company,
        FinancialTransfer $transfer,
        User $user,
        string $reason,
    ): FinancialTransfer {
        return DB::transaction(function () use ($company, $transfer, $user, $reason): FinancialTransfer {
            if (trim($reason) === '') {
                throw ValidationException::withMessages([
                    'reversal_reason' => 'Informe o motivo do estorno.',
                ]);
            }

            $lockedTransfer = FinancialTransfer::query()
                ->whereKey($transfer->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTransfer->isReversed()) {
                throw ValidationException::withMessages([
                    'status' => 'Esta transferência já foi estornada.',
                ]);
            }

            $this->reverseTransactionByReference($company, $lockedTransfer->outboundReferenceKey(), $user, $reason);
            $this->reverseTransactionByReference($company, $lockedTransfer->inboundReferenceKey(), $user, $reason);

            if (bccomp((string) $lockedTransfer->fee_amount, '0', 2) > 0) {
                $this->reverseTransactionByReference($company, $lockedTransfer->feeReferenceKey(), $user, $reason);
            }

            $lockedTransfer->forceFill([
                'reversed_by' => $user->getKey(),
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();

            return $lockedTransfer->refresh();
        });
    }

    /**
     * @return array{0: FinancialAccount, 1: FinancialAccount}
     */
    protected function lockAccountsInOrder(Company $company, int $firstId, int $secondId): array
    {
        $ids = [$firstId, $secondId];
        sort($ids);

        $accounts = [];

        foreach ($ids as $id) {
            $account = FinancialAccount::query()
                ->whereKey($id)
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                throw ValidationException::withMessages([
                    'financial_account_id' => 'A conta financeira informada não pertence a esta empresa.',
                ]);
            }

            $accounts[$account->getKey()] = $account;
        }

        return [
            $accounts[$firstId],
            $accounts[$secondId],
        ];
    }

    protected function reverseTransactionByReference(
        Company $company,
        string $referenceKey,
        User $user,
        string $reason,
    ): void {
        $transaction = FinancialTransaction::query()
            ->where('company_id', $company->getKey())
            ->where('reference_key', $referenceKey)
            ->firstOrFail();

        $this->ledgerService->reverse($company, $transaction, $user, $reason);
    }
}
