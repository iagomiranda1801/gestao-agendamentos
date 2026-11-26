<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Filament\App\Resources\Purchases\Pages\EditPurchase;
use App\Filament\App\Resources\Purchases\PurchaseResource;
use App\Models\Company;
use App\Models\MeasurementUnit;
use App\Models\Product;
use App\Models\User;
use App\Policies\PayablePolicy;
use App\Policies\StockDocumentPolicy;
use App\Services\Company\CompanyModuleService;
use App\Services\Nfe\NfeImportService;
use App\Services\Nfe\NfeParseException;
use App\Services\Nfe\NfeProductMatcher;
use App\Services\Nfe\NfeXmlParser;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ImportNfePage extends Page
{
    use RequiresCompanyModule;

    protected static ?string $slug = 'compras/importar-nfe';

    protected static ?string $title = 'Importar XML da NF-e';

    protected static ?string $navigationLabel = 'Importar XML da NF-e';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected string $view = 'filament.app.pages.import-nfe';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /** Passo atual: upload → review. */
    #[Locked]
    public string $step = 'upload';

    /**
     * Nota lida do XML. Travada: o navegador não pode alterar o que foi lido.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $invoice = [];

    /**
     * Resumo da análise, só com valores simples para o Livewire.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $analysis = [];

    #[Locked]
    public ?int $importCompanyId = null;

    /**
     * Decisões editáveis por item (chave = nItem da nota).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    public bool $createPayable = true;

    public bool $acknowledgeMismatch = false;

    public static function canAccess(): bool
    {
        if (! static::tenantHasRequiredModule()) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && (new StockDocumentPolicy)->create($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->importCompanyId = $this->company()->getKey();
        $this->form->fill();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('xml')
                ->label('Arquivo XML da nota')
                ->helperText('Aceita o XML de NF-e (modelo 55, versão 4.00) enviado pelo fornecedor. Máximo de 5 MB.')
                ->acceptedFileTypes(['text/xml', 'application/xml', 'text/plain', '.xml'])
                ->maxSize(5120)
                ->storeFiles(false)
                ->required(),
        ]);
    }

    public function readXml(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $file = $state['xml'] ?? null;

        if (is_array($file)) {
            $file = reset($file) ?: null;
        }

        if (! $file instanceof TemporaryUploadedFile) {
            $this->addError('data.xml', 'Escolha o arquivo XML da nota.');

            return;
        }

        try {
            $invoice = app(NfeXmlParser::class)->parse((string) file_get_contents($file->getRealPath()));
        } catch (NfeParseException $exception) {
            $this->addError('data.xml', $exception->getMessage());

            return;
        }

        $company = $this->company();
        $analysis = app(NfeImportService::class)->analyze($company, $invoice);

        if ($analysis['duplicate'] !== null) {
            $this->addError('data.xml', 'Esta NF-e já foi importada (compra '.($analysis['duplicate']->document_number ?: '#'.$analysis['duplicate']->getKey()).'). Não é possível importar duas vezes.');

            return;
        }

        if ($analysis['recipient'] === NfeImportService::RECIPIENT_OWN_ISSUER) {
            $this->addError('data.xml', 'Esta nota foi emitida pela sua própria empresa (é uma venda, não uma compra).');

            return;
        }

        $this->invoice = $invoice;
        $this->analysis = [
            'recipient' => $analysis['recipient'],
            'supplier_id' => $analysis['supplier']?->getKey(),
            'supplier_name' => $analysis['supplier']?->name,
            'finance_enabled' => $analysis['finance_enabled'] && $this->canCreatePayable(),
            'installments_total' => $analysis['installments_total'],
            'installments_from_xml' => ($invoice['installments'] ?? []) !== [],
        ];
        $this->rows = $this->buildRows($invoice, $analysis['matches']);
        $this->createPayable = $this->analysis['finance_enabled'];
        $this->acknowledgeMismatch = false;
        $this->step = 'review';
    }

    public function restart(): void
    {
        $this->reset(['invoice', 'analysis', 'rows', 'acknowledgeMismatch']);
        $this->step = 'upload';
        $this->form->fill();
    }

    public function confirmImport(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->step === 'review' && $this->invoice !== [], 422);

        $company = $this->company();
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $decisions = [];

        foreach ($this->rows as $index => $row) {
            $decisions[(int) $index] = [
                'action' => $row['action'] ?? 'ignore',
                'product_id' => filled($row['product_id'] ?? null) ? (int) $row['product_id'] : null,
                'factor' => $row['factor'] ?? '1',
                'remember' => (bool) ($row['remember'] ?? true),
                'new' => [
                    'name' => $row['new_name'] ?? null,
                    'barcode' => $row['new_barcode'] ?? null,
                    'sale_price' => $row['new_sale_price'] ?? null,
                    'menu_category' => $row['new_category'] ?? null,
                ],
            ];
        }

        try {
            $result = app(NfeImportService::class)->import($company, $user, $this->invoice, $decisions, [
                'create_payable' => $this->createPayable && ($this->analysis['finance_enabled'] ?? false),
                'acknowledge_recipient_mismatch' => $this->acknowledgeMismatch,
            ]);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'Não foi possível importar a nota.';

            Notification::make()->danger()->title('Importação não concluída')->body($message)->persistent()->send();
            $this->addError('import', $message);

            return;
        }

        $body = collect([
            'Estoque atualizado com '.$result->document->items()->count().' produto(s).',
            $result->createdProducts > 0 ? $result->createdProducts.' produto(s) novo(s) cadastrado(s).' : null,
            $result->payable !== null ? 'Contas a pagar geradas: '.$result->payable->installments()->count().' parcela(s).' : null,
            $result->ignoredItems > 0 ? $result->ignoredItems.' item(ns) ignorado(s).' : null,
        ])->filter()->implode(' ');

        Notification::make()->success()->title('NF-e importada')->body($body)->persistent()->send();

        $this->redirect(EditPurchase::getUrl(['record' => $result->document]));
    }

    public function updatedRows(mixed $value, string $path): void
    {
        // Ao trocar a ação para "criar", garante que o nome tenha valor.
        [$index, $field] = array_pad(explode('.', $path, 2), 2, null);

        if ($field === 'action' && $value === 'create' && blank($this->rows[$index]['new_name'] ?? null)) {
            $this->rows[$index]['new_name'] = collect($this->invoice['items'])->firstWhere('index', (int) $index)['name'] ?? '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $products = [];
        $units = [];

        if ($this->step === 'review') {
            $products = Product::query()
                ->where('company_id', $this->company()->getKey())
                ->active()
                ->tracksStock()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
            $units = MeasurementUnit::query()->active()->orderBy('name')->pluck('name', 'id')->all();
        }

        return [
            'stockProducts' => $products,
            'measurementUnits' => $units,
            'previews' => $this->step === 'review' ? $this->previews() : [],
            'installments' => $this->step === 'review' ? app(NfeImportService::class)->installmentsFor($this->invoice) : [],
            'ordersEnabled' => app(CompanyModuleService::class)->hasModule($this->company(), CompanyModule::Orders),
            'purchasesUrl' => PurchaseResource::getUrl('index'),
        ];
    }

    /**
     * Quantidade e custo unitário que vão para o estoque, conforme o fator digitado.
     *
     * @return array<int, array{quantity: string, unit_cost: string}|null>
     */
    protected function previews(): array
    {
        $previews = [];

        foreach ($this->invoice['items'] ?? [] as $item) {
            $factor = str_replace(',', '.', trim((string) ($this->rows[$item['index']]['factor'] ?? '1')));

            if (! is_numeric($factor) || bccomp($factor, '0', 4) <= 0 || bccomp($factor, '100000', 4) > 0) {
                $previews[$item['index']] = null;

                continue;
            }

            $quantity = bcmul((string) $item['quantity'], $factor, 4);
            $previews[$item['index']] = bccomp($quantity, '0', 4) > 0
                ? ['quantity' => $quantity, 'unit_cost' => bcdiv((string) $item['cost_total'], $quantity, 4)]
                : null;
        }

        return $previews;
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  array<int, array{source: string, product_id: ?int, factor: string, score: ?int}>  $matches
     * @return array<int, array<string, mixed>>
     */
    protected function buildRows(array $invoice, array $matches): array
    {
        $rows = [];

        foreach ($invoice['items'] as $item) {
            $match = $matches[$item['index']] ?? ['source' => NfeProductMatcher::SOURCE_NONE, 'product_id' => null, 'factor' => '1', 'score' => null];
            $matched = $match['product_id'] !== null;

            $rows[$item['index']] = [
                'action' => $matched ? 'match' : 'create',
                'source' => $match['source'],
                'score' => $match['score'],
                'product_id' => $match['product_id'],
                'factor' => $match['factor'],
                'remember' => true,
                'new_name' => $item['name'],
                'new_barcode' => $item['ean'] ?? $item['ean_tax'] ?? '',
                'new_sale_price' => '',
                'new_category' => '',
            ];
        }

        return $rows;
    }

    protected function canCreatePayable(): bool
    {
        $user = auth()->user();

        return $user !== null && (new PayablePolicy)->create($user);
    }

    protected function company(): Company
    {
        $company = Filament::getTenant();
        abort_unless($company instanceof Company, 404);

        if ($this->importCompanyId !== null) {
            abort_unless((int) $company->getKey() === $this->importCompanyId, 403);
        }

        return $company;
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Stock;
    }
}
