<?php

namespace App\Services\Nfe;

use App\Enums\CompanyModule;
use App\Enums\ExpenseCategoryType;
use App\Enums\PayableOrigin;
use App\Enums\ProductType;
use App\Enums\StockDocumentType;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\MeasurementUnit;
use App\Models\Payable;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\SupplierProductMapping;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use App\Services\Financial\ExpenseCategoryService;
use App\Services\Financial\PayableService;
use App\Services\Product\ProductService;
use App\Services\Stock\StockDocumentPostingService;
use App\Services\Stock\StockDocumentService;
use App\Services\Supplier\SupplierService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NfeImportService
{
    public const RECIPIENT_MATCH = 'match';

    public const RECIPIENT_MISMATCH = 'mismatch';

    public const RECIPIENT_UNKNOWN = 'unknown';

    public const RECIPIENT_OWN_ISSUER = 'own_issuer';

    public function __construct(
        protected NfeProductMatcher $matcher,
        protected StockDocumentService $documents,
        protected StockDocumentPostingService $posting,
        protected ProductService $products,
        protected SupplierService $suppliers,
        protected PayableService $payables,
        protected CompanyModuleService $modules,
    ) {}

    /**
     * Tudo o que a tela de revisão precisa saber, sem gravar nada.
     *
     * @param  array<string, mixed>  $invoice
     * @return array{
     *     duplicate: ?StockDocument,
     *     recipient: string,
     *     supplier: ?Supplier,
     *     matches: array<int, array{source: string, product_id: ?int, factor: string, score: ?int}>,
     *     finance_enabled: bool,
     *     installments: list<array{number: string, due_date: string, amount: string}>,
     *     installments_total: string
     * }
     */
    public function analyze(Company $company, array $invoice): array
    {
        $supplier = $this->findSupplier($company, $invoice['issuer']['document'] ?? null);
        $installments = $this->installmentsFor($invoice);

        return [
            'duplicate' => $this->findDuplicate($company, (string) $invoice['access_key']),
            'recipient' => $this->recipientStatus($company, $invoice),
            'supplier' => $supplier,
            'matches' => $this->matcher->matchAll($company, $supplier, $invoice['items']),
            'finance_enabled' => $this->modules->hasModule($company, CompanyModule::Finance),
            'installments' => $installments,
            'installments_total' => $this->sumAmounts($installments),
        ];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  array<int, array<string, mixed>>  $decisions  indexado pelo nItem da NF-e
     * @param  array{create_payable?: bool, expense_category_id?: int|string|null, acknowledge_recipient_mismatch?: bool}  $options
     */
    public function import(Company $company, User $user, array $invoice, array $decisions, array $options = []): NfeImportResult
    {
        try {
            return DB::transaction(fn (): NfeImportResult => $this->performImport($company, $user, $invoice, $decisions, $options));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'xml' => 'Esta NF-e acabou de ser importada por outra pessoa. Confira em Compras.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  array<int, array<string, mixed>>  $decisions
     * @param  array<string, mixed>  $options
     */
    protected function performImport(Company $company, User $user, array $invoice, array $decisions, array $options): NfeImportResult
    {
        $key = (string) ($invoice['access_key'] ?? '');

        if (strlen($key) !== 44) {
            throw ValidationException::withMessages(['xml' => 'Chave de acesso da NF-e inválida.']);
        }

        // Trava a leitura para que duas importações simultâneas da mesma chave não passem juntas.
        $existing = StockDocument::query()
            ->where('company_id', $company->getKey())
            ->where('nfe_access_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'xml' => 'Esta NF-e já foi importada (compra '.($existing->document_number ?: '#'.$existing->getKey()).').',
            ]);
        }

        $this->assertRecipient($company, $invoice, (bool) ($options['acknowledge_recipient_mismatch'] ?? false));

        $supplier = $this->findSupplier($company, $invoice['issuer']['document'] ?? null)
            ?? $this->createSupplier($company, $invoice);

        [$lines, $created, $ignored] = $this->resolveLines($company, $invoice, $decisions);

        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => 'Nenhum item foi selecionado para entrar no estoque.',
            ]);
        }

        $document = $this->documents->createDraft(
            $company,
            StockDocumentType::Purchase,
            [
                'supplier_id' => $supplier?->getKey(),
                'occurred_at' => now(),
                'document_number' => $this->documentLabel($invoice),
                'external_reference' => $key,
                'nfe_access_key' => $key,
                'notes' => $this->documentNotes($invoice),
            ],
            $this->mergeLines($lines),
            $user,
        );

        $document = $this->posting->post($company, $document, $user);

        $payable = null;

        if (($options['create_payable'] ?? false) && $this->modules->hasModule($company, CompanyModule::Finance)) {
            $payable = $this->createPayable(
                $company,
                $user,
                $document,
                $supplier,
                $invoice,
                $options['expense_category_id'] ?? null,
            );
        }

        $mappings = $supplier === null ? 0 : $this->saveMappings($company, $supplier, $lines);

        return new NfeImportResult(
            document: $document->refresh(),
            supplier: $supplier,
            payable: $payable,
            createdProducts: $created,
            mappingsSaved: $mappings,
            ignoredItems: $ignored,
        );
    }

    public function findDuplicate(Company $company, string $accessKey): ?StockDocument
    {
        return StockDocument::query()
            ->where('company_id', $company->getKey())
            ->where('nfe_access_key', $accessKey)
            ->first();
    }

    public function findSupplier(Company $company, ?string $document): ?Supplier
    {
        $digits = preg_replace('/\D+/', '', (string) $document);

        if ($digits === '' || $digits === null) {
            return null;
        }

        return Supplier::query()
            ->where('company_id', $company->getKey())
            ->whereNotNull('document')
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(document, '.', ''), '/', ''), '-', ''), ' ', '') = ?", [$digits])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function recipientStatus(Company $company, array $invoice): string
    {
        $own = $this->digits($company->document);

        if ($own === null) {
            return self::RECIPIENT_UNKNOWN;
        }

        if ($this->digits($invoice['issuer']['document'] ?? null) === $own) {
            return self::RECIPIENT_OWN_ISSUER;
        }

        $recipient = $this->digits($invoice['recipient']['document'] ?? null);

        if ($recipient === null) {
            return self::RECIPIENT_UNKNOWN;
        }

        return $recipient === $own ? self::RECIPIENT_MATCH : self::RECIPIENT_MISMATCH;
    }

    /**
     * Duplicatas da nota, ou uma parcela única do valor total vencendo hoje.
     *
     * @param  array<string, mixed>  $invoice
     * @return list<array{number: string, due_date: string, amount: string}>
     */
    public function installmentsFor(array $invoice): array
    {
        $installments = $invoice['installments'] ?? [];

        if (is_array($installments) && $installments !== []) {
            return array_values($installments);
        }

        return [[
            'number' => '001',
            'due_date' => now()->toDateString(),
            'amount' => bcadd((string) ($invoice['totals']['invoice'] ?? '0'), '0', 2),
        ]];
    }

    /**
     * @param  list<array{amount: string}>  $installments
     */
    protected function sumAmounts(array $installments): string
    {
        return array_reduce(
            $installments,
            fn (string $carry, array $installment): string => bcadd($carry, (string) $installment['amount'], 2),
            '0.00',
        );
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    protected function assertRecipient(Company $company, array $invoice, bool $acknowledged): void
    {
        $status = $this->recipientStatus($company, $invoice);

        if ($status === self::RECIPIENT_OWN_ISSUER) {
            throw ValidationException::withMessages([
                'xml' => 'Esta nota foi emitida pela sua própria empresa (é uma venda, não uma compra).',
            ]);
        }

        if ($status === self::RECIPIENT_MISMATCH && ! $acknowledged) {
            throw ValidationException::withMessages([
                'xml' => 'O destinatário da nota não é o CNPJ/CPF desta empresa. Confirme que quer importar mesmo assim.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    protected function createSupplier(Company $company, array $invoice): ?Supplier
    {
        $issuer = $invoice['issuer'] ?? [];
        $document = $this->digits($issuer['document'] ?? null);

        if ($document === null) {
            return null;
        }

        $name = trim((string) ($issuer['name'] ?? '')) ?: trim((string) ($issuer['trade_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $notes = collect([
            'Cadastrado automaticamente pela importação da NF-e '.$this->documentLabel($invoice).'.',
            filled($issuer['address'] ?? null) ? 'Endereço: '.$issuer['address'] : null,
        ])->filter()->implode("\n");

        return $this->suppliers->create($company, [
            'name' => Str::limit($name, 255, ''),
            'trade_name' => filled($issuer['trade_name'] ?? null) ? Str::limit((string) $issuer['trade_name'], 255, '') : null,
            'document' => $this->formatDocument($document),
            'phone' => filled($issuer['phone'] ?? null) ? $issuer['phone'] : null,
            'notes' => $notes,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  array<int, array<string, mixed>>  $decisions
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    protected function resolveLines(Company $company, array $invoice, array $decisions): array
    {
        $lines = [];
        $created = 0;
        $ignored = 0;

        foreach ($invoice['items'] as $item) {
            $decision = $decisions[(int) $item['index']] ?? [];
            $action = (string) ($decision['action'] ?? 'ignore');

            if ($action === 'ignore') {
                $ignored++;

                continue;
            }

            $label = 'Item '.$item['index'].' ('.Str::limit((string) $item['name'], 40).')';

            $factor = $this->normalizeFactor($decision['factor'] ?? '1', $label);
            $stockQuantity = bcmul((string) $item['quantity'], $factor, 4);

            if (bccomp($stockQuantity, '0', 4) <= 0) {
                throw ValidationException::withMessages(['items' => "{$label}: a quantidade no estoque ficou zerada."]);
            }

            $unitCost = $this->divideRounded((string) $item['cost_total'], $stockQuantity, 6);

            if ($action === 'match') {
                $product = $this->resolveStockProduct($company, (int) ($decision['product_id'] ?? 0), $label);
            } elseif ($action === 'create') {
                $product = $this->createProduct($company, $item, (array) ($decision['new'] ?? []), $unitCost, $label);
                $created++;
            } else {
                throw ValidationException::withMessages(['items' => "{$label}: ação inválida."]);
            }

            $lines[] = [
                'item' => $item,
                'product' => $product,
                'factor' => $factor,
                'quantity' => $stockQuantity,
                'cost_total' => (string) $item['cost_total'],
                'remember' => (bool) ($decision['remember'] ?? true),
            ];
        }

        return [$lines, $created, $ignored];
    }

    protected function normalizeFactor(mixed $factor, string $label): string
    {
        $factor = is_string($factor) ? str_replace(',', '.', trim($factor)) : (string) $factor;

        if ($factor === '' || ! is_numeric($factor) || bccomp($factor, '0', 4) <= 0 || bccomp($factor, '100000', 4) > 0) {
            throw ValidationException::withMessages([
                'items' => "{$label}: informe um fator de conversão maior que zero.",
            ]);
        }

        return bcadd($factor, '0', 4);
    }

    protected function resolveStockProduct(Company $company, int $productId, string $label): Product
    {
        $product = Product::query()
            ->where('company_id', $company->getKey())
            ->whereKey($productId)
            ->active()
            ->tracksStock()
            ->first();

        if ($product === null) {
            throw ValidationException::withMessages([
                'items' => "{$label}: escolha um produto ativo e com controle de estoque.",
            ]);
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $new
     */
    protected function createProduct(Company $company, array $item, array $new, string $unitCost, string $label): Product
    {
        $name = trim((string) ($new['name'] ?? '')) ?: trim((string) $item['name']);
        $salePrice = $new['sale_price'] ?? null;
        $salePrice = is_string($salePrice) ? str_replace(',', '.', trim($salePrice)) : $salePrice;
        $salePrice = is_numeric($salePrice) && bccomp((string) $salePrice, '0', 2) > 0
            ? bcadd((string) $salePrice, '0', 2)
            : '0.00';

        $payload = [
            'name' => Str::limit($name, 255, ''),
            'barcode' => filled($new['barcode'] ?? null) ? preg_replace('/\D+/', '', (string) $new['barcode']) ?: null : null,
            'type' => bccomp($salePrice, '0', 2) > 0 ? ProductType::Sale->value : ProductType::Consumable->value,
            'measurement_unit_id' => $this->resolveUnitId($new['measurement_unit_id'] ?? null, (string) $item['unit']),
            'reference_unit_cost' => $unitCost,
            'sale_price' => $salePrice,
            'minimum_stock' => 0,
            'tracks_stock' => true,
            'is_active' => true,
        ];

        $category = trim((string) ($new['menu_category'] ?? ''));

        if ($category !== '' && bccomp($salePrice, '0', 2) > 0 && $this->modules->hasModule($company, CompanyModule::Orders)) {
            $payload['online_order_category'] = $category;
        }

        try {
            return $this->products->create($company, $payload);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'items' => "{$label}: ".collect($exception->errors())->flatten()->first(),
            ]);
        }
    }

    protected function resolveUnitId(mixed $requested, string $invoiceUnit): int
    {
        if (filled($requested)) {
            $unit = MeasurementUnit::query()->active()->find((int) $requested);

            if ($unit !== null) {
                return (int) $unit->getKey();
            }
        }

        return $this->defaultUnitId($invoiceUnit);
    }

    public function defaultUnitId(string $invoiceUnit): int
    {
        $code = match (Str::upper(trim($invoiceUnit))) {
            'G', 'GR', 'GRAMA', 'GRAMAS' => 'gram',
            'ML' => 'milliliter',
            default => 'unit',
        };

        $unit = MeasurementUnit::query()->active()->where('code', $code)->first()
            ?? MeasurementUnit::query()->active()->orderBy('id')->first();

        if ($unit === null) {
            throw ValidationException::withMessages([
                'items' => 'Nenhuma unidade de medida ativa cadastrada para criar o produto.',
            ]);
        }

        return (int) $unit->getKey();
    }

    /**
     * O estoque não aceita o mesmo produto duas vezes no documento: soma
     * quantidades e custos e recalcula o custo unitário médio da nota.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    protected function mergeLines(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $line['product'];
            $id = (int) $product->getKey();
            $note = 'NF-e item '.$line['item']['index'].': '.$line['item']['code'].' — '.$line['item']['name']
                .' ('.$this->trimNumber((string) $line['item']['quantity']).' '.$line['item']['unit']
                .($line['factor'] !== '1.0000' ? ' × '.$this->trimNumber($line['factor']) : '').')';

            if (! isset($merged[$id])) {
                $merged[$id] = ['product_id' => $id, 'quantity' => '0', 'cost' => '0', 'notes' => []];
            }

            $merged[$id]['quantity'] = bcadd($merged[$id]['quantity'], $line['quantity'], 4);
            $merged[$id]['cost'] = bcadd($merged[$id]['cost'], $line['cost_total'], 6);
            $merged[$id]['notes'][] = $note;
        }

        return array_values(array_map(fn (array $row): array => [
            'product_id' => $row['product_id'],
            'quantity' => $row['quantity'],
            'unit_cost' => $this->divideRounded($row['cost'], $row['quantity'], 6),
            'notes' => implode("\n", $row['notes']),
        ], $merged));
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    protected function createPayable(
        Company $company,
        User $user,
        StockDocument $document,
        ?Supplier $supplier,
        array $invoice,
        int|string|null $categoryId,
    ): Payable {
        $installments = $this->installmentsFor($invoice);
        $total = $this->sumAmounts($installments);
        $issued = CarbonImmutable::parse((string) $invoice['issued_at'])->toDateString();
        $category = $this->resolveExpenseCategory($company, $categoryId);

        $payable = $this->payables->createDraft($company, $category, [
            'origin' => PayableOrigin::StockPurchase,
            'description' => 'NF-e '.$this->documentLabel($invoice).' — '.($supplier?->name ?? ($invoice['issuer']['name'] ?? 'fornecedor')),
            'document_number' => (string) $invoice['number'],
            'external_reference' => (string) $invoice['access_key'],
            'reference_key' => "stock-purchase:{$document->getKey()}:payable",
            'issue_date' => $issued,
            'competence_date' => $issued,
            'total_amount' => $total,
            'notes' => 'Gerada pela importação do XML da NF-e.',
            'stock_document_id' => $document->getKey(),
        ], $user, $supplier);

        $this->payables->createInstallments($company, $payable, array_map(
            fn (array $installment, int $position): array => [
                'installment_number' => $position + 1,
                'due_date' => $installment['due_date'],
                'amount' => $installment['amount'],
                'notes' => 'Duplicata '.$installment['number'].' da NF-e.',
            ],
            $installments,
            array_keys($installments),
        ));

        return $this->payables->launch($company, $payable->refresh());
    }

    protected function resolveExpenseCategory(Company $company, int|string|null $categoryId): ExpenseCategory
    {
        if (filled($categoryId)) {
            $category = ExpenseCategory::query()
                ->where('company_id', $company->getKey())
                ->where('is_active', true)
                ->find((int) $categoryId);

            if ($category === null) {
                throw ValidationException::withMessages([
                    'expense_category_id' => 'Escolha uma categoria de despesa ativa.',
                ]);
            }

            return $category;
        }

        return $this->stockPurchaseCategory($company);
    }

    public function stockPurchaseCategory(Company $company): ExpenseCategory
    {
        $category = ExpenseCategory::query()
            ->where('company_id', $company->getKey())
            ->where('type', ExpenseCategoryType::StockPurchase->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($category !== null) {
            return $category;
        }

        return app(ExpenseCategoryService::class)->create($company, [
            'name' => ExpenseCategory::query()->where('company_id', $company->getKey())->where('name', 'Compra de estoque')->exists()
                ? 'Compra de estoque (NF-e)'
                : 'Compra de estoque',
            'type' => ExpenseCategoryType::StockPurchase->value,
            'description' => 'Compras de mercadoria para estoque.',
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function saveMappings(Company $company, Supplier $supplier, array $lines): int
    {
        $saved = 0;

        foreach ($lines as $line) {
            if (! $line['remember']) {
                continue;
            }

            /** @var Product $product */
            $product = $line['product'];
            $item = $line['item'];

            $mapping = SupplierProductMapping::query()
                ->where('company_id', $company->getKey())
                ->where('supplier_id', $supplier->getKey())
                ->where('supplier_code', (string) $item['code'])
                ->first() ?? new SupplierProductMapping;

            $mapping->forceFill([
                'company_id' => $company->getKey(),
                'supplier_id' => $supplier->getKey(),
                'supplier_code' => (string) $item['code'],
                'supplier_description' => Str::limit((string) $item['name'], 255, ''),
                'supplier_unit' => Str::limit((string) $item['unit'], 10, ''),
                'product_id' => $product->getKey(),
                'conversion_factor' => $line['factor'],
            ])->save();

            $saved++;
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function documentLabel(array $invoice): string
    {
        $label = (string) $invoice['number'];

        return filled($invoice['series'] ?? null) ? "{$label}/{$invoice['series']}" : $label;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    protected function documentNotes(array $invoice): string
    {
        $issued = CarbonImmutable::parse((string) $invoice['issued_at'])->format('d/m/Y');

        return implode("\n", array_filter([
            "Importada do XML da NF-e {$this->documentLabel($invoice)} (emissão {$issued}).",
            'Chave de acesso: '.$invoice['access_key'],
            filled($invoice['nature'] ?? null) ? 'Natureza da operação: '.$invoice['nature'] : null,
        ]));
    }

    /** Divisão com arredondamento para o mais próximo (bcdiv só trunca). */
    protected function divideRounded(string $dividend, string $divisor, int $scale): string
    {
        $quotient = bcdiv($dividend, $divisor, $scale + 1);

        return bcadd(bcadd($quotient, '0.'.str_repeat('0', $scale).'5', $scale + 1), '0', $scale);
    }

    protected function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' || $digits === null ? null : $digits;
    }

    protected function formatDocument(string $digits): string
    {
        return match (strlen($digits)) {
            14 => preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits) ?? $digits,
            11 => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits) ?? $digits,
            default => $digits,
        };
    }

    protected function trimNumber(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
