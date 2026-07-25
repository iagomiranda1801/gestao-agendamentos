<?php

namespace App\Services\Financial;

use App\Enums\CashSessionStatus;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class FinancialAccountService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): FinancialAccount
    {
        return DB::transaction(function () use ($company, $data): FinancialAccount {
            $payload = $this->preparePayload($data);

            $this->assertUniqueName($company, $payload['name']);

            $account = new FinancialAccount($payload);
            $account->company()->associate($company);
            $account->save();

            $balance = new FinancialAccountBalance([
                'current_balance' => '0.00',
                'last_transaction_at' => null,
            ]);
            $balance->company()->associate($company);
            $balance->financialAccount()->associate($account);
            $balance->save();

            if ($account->is_default_receipt_account) {
                $this->clearOtherDefaultReceiptAccounts($company, $account);
            }

            if ($account->is_default_expense_account) {
                $this->clearOtherDefaultExpenseAccounts($company, $account);
            }

            return $account->refresh()->load('balance');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, FinancialAccount $account, array $data): FinancialAccount
    {
        return DB::transaction(function () use ($company, $account, $data): FinancialAccount {
            $this->ensureBelongsToCompany($company, $account);

            $payload = $this->preparePayload($data);

            if (array_key_exists('name', $payload)) {
                $this->assertUniqueName($company, $payload['name'], $account);
            }

            $account->fill($payload);
            $account->save();

            if ($account->is_default_receipt_account) {
                $this->clearOtherDefaultReceiptAccounts($company, $account);
            }

            if ($account->is_default_expense_account) {
                $this->clearOtherDefaultExpenseAccounts($company, $account);
            }

            return $account->refresh()->load('balance');
        });
    }

    public function activate(Company $company, FinancialAccount $account): FinancialAccount
    {
        $this->ensureBelongsToCompany($company, $account);

        $account->update(['is_active' => true]);

        return $account->refresh()->load('balance');
    }

    public function deactivate(Company $company, FinancialAccount $account): FinancialAccount
    {
        return DB::transaction(function () use ($company, $account): FinancialAccount {
            $this->ensureBelongsToCompany($company, $account);
            $this->assertNoOpenCashSession($account);

            $account->update(['is_active' => false]);

            return $account->refresh()->load('balance');
        });
    }

    public function setDefaultReceiptAccount(Company $company, FinancialAccount $account): FinancialAccount
    {
        return DB::transaction(function () use ($company, $account): FinancialAccount {
            $this->ensureBelongsToCompany($company, $account);

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => 'Somente contas ativas podem ser definidas como padrão.',
                ]);
            }

            $account->update(['is_default_receipt_account' => true]);
            $this->clearOtherDefaultReceiptAccounts($company, $account);

            return $account->refresh()->load('balance');
        });
    }

    public function setDefaultExpenseAccount(Company $company, FinancialAccount $account): FinancialAccount
    {
        return DB::transaction(function () use ($company, $account): FinancialAccount {
            $this->ensureBelongsToCompany($company, $account);

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => 'Somente contas ativas podem ser definidas como padrão.',
                ]);
            }

            $account->update(['is_default_expense_account' => true]);
            $this->clearOtherDefaultExpenseAccounts($company, $account);

            return $account->refresh()->load('balance');
        });
    }

    public function ensureBelongsToCompany(Company $company, FinancialAccount $account): void
    {
        if ((int) $account->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        unset($data['company_id'], $data['current_balance']);

        return $data;
    }

    protected function assertUniqueName(
        Company $company,
        string $name,
        ?FinancialAccount $ignore = null,
    ): void {
        $exists = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->where('name', $name)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Já existe uma conta financeira com este nome nesta empresa.',
            ]);
        }
    }

    protected function clearOtherDefaultReceiptAccounts(Company $company, FinancialAccount $account): void
    {
        FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->whereKeyNot($account->getKey())
            ->where('is_default_receipt_account', true)
            ->update(['is_default_receipt_account' => false]);
    }

    protected function clearOtherDefaultExpenseAccounts(Company $company, FinancialAccount $account): void
    {
        FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->whereKeyNot($account->getKey())
            ->where('is_default_expense_account', true)
            ->update(['is_default_expense_account' => false]);
    }

    protected function assertNoOpenCashSession(FinancialAccount $account): void
    {
        if (! Schema::hasTable('cash_registers') || ! Schema::hasTable('cash_sessions')) {
            return;
        }

        $hasOpenSession = DB::table('cash_sessions')
            ->join('cash_registers', 'cash_registers.id', '=', 'cash_sessions.cash_register_id')
            ->where('cash_registers.financial_account_id', $account->getKey())
            ->where('cash_sessions.status', CashSessionStatus::Open->value)
            ->exists();

        if ($hasOpenSession) {
            throw ValidationException::withMessages([
                'is_active' => 'Não é possível desativar uma conta com sessão de caixa aberta.',
            ]);
        }
    }
}
