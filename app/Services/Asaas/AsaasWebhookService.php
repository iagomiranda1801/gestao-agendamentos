<?php

namespace App\Services\Asaas;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\AsaasBillingType;
use App\Enums\AsaasChargeStatus;
use App\Enums\FinancialTransactionType;
use App\Models\AsaasCharge;
use App\Models\Company;
use App\Models\CompanyAsaasSetting;
use App\Models\FinancialAccount;
use App\Models\TattooAiConversation;
use App\Services\Financial\FinancialLedgerService;
use App\Services\Financial\ReceivableService;
use App\Services\Tattoo\TattooBookingFlow;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AsaasWebhookService
{
    public function __construct(
        protected ReceivableService $receivables,
        protected FinancialLedgerService $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Company $company, array $payload): void
    {
        $event = (string) ($payload['event'] ?? '');
        $status = match ($event) {
            'PAYMENT_RECEIVED' => AsaasChargeStatus::Received,
            'PAYMENT_CONFIRMED' => AsaasChargeStatus::Confirmed,
            'PAYMENT_OVERDUE' => AsaasChargeStatus::Overdue,
            default => null,
        };

        if ($status === null) {
            return;
        }

        $paymentId = (string) data_get($payload, 'payment.id');
        if ($paymentId === '') {
            return;
        }

        $charge = AsaasCharge::query()
            ->where('company_id', $company->getKey())
            ->where('asaas_payment_id', $paymentId)
            ->first();

        if ($charge === null) {
            Log::info('Asaas webhook payment was not created by this company.', [
                'company_id' => $company->getKey(),
                'event' => $event,
            ]);

            return;
        }

        if ($status === AsaasChargeStatus::Overdue) {
            if ($charge->settled_at === null && $charge->status === AsaasChargeStatus::Pending) {
                $charge->forceFill(['status' => AsaasChargeStatus::Overdue])->save();
            }

            return;
        }

        $conversation = null;
        DB::transaction(function () use ($company, $charge, $payload, $status, &$conversation): void {
            $locked = AsaasCharge::query()
                ->whereKey($charge->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->settled_at !== null) {
                if ($locked->status !== $status) {
                    $locked->forceFill(['status' => $status])->save();
                }

                return;
            }

            $amounts = $this->amounts($locked, $payload);
            $paidAt = $this->paidAt($payload);

            if ($locked->receivable_id !== null) {
                $this->settleReceivable($company, $locked, $amounts['value'], $amounts['fee'], $paidAt);
            } else {
                $this->settleDeposit($company, $locked, $amounts['value'], $amounts['fee'], $paidAt);
                $conversation = $this->markDepositConfirmed($locked);
            }

            $locked->forceFill([
                'status' => $status,
                'net_value' => $amounts['net'],
                'paid_at' => $paidAt,
                'settled_at' => now(),
            ])->save();
        });

        if ($conversation instanceof TattooAiConversation) {
            $this->notifyDeposit($conversation, $charge);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{value: string, net: string, fee: string}
     */
    protected function amounts(AsaasCharge $charge, array $payload): array
    {
        $value = $this->money(data_get($payload, 'payment.value', $charge->value));
        $net = $this->money(data_get($payload, 'payment.netValue', $value));
        if (bccomp($net, $value, 2) > 0) {
            $net = $value;
        }
        $fee = bccomp($value, $net, 2) > 0
            ? DecimalMoney::round(bcsub($value, $net, 2))
            : '0.00';

        return ['value' => $value, 'net' => $net, 'fee' => $fee];
    }

    protected function money(mixed $amount): string
    {
        if (is_int($amount) || is_float($amount)) {
            return number_format($amount, 2, '.', '');
        }

        return DecimalMoney::round(is_string($amount) && is_numeric($amount) ? $amount : '0');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function paidAt(array $payload): CarbonImmutable
    {
        $date = data_get($payload, 'payment.paymentDate')
            ?? data_get($payload, 'payment.clientPaymentDate')
            ?? data_get($payload, 'payment.confirmedDate');

        return filled($date) ? CarbonImmutable::parse((string) $date) : CarbonImmutable::now();
    }

    protected function settleReceivable(
        Company $company,
        AsaasCharge $charge,
        string $value,
        string $fee,
        CarbonImmutable $paidAt,
    ): void {
        $receivable = $charge->receivable;
        $account = $this->receiptAccount($company);
        if ($receivable === null || $account === null) {
            throw ValidationException::withMessages([
                'financial_account_id' => 'Configure uma conta financeira ativa para lançar o recebimento do Asaas.',
            ]);
        }

        $billingType = $charge->billing_type instanceof AsaasBillingType
            ? $charge->billing_type
            : AsaasBillingType::from((string) $charge->billing_type);

        $payment = $this->receivables->registerGatewayPayment(
            $company,
            $receivable,
            new PaymentData(
                amount: $value,
                feeAmount: $fee,
                method: $billingType->paymentMethod(),
                paidAt: $paidAt,
                financialAccountId: (int) $account->getKey(),
                notes: $this->notes($charge, $billingType),
            ),
        );
        $charge->payment()->associate($payment);
    }

    protected function settleDeposit(
        Company $company,
        AsaasCharge $charge,
        string $value,
        string $fee,
        CarbonImmutable $paidAt,
    ): void {
        $account = $this->receiptAccount($company);
        if ($account === null) {
            return;
        }

        $this->ledger->postInbound(
            $company,
            $account,
            $value,
            FinancialTransactionType::CustomerPayment,
            $paidAt,
            'Sinal recebido via Asaas',
            $charge->ledgerReferenceKey(),
            $charge,
        );

        if (bccomp($fee, '0', 2) > 0) {
            $this->ledger->postOutbound(
                $company,
                $account,
                $fee,
                FinancialTransactionType::PaymentFee,
                $paidAt,
                'Taxa do Asaas',
                $charge->feeLedgerReferenceKey(),
                $charge,
            );
        }
    }

    protected function markDepositConfirmed(AsaasCharge $charge): ?TattooAiConversation
    {
        $quote = $charge->quote;
        if ($quote === null) {
            return null;
        }

        $conversation = TattooAiConversation::query()
            ->where('company_id', $charge->company_id)
            ->where('tattoo_request_id', $quote->tattoo_request_id)
            ->latest('id')
            ->first();

        $conversation?->update(['status' => 'payment_confirmed']);

        return $conversation;
    }

    protected function notifyDeposit(TattooAiConversation $conversation, AsaasCharge $charge): void
    {
        try {
            $flow = app(TattooBookingFlow::class);
            $reply = $flow->confirmPaidDeposit($conversation->fresh());
            if ($reply !== null) {
                $flow->sendToCustomer($conversation->fresh(), $reply, 'asaas-payment:'.$charge->getKey());
            }
        } catch (\Throwable $exception) {
            Log::warning('Asaas deposit confirmation message failed.', [
                'company_id' => $charge->company_id,
                'charge_id' => $charge->getKey(),
                'error_type' => $exception::class,
            ]);
        }
    }

    protected function receiptAccount(Company $company): ?FinancialAccount
    {
        $setting = CompanyAsaasSetting::query()
            ->where('company_id', $company->getKey())
            ->first();
        $account = $setting?->financialAccount;
        if ($account === null || ! $account->isActive() || (int) $account->company_id !== (int) $company->getKey()) {
            return null;
        }

        return $account;
    }

    protected function notes(AsaasCharge $charge, AsaasBillingType $billingType): string
    {
        $label = match ($billingType) {
            AsaasBillingType::Pix => 'PIX',
            AsaasBillingType::Boleto => 'Boleto',
            AsaasBillingType::CreditCard => 'Cartão',
        };

        return 'Asaas '.$label.' '.$charge->asaas_payment_id;
    }
}
