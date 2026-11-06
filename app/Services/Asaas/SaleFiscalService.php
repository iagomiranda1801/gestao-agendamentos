<?php

namespace App\Services\Asaas;

use App\Enums\AsaasEnvironment;
use App\Enums\SaleItemType;
use App\Enums\SaleStatus;
use App\Exceptions\AsaasApiException;
use App\Models\AsaasCharge;
use App\Models\Company;
use App\Models\CompanyAsaasSetting;
use App\Models\Sale;
use App\Models\SaleFiscalDocument;
use App\Models\SaleItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleFiscalService
{
    public function __construct(
        private AsaasClient $asaas,
        private BaseFiscalClient $base,
    ) {}

    public function items(Sale $sale, string $type): Collection
    {
        return $sale->items->filter(fn (SaleItem $item): bool => $type === SaleFiscalDocument::PRODUCT
            ? $item->item_type === SaleItemType::Product
            : in_array($item->item_type, [SaleItemType::Service, SaleItemType::Attendance], true));
    }

    public function amount(Sale $sale, string $type): string
    {
        $products = $this->subtotal($this->items($sale, SaleFiscalDocument::PRODUCT));
        $services = $this->subtotal($this->items($sale, SaleFiscalDocument::SERVICE));
        $total = bcadd($products, $services, 2);

        if (bccomp($total, '0', 2) <= 0) {
            return '0.00';
        }

        // Assign any fraction of a cent from the global discount to services.
        $productDiscount = bcdiv(bcmul($sale->discount_amount, $products, 4), $total, 2);

        return $type === SaleFiscalDocument::PRODUCT
            ? bcsub($products, $productDiscount, 2)
            : bcsub($services, bcsub($sale->discount_amount, $productDiscount, 2), 2);
    }

    public function issue(Sale $sale, string $type, array $data): SaleFiscalDocument
    {
        $setting = $this->setting($sale->company, $type);

        [$document, $payload] = DB::transaction(function () use ($sale, $type, $data): array {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->getKey())->load('items', 'client');
            $amount = $this->amount($sale, $type);
            $items = $this->items($sale, $type);

            if (in_array($sale->status, [SaleStatus::Draft, SaleStatus::Cancelled], true)
                || $items->isEmpty() || bccomp($amount, '0', 2) <= 0) {
                $this->fail('A venda precisa estar finalizada e ter itens com valor positivo para esta nota.');
            }

            if ($sale->items->contains('item_type', SaleItemType::Custom)) {
                $this->fail('A venda tem itens avulsos. Classifique os itens antes de emitir.');
            }

            if ($sale->client === null || (int) $sale->client->company_id !== (int) $sale->company_id
                || ! in_array(strlen(preg_replace('/\D/', '', (string) $sale->client->document)), [11, 14], true)) {
                $this->fail('Vincule um cliente com CPF ou CNPJ à venda.');
            }

            $document = $sale->fiscalDocuments()->firstOrNew([
                'type' => $type,
                'environment' => AsaasEnvironment::Sandbox->value,
            ]);
            if ($document->exists && $document->status !== 'rejected') {
                $this->fail('Já existe uma solicitação para esta nota. Consulte o status na venda.');
            }

            $reference = (string) Str::uuid();
            $payload = $type === SaleFiscalDocument::PRODUCT
                ? $this->productPayload($items, $amount, $reference, $data)
                : $this->servicePayload($sale, $items, $amount, $reference, $data);

            $document->fill([
                'company_id' => $sale->company_id,
                'reference' => $reference,
                'status' => 'sending',
                'amount' => $amount,
                'error' => null,
            ])->save();

            return [$document, $payload];
        });

        $writing = false;

        try {
            if ($type === SaleFiscalDocument::SERVICE) {
                $payload += $this->serviceCustomer($sale->loadMissing('client'), $setting, $document->amount, $data);
                $writing = true;
                $response = $this->asaas->scheduleInvoice(AsaasEnvironment::Sandbox, $setting->fiscalSandboxKey(), $payload);
                $this->apply($document, filled($response['id'] ?? null) ? $this->serviceStatus($response['status'] ?? '') : 'unknown', $response);
            } else {
                $writing = true;
                $order = $this->base->send($setting->base_sandbox_api_key, 'post', '/salesOrders', $payload, $document->reference);

                if (blank($order['id'] ?? null)) {
                    $this->apply($document, 'unknown', [], 'O Base não devolveu o número do pedido. Consulte o status antes de continuar.');
                } else {
                    $this->rememberProductOrder($document, $order['id']);
                    $this->sendProductInvoice($document->refresh(), $setting);
                }
            }
        } catch (AsaasApiException|ConnectionException $exception) {
            $definite = ! $writing || ($exception instanceof AsaasApiException && $exception->wasRejected());
            $this->apply($document, $definite ? 'rejected' : 'unknown', [], $exception instanceof AsaasApiException
                ? $exception->getMessage()
                : 'O provedor não respondeu. Consulte o status antes de tentar novamente.');
        }

        return $document->refresh();
    }

    public function refresh(SaleFiscalDocument $document, Company $company): void
    {
        abort_unless((int) $document->company_id === (int) $company->getKey(), 404);
        $setting = $this->setting($company, $document->type);

        if ($document->type === SaleFiscalDocument::SERVICE) {
            $invoice = $this->asaas->findInvoice(AsaasEnvironment::Sandbox, $setting->fiscalSandboxKey(), $document->reference);
            if ($invoice !== null) {
                $this->apply($document, $this->serviceStatus($invoice['status'] ?? ''), $invoice);
            }

            return;
        }

        if (blank($document->external_order_id)) {
            $orders = $this->base->send($setting->base_sandbox_api_key, 'get', '/salesOrders', ['externalReference' => $document->reference]);
            $order = collect($orders['content'] ?? [])->firstWhere('externalReference', $document->reference);
            if (filled($order['id'] ?? null)) {
                $this->rememberProductOrder($document, $order['id']);
            }
        }

        $document->refresh();
        if (filled($document->external_order_id) && $document->status !== 'order_pending') {
            $order = $this->base->send($setting->base_sandbox_api_key, 'get', '/salesOrders/'.$document->external_order_id);
            $this->applyProductOrderStatus($document, $order);
        }
    }

    public function continueProductInvoice(SaleFiscalDocument $document, Company $company): void
    {
        abort_unless((int) $document->company_id === (int) $company->getKey() && $document->type === SaleFiscalDocument::PRODUCT, 404);
        $this->sendProductInvoice($document, $this->setting($company, SaleFiscalDocument::PRODUCT));
    }

    private function rememberProductOrder(SaleFiscalDocument $document, int|string $orderId): void
    {
        SaleFiscalDocument::query()->whereKey($document->getKey())->where('reference', $document->reference)
            ->whereNull('external_order_id')->whereIn('status', ['sending', 'unknown'])
            ->update(['external_order_id' => (string) $orderId, 'status' => 'order_pending']);
    }

    private function applyProductOrderStatus(SaleFiscalDocument $document, array $order): void
    {
        if (blank($order['invoiceId'] ?? null)) {
            return;
        }

        $status = match ($order['invoiceStatus'] ?? '') {
            'ENVIADA' => 'authorized',
            'CANCELADA', 'INUTILIZADA' => 'cancelled',
            'NEGADA' => 'error',
            default => 'pending',
        };
        $this->apply($document, $status, ['id' => $order['invoiceId'], 'number' => $order['invoiceNumber'] ?? null]);
    }

    private function sendProductInvoice(SaleFiscalDocument $document, CompanyAsaasSetting $setting): void
    {
        $claimed = SaleFiscalDocument::query()->whereKey($document->getKey())->where('status', 'order_pending')
            ->update(['status' => 'sending', 'error' => null]);

        if ($claimed === 0) {
            return;
        }

        $writing = false;
        try {
            $order = $this->base->send($setting->base_sandbox_api_key, 'get', '/salesOrders/'.$document->external_order_id);
            if (filled($order['invoiceId'] ?? null)) {
                $this->applyProductOrderStatus($document, $order);

                return;
            }

            if (($order['externalReference'] ?? null) !== $document->reference
                || ! is_numeric($order['orderValue'] ?? null)
                || $this->asaas->money($order['orderValue']) !== $document->amount) {
                $this->apply($document, 'order_pending', [], 'O pedido no Base ainda não está pronto ou o valor diverge da venda. Confira o pedido antes de continuar.');

                return;
            }

            $writing = true;
            $response = $this->base->send($setting->base_sandbox_api_key, 'post', '/salesOrders/'.$document->external_order_id.'/invoice', ['type' => '55'], $document->reference.'-invoice');
            $this->apply($document, 'pending', ['id' => $response['invoiceId'] ?? null]);
            $this->applyProductOrderStatus($document, $response);
        } catch (AsaasApiException|ConnectionException $exception) {
            $rejected = ! $writing || ($exception instanceof AsaasApiException && $exception->wasRejected());
            $this->apply($document, $rejected ? 'order_pending' : 'unknown', [], $exception instanceof AsaasApiException
                ? $exception->getMessage()
                : 'O Base não respondeu. Consulte o status da nota.');
        }
    }

    private function setting(Company $company, string $type): CompanyAsaasSetting
    {
        $setting = $company->asaasSetting()->first();
        $available = $type === SaleFiscalDocument::PRODUCT
            ? filled($setting?->base_sandbox_api_key)
            : filled($setting?->fiscalSandboxKey());

        if (! $available || $setting === null) {
            $this->fail('Peça ao administrador da empresa para cadastrar a chave Sandbox do emissor em Vendas → Configurar notas fiscais.');
        }

        return $setting;
    }

    private function serviceCustomer(Sale $sale, CompanyAsaasSetting $setting, string $amount, array $data): array
    {
        $charge = AsaasCharge::query()->where('company_id', $sale->company_id)
            ->where('client_id', $sale->client_id)
            ->whereNotNull('asaas_payment_id')
            ->where('value', $amount)
            ->whereHas('receivable', fn ($query) => $query->where('sale_id', $sale->getKey()))
            ->first();

        // Resolve customers in the selected environment, including sales paid outside Asaas.
        $customer = $this->asaas->findCustomer(AsaasEnvironment::Sandbox, $setting->fiscalSandboxKey(), $sale->client);
        if ($customer === null) {
            $address = array_intersect_key($data, array_flip(['postalCode', 'address', 'addressNumber', 'province']));
            $address = array_map(fn ($value): string => trim((string) $value), $address);
            if (count(array_filter($address, fn (string $value): bool => $value !== '')) !== 4
                || ! filter_var($sale->client->email, FILTER_VALIDATE_EMAIL)) {
                throw new AsaasApiException('Para cadastrar o cliente no Asaas, informe o endereço no formulário e um e-mail válido no cadastro do cliente.');
            }

            $address['postalCode'] = preg_replace('/\D/', '', $address['postalCode']);
            $customer = $this->asaas->createCustomer(AsaasEnvironment::Sandbox, $setting->fiscalSandboxKey(), $sale->client, $address);
        }

        if (blank($customer['id'] ?? null)) {
            throw new AsaasApiException('O Asaas não retornou o cadastro do cliente.');
        }

        if ($charge !== null && ($customer['id'] ?? null) === $charge->asaas_customer_id) {
            return ['payment' => $charge->asaas_payment_id];
        }

        return ['customer' => $customer['id']];
    }

    private function servicePayload(Sale $sale, Collection $items, string $amount, string $reference, array $data): array
    {
        $taxes = ['retainIss' => (bool) ($data['retainIss'] ?? false)];
        foreach (['iss', 'pis', 'cofins', 'csll', 'inss', 'ir'] as $tax) {
            $taxes[$tax] = (float) $data[$tax];
        }
        foreach (['nbsCode', 'federalServiceTaxCode', 'taxSituationCode', 'taxClassificationCode', 'operationIndicatorCode'] as $field) {
            if (filled($data[$field] ?? null)) {
                $taxes[$field] = trim($data[$field]);
            }
        }

        return [
            'externalReference' => $reference,
            'effectiveDate' => today()->toDateString(),
            'serviceDescription' => $items->pluck('name_snapshot')->implode('; '),
            'observations' => 'Venda '.$sale->getKey(),
            'value' => (float) $amount,
            'deductions' => 0,
            'municipalServiceCode' => trim($data['serviceCode']),
            'municipalServiceName' => trim($data['serviceName']),
            'updatePayment' => false,
            'taxes' => $taxes,
        ];
    }

    private function productPayload(Collection $items, string $amount, string $reference, array $data): array
    {
        $gross = '0.00';
        $orderItems = [];
        foreach ($items as $item) {
            $gross = bcadd($gross, bcadd($item->line_total, $item->discount_amount, 2), 2);
            $orderItem = [
                'productId' => (int) $data['product_'.$item->getKey()],
                'quantity' => (float) $item->quantity,
                'unitPrice' => (float) $item->unit_price_snapshot,
            ];
            if (filled($data['tax_'.$item->getKey()] ?? null)) {
                $orderItem['cClassTrib'] = trim($data['tax_'.$item->getKey()]);
            }
            $orderItems[] = $orderItem;
        }

        return [
            'customerId' => (int) $data['baseCustomer'],
            'issueDate' => today()->toDateString(),
            'externalReference' => $reference,
            'discountValue' => (float) bcsub($gross, $amount, 2),
            'orderItems' => $orderItems,
            'orderPayments' => [[
                'bankId' => (int) $data['baseBank'],
                'billingType' => $data['basePaymentType'],
                'dueDate' => $data['dueDate'],
                'value' => (float) $amount,
            ]],
        ];
    }

    private function subtotal(Collection $items): string
    {
        return $items->reduce(fn (string $total, SaleItem $item): string => bcadd($total, $item->line_total, 2), '0.00');
    }

    public function handleWebhook(Company $company, array $payload): void
    {
        $event = (string) ($payload['event'] ?? '');
        $base = str_starts_with($event, 'INVOICE_NFE_');
        $invoice = $payload[$base ? 'invoiceNfe' : 'invoice'] ?? [];
        $type = $base ? SaleFiscalDocument::PRODUCT : SaleFiscalDocument::SERVICE;
        $id = (string) ($invoice[$base ? 'invoiceId' : 'id'] ?? '');
        $link = (string) ($invoice[$base ? 'salesOrderId' : 'externalReference'] ?? '');

        $status = match ($event) {
            'INVOICE_CREATED', 'INVOICE_SYNCHRONIZED', 'INVOICE_NFE_CREATED' => 'pending',
            'INVOICE_AUTHORIZED', 'INVOICE_NFE_AUTHORIZED' => 'authorized',
            'INVOICE_ERROR', 'INVOICE_NFE_ERROR' => 'error',
            'INVOICE_CANCELED', 'INVOICE_NFE_CANCELED' => 'cancelled',
            default => null,
        };

        if ($status === null || $id === '') {
            return;
        }

        $query = SaleFiscalDocument::query()->where('company_id', $company->getKey())
            ->where('environment', AsaasEnvironment::Sandbox->value)->where('type', $type);

        $document = (clone $query)->where('external_invoice_id', $id)->first()
            ?? ($link === '' ? null : $query->where($base ? 'external_order_id' : 'reference', $link)->first());

        if ($document !== null) {
            $this->apply($document, $status, ['id' => $id] + $invoice);
        }
    }

    private function serviceStatus(string $status): string
    {
        return match ($status) {
            'AUTHORIZED', 'CANCELLATION_DENIED' => 'authorized',
            'CANCELED' => 'cancelled',
            'ERROR' => 'error',
            default => 'pending',
        };
    }

    private function apply(SaleFiscalDocument $document, string $status, array $data, ?string $error = null): void
    {
        DB::transaction(function () use ($document, $status, $data, $error): void {
            $row = SaleFiscalDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            if ($row->reference !== $document->reference
                || (filled($row->external_invoice_id) && filled($data['id'] ?? null) && $row->external_invoice_id !== (string) $data['id'])) {
                return;
            }

            $keepStatus = ($row->status === 'cancelled')
                || ($row->status === 'authorized' && $status !== 'cancelled')
                || ($row->status === 'error' && ! in_array($status, ['authorized', 'cancelled'], true))
                || (filled($row->external_invoice_id) && in_array($status, ['unknown', 'rejected', 'order_pending'], true));

            $row->update([
                'status' => $keepStatus ? $row->status : $status,
                'external_invoice_id' => filled($data['id'] ?? null) ? (string) $data['id'] : $row->external_invoice_id,
                'number' => $data['number'] ?? $row->number,
                'error' => $keepStatus ? $row->error : ($error ?? ($status === 'error' ? ($data['statusDescription'] ?? 'Consulte a rejeição no emissor.') : null)),
                'pdf_url' => $this->documentUrl($data['pdfUrl'] ?? null) ?? $row->pdf_url,
                'xml_url' => $this->documentUrl($data['xmlUrl'] ?? null) ?? $row->xml_url,
            ]);
        });
    }

    private function documentUrl(mixed $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && (str_ends_with($host, '.asaas.com') || str_ends_with($host, '.baseerp.com.br')) ? $url : null;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['fiscal' => $message]);
    }
}
