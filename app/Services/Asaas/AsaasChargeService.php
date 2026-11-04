<?php

namespace App\Services\Asaas;

use App\Enums\AsaasBillingType;
use App\Enums\AsaasChargeStatus;
use App\Exceptions\AsaasApiException;
use App\Models\AsaasCharge;
use App\Models\AsaasCustomer;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyAsaasSetting;
use App\Models\Receivable;
use App\Models\TattooQuote;
use App\Support\DecimalMoney;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AsaasChargeService
{
    public function __construct(
        protected AsaasClient $asaas,
        protected CompanyAsaasSettingService $settings,
    ) {}

    public function settingsReady(Company $company): bool
    {
        return $this->settings->ready($company) !== null;
    }

    public function createForReceivable(
        Company $company,
        Receivable $receivable,
        AsaasBillingType $billingType,
        string $amount,
        ?CarbonInterface $dueDate = null,
    ): AsaasCharge {
        $this->ensureReceivable($company, $receivable);
        $amount = DecimalMoney::round($amount);
        $this->ensureAmount($amount, (string) $receivable->outstanding_amount);

        $client = $receivable->client;
        if ($client === null) {
            throw ValidationException::withMessages([
                'client_id' => 'A conta a receber precisa de um cliente para gerar a cobrança.',
            ]);
        }

        $existing = $this->pendingCharge($company, $billingType, $amount, receivableId: (int) $receivable->getKey());
        if ($existing !== null) {
            return $existing;
        }

        return $this->create(
            $company,
            $client,
            $billingType,
            $amount,
            $dueDate ?? $receivable->due_date ?? now(),
            'Conta a receber #'.$receivable->getKey(),
            receivable: $receivable,
        );
    }

    public function createForQuote(Company $company, TattooQuote $quote, Client $client): AsaasCharge
    {
        if ((int) $quote->company_id !== (int) $company->getKey() || (int) $client->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'quote' => 'O orçamento não pertence a esta empresa.',
            ]);
        }

        $amount = DecimalMoney::round((string) $quote->deposit_amount);
        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'deposit_amount' => 'O sinal precisa ser maior que zero.',
            ]);
        }

        if (AsaasCharge::depositIsPaid((int) $company->getKey(), (int) $quote->getKey())) {
            throw ValidationException::withMessages([
                'deposit_amount' => 'O sinal deste orçamento já foi pago.',
            ]);
        }

        $existing = $this->pendingCharge($company, AsaasBillingType::Pix, $amount, quoteId: (int) $quote->getKey());
        if ($existing !== null) {
            return $existing;
        }

        return $this->create(
            $company,
            $client,
            AsaasBillingType::Pix,
            $amount,
            now(),
            'Sinal do orçamento #'.$quote->getKey(),
            quote: $quote,
        );
    }

    protected function pendingCharge(
        Company $company,
        AsaasBillingType $billingType,
        string $amount,
        ?int $receivableId = null,
        ?int $quoteId = null,
    ): ?AsaasCharge {
        return AsaasCharge::query()
            ->where('company_id', $company->getKey())
            ->when($receivableId !== null, fn ($query) => $query->where('receivable_id', $receivableId))
            ->when($quoteId !== null, fn ($query) => $query->where('tattoo_quote_id', $quoteId))
            ->where('billing_type', $billingType)
            ->where('status', AsaasChargeStatus::Pending)
            ->where('value', $amount)
            ->whereNotNull('asaas_payment_id')
            ->latest('id')
            ->first();
    }

    protected function create(
        Company $company,
        Client $client,
        AsaasBillingType $billingType,
        string $amount,
        CarbonInterface $dueDate,
        string $description,
        ?Receivable $receivable = null,
        ?TattooQuote $quote = null,
    ): AsaasCharge {
        $setting = $this->settings->ready($company);
        if ($setting === null) {
            throw ValidationException::withMessages([
                'asaas' => 'Ative a integração Asaas desta empresa antes de cobrar.',
            ]);
        }

        $customerId = $this->customerId($company, $client, $setting);
        $due = Carbon::parse($dueDate)->startOfDay();
        if ($due->lt(now()->startOfDay())) {
            $due = now()->startOfDay();
        }

        $charge = new AsaasCharge([
            'asaas_customer_id' => $customerId,
            'billing_type' => $billingType,
            'value' => $amount,
            'status' => AsaasChargeStatus::Pending,
            'due_date' => $due->toDateString(),
        ]);
        $charge->company()->associate($company);
        $charge->client()->associate($client);
        if ($receivable !== null) {
            $charge->receivable()->associate($receivable);
        }
        if ($quote !== null) {
            $charge->quote()->associate($quote);
        }
        $charge->save();

        try {
            $payment = $this->asaas->createPayment($setting->environment, (string) $setting->api_key, [
                'customer' => $customerId,
                'billingType' => $billingType->value,
                'value' => (float) $amount,
                'dueDate' => $due->toDateString(),
                'description' => mb_substr($description, 0, 500),
                'externalReference' => 'charge:'.$charge->getKey(),
            ]);
        } catch (AsaasApiException $exception) {
            $charge->delete();

            throw ValidationException::withMessages([
                'asaas' => $exception->getMessage(),
            ]);
        }

        $paymentId = (string) ($payment['id'] ?? '');
        if ($paymentId === '') {
            $charge->delete();

            throw ValidationException::withMessages([
                'asaas' => 'O Asaas não devolveu a cobrança.',
            ]);
        }

        $charge->forceFill([
            'asaas_payment_id' => $paymentId,
            'invoice_url' => $payment['invoiceUrl'] ?? null,
            'bank_slip_url' => $payment['bankSlipUrl'] ?? null,
            'net_value' => isset($payment['netValue']) ? $this->asaas->money($payment['netValue']) : null,
        ])->save();

        if ($billingType === AsaasBillingType::Pix) {
            $this->storePixPayload($setting, $charge);
        }

        return $charge->refresh();
    }

    protected function storePixPayload(CompanyAsaasSetting $setting, AsaasCharge $charge): void
    {
        try {
            $qr = $this->asaas->pixQrCode($setting->environment, (string) $setting->api_key, (string) $charge->asaas_payment_id);
            $payload = $qr['payload'] ?? null;
            if (is_string($payload) && $payload !== '') {
                $charge->forceFill(['pix_payload' => $payload])->save();
            }
        } catch (AsaasApiException $exception) {
            Log::warning('Asaas PIX payload unavailable.', [
                'company_id' => $charge->company_id,
                'charge_id' => $charge->getKey(),
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ]);
        }
    }

    protected function customerId(Company $company, Client $client, CompanyAsaasSetting $setting): string
    {
        if ((int) $client->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'client_id' => 'O cliente não pertence a esta empresa.',
            ]);
        }

        $existing = AsaasCustomer::query()
            ->where('company_id', $company->getKey())
            ->where('client_id', $client->getKey())
            ->first();

        if ($existing !== null) {
            return $existing->asaas_id;
        }

        try {
            $created = $this->asaas->createCustomer($setting->environment, (string) $setting->api_key, $client);
        } catch (AsaasApiException $exception) {
            throw ValidationException::withMessages([
                'asaas' => $exception->getMessage(),
            ]);
        }

        $asaasId = (string) ($created['id'] ?? '');
        if ($asaasId === '') {
            throw ValidationException::withMessages([
                'asaas' => 'O Asaas não devolveu o cliente.',
            ]);
        }

        $row = new AsaasCustomer(['asaas_id' => $asaasId]);
        $row->company()->associate($company);
        $row->client()->associate($client);

        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            return (string) AsaasCustomer::query()
                ->where('company_id', $company->getKey())
                ->where('client_id', $client->getKey())
                ->value('asaas_id');
        }

        return $asaasId;
    }

    protected function ensureReceivable(Company $company, Receivable $receivable): void
    {
        if ((int) $receivable->company_id !== (int) $company->getKey()) {
            throw ValidationException::withMessages([
                'receivable_id' => 'A conta a receber informada não pertence a esta empresa.',
            ]);
        }

        if ($receivable->isSettled() || $receivable->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => 'Só é possível cobrar uma conta em aberto.',
            ]);
        }
    }

    protected function ensureAmount(string $amount, string $outstanding): void
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'O valor da cobrança precisa ser maior que zero.',
            ]);
        }

        if (bccomp($amount, $outstanding, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'A cobrança não pode ser maior que o saldo em aberto.',
            ]);
        }
    }
}
