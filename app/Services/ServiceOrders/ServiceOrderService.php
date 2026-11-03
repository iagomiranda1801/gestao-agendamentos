<?php

namespace App\Services\ServiceOrders;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\CompanyModule;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductType;
use App\Enums\SaleOrigin;
use App\Enums\SaleStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderQuote;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use App\Services\Company\CompanyPermissionService;
use App\Services\Financial\ReceivableService;
use App\Services\Sales\SaleService;
use App\Services\Stock\StockDocumentReversalService;
use App\Support\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceOrderService
{
    public function authorize(Company $company, User $user): void
    {
        abort_unless(app(CompanyModuleService::class)->hasModule($company, CompanyModule::ServiceOrders)
            && $company->is_active && $user->is_active
            && app(CompanyPermissionService::class)->allows($user, $company, CompanyPermission::ManageServiceOrders), 403);
    }

    public function receive(Company $company, User $user, array $data): ServiceOrder
    {
        $this->authorize($company, $user);
        $data = validator($data, [
            'client_id' => ['required', 'integer'], 'device' => ['required', 'string', 'max:255'],
            'problem' => ['required', 'string', 'max:5000'],
            'brand' => ['nullable', 'string', 'max:255'], 'model' => ['nullable', 'string', 'max:255'],
            'imei' => ['nullable', 'string', 'max:255'], 'condition' => ['nullable', 'string', 'max:5000'],
            'accessories' => ['nullable', 'string', 'max:5000'], 'notes' => ['nullable', 'string', 'max:5000'],
            'warranty' => ['nullable', 'string', 'max:5000'],
        ])->validate();
        $client = Client::where('company_id', $company->id)->findOrFail($data['client_id']);
        $this->check(filled($client->name) && filled($client->phone_normalized), 'O cliente precisa de nome e telefone válido.');

        return DB::transaction(function () use ($company, $user, $data) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $order = new ServiceOrder($data);
            $order->company_id = $company->id;
            $order->number = (int) ServiceOrder::where('company_id', $company->id)->max('number') + 1;
            $order->document_token = Str::random(64);
            $order->receipt_token = Str::random(64);
            $order->status = 'analysis';
            $order->save();
            $this->event($order, 'Aparelho recebido', $user);

            return $order;
        });
    }

    public function quote(ServiceOrder $order, User $user, array $data): ServiceOrderQuote
    {
        return DB::transaction(function () use ($order, $user, $data) {
            $order = $this->lock($order, $user);
            $this->check(! in_array($order->status, ['ready', 'delivered', 'returned', 'reversed']), 'Esta OS já foi concluída.');
            $data = validator($data, [
                'items' => ['required', 'array', 'min:1', 'max:100'],
                'items.*.item_type' => ['required', 'in:product,service,custom'],
                'items.*.product_id' => ['nullable', 'integer'], 'items.*.service_id' => ['nullable', 'integer'],
                'items.*.name' => ['nullable', 'string', 'max:255'],
                'items.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:999999', 'regex:/^\d+(?:\.\d{1,4})?$/'],
                'items.*.unit_price' => ['required', 'numeric', 'min:0.01', 'max:99999999', 'regex:/^\d+(?:\.\d{1,2})?$/'],
                'deadline' => ['required', 'string', 'max:255'],
                'valid_until' => ['required', 'date', 'after_or_equal:'.now($order->company->timezone)->toDateString()],
                'conditions' => ['nullable', 'string', 'max:5000'],
            ])->validate();
            $total = '0.00';
            $items = [];
            foreach ($data['items'] as $input) {
                $item = [
                    'item_type' => $input['item_type'], 'quantity' => bcadd((string) $input['quantity'], '0', 4),
                    'unit_price' => DecimalMoney::round((string) $input['unit_price']),
                    'name' => trim($input['name'] ?? ''),
                ];
                if ($item['item_type'] === 'product') {
                    $product = Product::where('company_id', $order->company_id)->findOrFail($input['product_id'] ?? null);
                    $this->check($product->is_active && in_array($product->type, [ProductType::Sale, ProductType::Consumable], true), 'Selecione uma peça ativa de venda ou consumo.');
                    $item['product_id'] = $product->id;
                    $item['name'] = $product->name;
                } elseif ($item['item_type'] === 'service') {
                    $service = Service::where('company_id', $order->company_id)->findOrFail($input['service_id'] ?? null);
                    // Peças são declaradas explicitamente na OS, sem consumo implícito adicional.
                    $item['item_type'] = 'custom';
                    $item['catalog_service_id'] = $service->id;
                    $item['name'] = $service->name;
                }
                $this->check($item['name'] !== '', 'Informe a descrição da mão de obra.');
                $total = bcadd($total, DecimalMoney::round(bcmul($item['quantity'], $item['unit_price'], 4)), 2);
                $items[] = $item;
            }
            $this->check(bccomp($total, '9999999999.99', 2) <= 0, 'O total excede o limite financeiro permitido.');
            $quote = $order->quotes()->create([
                'version' => (int) $order->quotes()->max('version') + 1,
                'items' => $items, 'total' => $total, 'deadline' => $data['deadline'],
                'valid_until' => $data['valid_until'], 'conditions' => $data['conditions'] ?? null,
                'token' => Str::random(64),
            ]);
            $order->update(['status' => 'analysis']);
            $this->event($order, 'Orçamento versão '.$quote->version.' criado; aprovação anterior substituída', $user);

            return $quote;
        });
    }

    public function publish(ServiceOrder $order, User $user): ServiceOrderQuote
    {
        return DB::transaction(function () use ($order, $user) {
            $order = $this->lock($order, $user);
            $quote = $order->latestQuote();
            $this->check($quote !== null && ! in_array($order->status, ['ready', 'delivered', 'returned', 'reversed']), 'Crie um orçamento antes de enviar.');
            $this->check(! $quote->expired(), 'O orçamento expirou. Crie uma nova versão.');
            if (! $quote->published_at) {
                $quote->update(['published_at' => now()]);
                $order->update(['status' => 'awaiting_approval']);
                $this->event($order, 'Orçamento versão '.$quote->version.' disponibilizado', $user);
            }

            return $quote;
        });
    }

    public function decide(ServiceOrderQuote $quote, string $decision, ?User $user = null): void
    {
        DB::transaction(function () use ($quote, $decision, $user) {
            $order = ServiceOrder::whereKey($quote->service_order_id)->lockForUpdate()->firstOrFail();
            if ($user) {
                $this->authorize($order->company, $user);
            }
            $quote = $quote->fresh();
            $this->check(in_array($decision, ['approved', 'declined']), 'Decisão inválida.');
            $this->check($quote->published_at !== null && $order->latestQuote()?->id === $quote->id
                && $quote->decision === null && ! $quote->expired()
                && $order->company->is_active && app(CompanyModuleService::class)->hasModule($order->company, CompanyModule::ServiceOrders)
                && $order->status === 'awaiting_approval', 'Este orçamento expirou, foi substituído ou já recebeu uma resposta.');
            $quote->update(['decision' => $decision, 'decided_at' => now(), 'decision_source' => $user ? 'in_person' : 'public_link', 'decided_by' => $user?->id]);
            $order->update(['status' => $decision === 'approved' ? 'approved' : 'analysis']);
            $this->event($order, 'Orçamento versão '.$quote->version.($decision === 'approved' ? ' aprovado' : ' recusado'), $user);
        });
    }

    public function transition(ServiceOrder $order, User $user, string $status, bool $allowOutstanding = false): ServiceOrder
    {
        return DB::transaction(function () use ($order, $user, $status, $allowOutstanding) {
            $order = $this->lock($order, $user);
            if ($order->status === $status) {
                return $order;
            }
            $allowed = [
                'analysis' => ['returned'], 'awaiting_approval' => ['returned'],
                'approved' => ['awaiting_part', 'repairing', 'returned'],
                'awaiting_part' => ['repairing', 'returned'], 'repairing' => ['awaiting_part', 'ready'],
                'ready' => ['delivered'],
            ];
            $this->check(in_array($status, $allowed[$order->status] ?? []), 'Esta mudança de etapa não é permitida.');
            if (in_array($status, ['repairing', 'awaiting_part', 'ready'])) {
                $this->check($order->latestQuote()?->decision === 'approved', 'O cliente precisa aprovar o orçamento atual.');
            }
            if ($status === 'ready') {
                foreach ([CompanyModule::Sales, CompanyModule::Stock, CompanyModule::Finance] as $module) {
                    $this->check(app(CompanyModuleService::class)->hasModule($order->company, $module), 'Ative Vendas, Estoque e Financeiro para concluir o conserto.');
                }
                $quote = $order->latestQuote();
                $sale = app(SaleService::class)->complete($order->company, $user, [
                    'client_id' => $order->client_id, 'origin' => SaleOrigin::ServiceOrder->value,
                    'reference_key' => 'service-order:'.$order->id, 'allow_unpaid' => true,
                    'items' => $quote->items, 'payments' => [], 'notes' => 'OS '.$order->number,
                ]);
                $this->check(bccomp($sale->final_amount, $quote->total, 2) === 0, 'O total precisa corresponder ao orçamento aprovado.');
                $order->sale_id = $sale->id;
            }
            if ($status === 'delivered') {
                $sale = $order->sale?->fresh();
                $this->check($sale !== null && $sale->status !== SaleStatus::Cancelled, 'A venda está cancelada ou indisponível.');
                $this->check(! $sale->receivable->isCancelled() && ! $sale->stockDocument?->isReversed(), 'O financeiro ou estoque desta OS foi estornado. Revise antes da entrega.');
                if (bccomp((string) $sale->receivable->outstanding_amount, '0', 2) > 0) {
                    $this->check($allowOutstanding && $user->hasActiveRoleInCompany($order->company, CompanyRole::CompanyAdmin, CompanyRole::Manager), 'Existe saldo pendente. Somente administrador ou gerente pode autorizar a entrega.');
                    $this->event($order, 'Entrega com saldo pendente autorizada', $user);
                }
            }
            $order->status = $status;
            $order->save();
            $this->event($order, ServiceOrder::STATUSES[$status], $user);

            return $order;
        });
    }

    private function lock(ServiceOrder $order, User $user): ServiceOrder
    {
        $order = ServiceOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $this->authorize($order->company, $user);

        return $order;
    }

    public function reverse(ServiceOrder $order, User $user, string $reason): void
    {
        DB::transaction(function () use ($order, $user, $reason) {
            $order = $this->lock($order, $user);
            abort_unless($user->hasActiveRoleInCompany($order->company, CompanyRole::CompanyAdmin, CompanyRole::Manager)
                && app(CompanyPermissionService::class)->allows($user, $order->company, CompanyPermission::ViewFinancial), 403);
            $reason = trim($reason);
            $this->check($reason !== '' && mb_strlen($reason) <= 2000, 'Informe o motivo do estorno (até 2000 caracteres).');
            if ($order->status === 'reversed') {
                return;
            }
            $this->check($order->sale_id !== null, 'Esta OS ainda não gerou venda.');
            $sale = $order->sale;
            $receivables = app(ReceivableService::class);
            foreach ($sale->payments()->where('status', PaymentStatus::Confirmed->value)->get() as $payment) {
                $receivables->cancelPayment($order->company, $payment, $user, $reason);
            }
            if (! $sale->receivable->fresh()->isCancelled()) {
                $receivables->cancel($order->company, $sale->receivable->fresh());
            }
            if ($sale->stockDocument && ! $sale->stockDocument->isReversed()) {
                app(StockDocumentReversalService::class)->reverse($order->company, $sale->stockDocument, $user, $reason);
            }
            $sale->update(['status' => SaleStatus::Cancelled, 'paid_amount' => '0.00', 'outstanding_amount' => '0.00', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $order->update(['status' => 'reversed']);
            $this->event($order, 'Conserto estornado: '.$reason, $user);
        });
    }

    public function payment(ServiceOrder $order, User $user, array $data): void
    {
        DB::transaction(function () use ($order, $user, $data) {
            $order = $this->lock($order, $user);
            abort_unless(app(CompanyModuleService::class)->hasModule($order->company, CompanyModule::Finance)
                && app(CompanyPermissionService::class)->allows($user, $order->company, CompanyPermission::ViewFinancial), 403);
            $data = validator($data, [
                'request_key' => 'required|uuid', 'amount' => 'required|numeric|min:0.01|max:99999999',
                'method' => ['required', Rule::enum(PaymentMethod::class)],
                'financial_account_id' => 'required|integer',
            ])->validate();
            $description = 'Pagamento registrado: '.$data['request_key'];
            if ($order->events()->where('description', $description)->exists()) {
                return;
            }
            $this->check($order->sale_id !== null, 'Conclua o conserto antes de registrar o pagamento.');
            $sale = $order->sale;
            $this->check($sale->status !== SaleStatus::Cancelled, 'A venda foi cancelada.');
            app(ReceivableService::class)->registerPayment($order->company, $sale->receivable,
                new PaymentData(
                    DecimalMoney::round((string) $data['amount']), '0.00', PaymentMethod::from($data['method']), now(), (int) $data['financial_account_id']
                ), $user);
            $receivable = $sale->receivable->fresh();
            $sale->update(['paid_amount' => $receivable->paid_amount, 'outstanding_amount' => $receivable->outstanding_amount,
                'status' => bccomp($receivable->outstanding_amount, '0', 2) === 0 ? SaleStatus::Paid : SaleStatus::Partial]);
            $this->event($order, $description, $user);
        });
    }

    private function event(ServiceOrder $order, string $description, ?User $user): void
    {
        $order->events()->create(['description' => $description, 'user_id' => $user?->id]);
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['service_order' => $message]);
        }
    }
}
