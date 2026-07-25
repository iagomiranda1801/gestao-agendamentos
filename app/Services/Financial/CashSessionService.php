<?php

namespace App\Services\Financial;

use App\Enums\CashAdjustmentType;
use App\Enums\CashSessionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashSessionAdjustment;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashSessionService
{
    public function __construct(
        protected FinancialLedgerService $ledgerService,
        protected FinancialAccountService $accountService,
    ) {}

    public function open(
        Company $company,
        CashRegister $cashRegister,
        User $user,
        string $countedAmount,
        ?string $notes = null,
    ): CashSession {
        return DB::transaction(function () use ($company, $cashRegister, $user, $countedAmount, $notes): CashSession {
            $this->ensureRegisterBelongsToCompany($company, $cashRegister);

            $lockedRegister = CashRegister::query()
                ->whereKey($cashRegister->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRegister->isActive()) {
                throw ValidationException::withMessages([
                    'cash_register_id' => 'O caixa precisa estar ativo.',
                ]);
            }

            $this->assertNoOpenSession($lockedRegister);

            $account = FinancialAccount::query()
                ->whereKey($lockedRegister->financial_account_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->accountService->ensureBelongsToCompany($company, $account);

            $expectedOpening = $account->getCurrentBalance();
            $countedOpening = $this->normalizeNonNegativeAmount($countedAmount, 'counted_amount');
            $difference = DecimalMoney::round(bcsub($countedOpening, $expectedOpening, 6));

            $session = new CashSession([
                'status' => CashSessionStatus::Open,
                'opened_at' => now(),
                'expected_opening_amount' => $expectedOpening,
                'counted_opening_amount' => $countedOpening,
                'opening_difference_amount' => $difference,
                'opening_notes' => $notes,
            ]);
            $session->company()->associate($company);
            $session->cashRegister()->associate($lockedRegister);
            $session->opener()->associate($user);
            $session->save();

            return $session->refresh();
        });
    }

    public function close(
        Company $company,
        CashSession $session,
        User $user,
        string $countedAmount,
        ?string $notes = null,
    ): CashSession {
        return DB::transaction(function () use ($company, $session, $user, $countedAmount, $notes): CashSession {
            $lockedSession = CashSession::query()
                ->whereKey($session->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedSession->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => 'Esta sessão de caixa já está fechada.',
                ]);
            }

            $lockedSession->loadMissing('cashRegister');

            $account = FinancialAccount::query()
                ->whereKey($lockedSession->cashRegister->financial_account_id)
                ->lockForUpdate()
                ->firstOrFail();

            $expectedClosing = $account->getCurrentBalance();
            $countedClosing = $this->normalizeNonNegativeAmount($countedAmount, 'counted_amount');
            $difference = DecimalMoney::round(bcsub($countedClosing, $expectedClosing, 6));

            $lockedSession->forceFill([
                'status' => CashSessionStatus::Closed,
                'closed_by' => $user->getKey(),
                'closed_at' => now(),
                'expected_closing_amount' => $expectedClosing,
                'counted_closing_amount' => $countedClosing,
                'closing_difference_amount' => $difference,
                'closing_notes' => $notes,
            ])->save();

            return $lockedSession->refresh();
        });
    }

    public function reinforcement(
        Company $company,
        CashSession $session,
        User $user,
        string $amount,
        string $reason,
    ): CashSessionAdjustment {
        return $this->recordAdjustment(
            $company,
            $session,
            $user,
            CashAdjustmentType::Reinforcement,
            $amount,
            $reason,
        );
    }

    public function withdrawal(
        Company $company,
        CashSession $session,
        User $user,
        string $amount,
        string $reason,
    ): CashSessionAdjustment {
        return $this->recordAdjustment(
            $company,
            $session,
            $user,
            CashAdjustmentType::Withdrawal,
            $amount,
            $reason,
        );
    }

    public function requireOpenSessionForAccount(Company $company, FinancialAccount $account): CashSession
    {
        $session = $this->resolveOpenSessionForAccount($company, $account);

        if ($session === null) {
            throw ValidationException::withMessages([
                'cash_session' => 'É necessário abrir o caixa antes de registrar movimentações em dinheiro.',
            ]);
        }

        return $session;
    }

    public function resolveOpenSessionForAccount(Company $company, FinancialAccount $account): ?CashSession
    {
        $this->accountService->ensureBelongsToCompany($company, $account);

        return CashSession::query()
            ->where('company_id', $company->getKey())
            ->where('status', CashSessionStatus::Open)
            ->whereHas('cashRegister', fn ($query) => $query
                ->where('financial_account_id', $account->getKey()))
            ->first();
    }

    public function resolveCashSessionIdForTransaction(
        Company $company,
        FinancialAccount $account,
        PaymentMethod $method,
    ): ?int {
        $registerExists = CashRegister::query()
            ->where('company_id', $company->getKey())
            ->where('financial_account_id', $account->getKey())
            ->where('is_active', true)
            ->exists();

        if (! $registerExists) {
            return null;
        }

        if ($method === PaymentMethod::Cash) {
            return $this->requireOpenSessionForAccount($company, $account)->getKey();
        }

        return $this->resolveOpenSessionForAccount($company, $account)?->getKey();
    }

    protected function recordAdjustment(
        Company $company,
        CashSession $session,
        User $user,
        CashAdjustmentType $type,
        string $amount,
        string $reason,
    ): CashSessionAdjustment {
        return DB::transaction(function () use ($company, $session, $user, $type, $amount, $reason): CashSessionAdjustment {
            if (trim($reason) === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Informe o motivo do ajuste.',
                ]);
            }

            $lockedSession = CashSession::query()
                ->whereKey($session->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedSession->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => 'Não é possível registrar ajustes em uma sessão fechada.',
                ]);
            }

            $lockedSession->loadMissing('cashRegister');
            $account = FinancialAccount::query()
                ->whereKey($lockedSession->cashRegister->financial_account_id)
                ->lockForUpdate()
                ->firstOrFail();

            $normalizedAmount = $this->normalizePositiveAmount($amount, 'amount');
            $referenceKey = "cash-session-adjustment:{$lockedSession->getKey()}:{$type->value}:".now()->format('YmdHisu');

            $transaction = match ($type) {
                CashAdjustmentType::Reinforcement => $this->ledgerService->postInbound(
                    $company,
                    $account,
                    $normalizedAmount,
                    FinancialTransactionType::CashReinforcement,
                    CarbonImmutable::now(),
                    $reason,
                    $referenceKey,
                    null,
                    $user,
                    $lockedSession->getKey(),
                ),
                CashAdjustmentType::Withdrawal => $this->ledgerService->postOutbound(
                    $company,
                    $account,
                    $normalizedAmount,
                    FinancialTransactionType::CashWithdrawal,
                    CarbonImmutable::now(),
                    $reason,
                    $referenceKey,
                    null,
                    $user,
                    $lockedSession->getKey(),
                ),
            };

            $adjustment = new CashSessionAdjustment([
                'type' => $type,
                'amount' => $normalizedAmount,
                'reason' => $reason,
            ]);
            $adjustment->company()->associate($company);
            $adjustment->cashSession()->associate($lockedSession);
            $adjustment->financialTransaction()->associate($transaction);
            $adjustment->creator()->associate($user);
            $adjustment->save();

            return $adjustment->refresh();
        });
    }

    protected function assertNoOpenSession(CashRegister $cashRegister): void
    {
        $hasOpenSession = CashSession::query()
            ->where('cash_register_id', $cashRegister->getKey())
            ->where('status', CashSessionStatus::Open)
            ->exists();

        if ($hasOpenSession) {
            throw ValidationException::withMessages([
                'cash_register_id' => 'Já existe uma sessão de caixa aberta para este caixa.',
            ]);
        }
    }

    protected function ensureRegisterBelongsToCompany(Company $company, CashRegister $cashRegister): void
    {
        if ((int) $cashRegister->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    protected function normalizeNonNegativeAmount(string $amount, string $field): string
    {
        $normalized = DecimalMoney::round($amount);

        if (bccomp($normalized, '0', 2) < 0) {
            throw ValidationException::withMessages([
                $field => 'Valores negativos não são permitidos.',
            ]);
        }

        return $normalized;
    }

    protected function normalizePositiveAmount(string $amount, string $field): string
    {
        $normalized = DecimalMoney::round($amount);

        if (bccomp($normalized, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                $field => 'O valor deve ser maior que zero.',
            ]);
        }

        return $normalized;
    }
}
