<?php

namespace App\Enums;

enum FinancialTransactionType: string
{
    case CustomerPayment = 'customer_payment';
    case PaymentFee = 'payment_fee';
    case ExpensePayment = 'expense_payment';
    case FinancialCharge = 'financial_charge';
    case OpeningBalance = 'opening_balance';
    case AccountAdjustment = 'account_adjustment';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case CashReinforcement = 'cash_reinforcement';
    case CashWithdrawal = 'cash_withdrawal';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::CustomerPayment => 'Recebimento de cliente',
            self::PaymentFee => 'Taxa de pagamento',
            self::ExpensePayment => 'Pagamento de despesa',
            self::FinancialCharge => 'Encargo financeiro',
            self::OpeningBalance => 'Saldo inicial',
            self::AccountAdjustment => 'Ajuste de conta',
            self::TransferIn => 'Transferência recebida',
            self::TransferOut => 'Transferência enviada',
            self::CashReinforcement => 'Reforço de caixa',
            self::CashWithdrawal => 'Sangria de caixa',
            self::Reversal => 'Estorno',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type) => [$type->value => $type->label()])
            ->all();
    }

    public function isTransfer(): bool
    {
        return in_array($this, [self::TransferIn, self::TransferOut], true);
    }

    public function isCashAdjustment(): bool
    {
        return in_array($this, [self::CashReinforcement, self::CashWithdrawal], true);
    }
}
