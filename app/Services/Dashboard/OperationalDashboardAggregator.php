<?php

namespace App\Services\Dashboard;

use App\Enums\AppointmentStatus;
use App\Enums\CompanyModule;
use App\Enums\PayableStatus;
use App\Enums\ReceivableStatus;
use App\Enums\SaleStatus;
use App\Enums\StockDocumentStatus;
use App\Enums\StockDocumentType;
use App\Filament\App\Pages\CalendarPage;
use App\Filament\App\Pages\InventoryPosition;
use App\Filament\App\Pages\PointOfSalePage;
use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Filament\App\Resources\Attendances\AttendanceResource;
use App\Filament\App\Resources\Clients\ClientResource;
use App\Filament\App\Resources\Payables\PayableResource;
use App\Filament\App\Resources\Purchases\PurchaseResource;
use App\Filament\App\Resources\Receivables\ReceivableResource;
use App\Filament\App\Resources\Sales\SaleResource;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\PayableInstallment;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\StockDocument;
use App\Services\Company\CompanyModuleService;
use App\Support\CompanyDateTime;
use App\Support\CompanyTerminology;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class OperationalDashboardAggregator
{
    public function __construct(
        protected CompanyModuleService $moduleService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function aggregate(Company $company): array
    {
        $modules = collect($this->moduleService->enabledModules($company))
            ->map(fn (CompanyModule $module): string => $module->value)
            ->all();

        $localToday = CompanyDateTime::nowLocal($company)->startOfDay();
        $todayStart = CarbonImmutable::parse($localToday)->utc();
        $todayEnd = $todayStart->addDay();
        $todayDate = $localToday->toDateString();
        $nextWeekDate = $localToday->addDays(7)->toDateString();

        return [
            'company' => $company,
            'dateLabel' => $localToday->translatedFormat('d/m/Y'),
            'modules' => $modules,
            'quickActions' => $this->quickActions($company, $modules),
            'cards' => $this->cards($company, $modules, $todayStart, $todayEnd, $todayDate, $nextWeekDate),
            'alerts' => $this->alerts($company, $modules, $todayStart, $todayEnd, $todayDate, $nextWeekDate),
            'agenda' => $this->todayAgenda($company, $modules, $todayStart, $todayEnd),
            'sales' => $this->latestSales($company, $modules),
        ];
    }

    /**
     * @param  list<string>  $modules
     * @return list<array{label: string, description: string, url: string}>
     */
    protected function quickActions(Company $company, array $modules): array
    {
        $actions = [
            [
                'label' => 'Novo '.CompanyTerminology::client($company, capitalized: false),
                'description' => 'Cadastre uma pessoa',
                'url' => ClientResource::getUrl('create'),
            ],
        ];

        if ($this->has($modules, CompanyModule::Scheduling)) {
            array_unshift($actions, [
                'label' => 'Novo agendamento',
                'description' => 'Reserve um horário',
                'url' => AppointmentResource::getUrl('create'),
            ]);
        }

        if ($this->has($modules, CompanyModule::Sales)) {
            $actions[] = [
                'label' => 'Nova venda',
                'description' => 'Abra o atendimento no PDV',
                'url' => PointOfSalePage::getUrl(),
            ];
        }

        return $actions;
    }

    /**
     * @param  list<string>  $modules
     * @return list<array<string, string|int>>
     */
    protected function cards(
        Company $company,
        array $modules,
        CarbonImmutable $todayStart,
        CarbonImmutable $todayEnd,
        string $todayDate,
        string $nextWeekDate,
    ): array {
        $cards = [];

        if ($this->has($modules, CompanyModule::Scheduling)) {
            $todayAppointments = Appointment::query()
                ->where('company_id', $company->getKey())
                ->where('start_at', '>=', $todayStart)
                ->where('start_at', '<', $todayEnd)
                ->count();

            $cards[] = $this->card('Agenda hoje', (string) $todayAppointments, 'Agendamentos no dia', 'primary', CalendarPage::getUrl());

            $completedToday = Attendance::query()
                ->where('company_id', $company->getKey())
                ->where('completed_at', '>=', $todayStart)
                ->where('completed_at', '<', $todayEnd)
                ->count();

            $cards[] = $this->card('Atendimentos', (string) $completedToday, 'Finalizados hoje', 'success', AttendanceResource::getUrl());
        }

        if ($this->has($modules, CompanyModule::Finance)) {
            $receivedToday = Receivable::query()
                ->where('company_id', $company->getKey())
                ->whereDate('settled_at', $todayDate)
                ->sum('paid_amount');

            $overdueReceivables = Receivable::query()
                ->where('company_id', $company->getKey())
                ->whereIn('status', [ReceivableStatus::Open, ReceivableStatus::Partial])
                ->whereDate('due_date', '<', $todayDate)
                ->sum('outstanding_amount');

            $upcomingPayables = PayableInstallment::query()
                ->where('company_id', $company->getKey())
                ->whereIn('status', [PayableStatus::Open, PayableStatus::Partial])
                ->whereDate('due_date', '>=', $todayDate)
                ->whereDate('due_date', '<=', $nextWeekDate)
                ->sum('outstanding_amount');

            $cards[] = $this->card('Recebido hoje', $this->money($receivedToday), 'Entradas confirmadas', 'success', ReceivableResource::getUrl());
            $cards[] = $this->card('A receber vencido', $this->money($overdueReceivables), 'Saldo em atraso', 'danger', ReceivableResource::getUrl());
            $cards[] = $this->card('A pagar 7 dias', $this->money($upcomingPayables), 'Próximos vencimentos', 'warning', PayableResource::getUrl());
        }

        if ($this->has($modules, CompanyModule::Sales)) {
            $salesToday = Sale::query()
                ->where('company_id', $company->getKey())
                ->whereIn('status', [SaleStatus::Completed, SaleStatus::Partial, SaleStatus::Paid])
                ->where('sold_at', '>=', $todayStart)
                ->where('sold_at', '<', $todayEnd);

            $cards[] = $this->card('Vendas PDV', $this->money((clone $salesToday)->sum('final_amount')), (clone $salesToday)->count().' venda(s) hoje', 'primary', SaleResource::getUrl());
        }

        if ($this->has($modules, CompanyModule::Stock)) {
            $lowStock = Product::query()
                ->where('company_id', $company->getKey())
                ->where('tracks_stock', true)
                ->with('inventoryBalance')
                ->get()
                ->filter(fn (Product $product): bool => $product->isBelowMinimumStock())
                ->count();

            $cards[] = $this->card('Estoque crítico', (string) $lowStock, 'Abaixo do mínimo ou zerado', 'warning', InventoryPosition::getUrl());
        }

        return $cards;
    }

    /**
     * @param  list<string>  $modules
     * @return list<array<string, string|int>>
     */
    protected function alerts(
        Company $company,
        array $modules,
        CarbonImmutable $todayStart,
        CarbonImmutable $todayEnd,
        string $todayDate,
        string $nextWeekDate,
    ): array {
        $alerts = [];

        if ($this->has($modules, CompanyModule::Scheduling)) {
            $pendingAppointments = Appointment::query()
                ->where('company_id', $company->getKey())
                ->where('status', AppointmentStatus::Pending->value)
                ->where('start_at', '>=', $todayStart)
                ->where('start_at', '<', $todayEnd)
                ->count();

            $alerts[] = $this->alert('Agendamentos aguardando confirmação', $pendingAppointments, 'Confirmar evita horários soltos na agenda.', AppointmentResource::getUrl(), 'warning');
        }

        if ($this->has($modules, CompanyModule::Stock)) {
            $draftPurchases = StockDocument::query()
                ->where('company_id', $company->getKey())
                ->where('type', StockDocumentType::Purchase->value)
                ->where('status', StockDocumentStatus::Draft->value)
                ->count();

            $postedWithoutPayable = StockDocument::query()
                ->where('company_id', $company->getKey())
                ->where('type', StockDocumentType::Purchase->value)
                ->where('status', StockDocumentStatus::Posted->value)
                ->whereDoesntHave('payable')
                ->count();

            $alerts[] = $this->alert('Compras em rascunho', $draftPurchases, 'Rascunhos ainda não entram no estoque.', PurchaseResource::getUrl(), 'danger');
            $alerts[] = $this->alert('Compras sem conta a pagar', $postedWithoutPayable, 'Compras lançadas sem financeiro vinculado.', PurchaseResource::getUrl(), 'warning');
        }

        if ($this->has($modules, CompanyModule::Finance)) {
            $overduePayables = PayableInstallment::query()
                ->where('company_id', $company->getKey())
                ->whereIn('status', [PayableStatus::Open, PayableStatus::Partial])
                ->whereDate('due_date', '<', $todayDate)
                ->count();

            $upcomingReceivables = Receivable::query()
                ->where('company_id', $company->getKey())
                ->whereIn('status', [ReceivableStatus::Open, ReceivableStatus::Partial])
                ->whereDate('due_date', '>=', $todayDate)
                ->whereDate('due_date', '<=', $nextWeekDate)
                ->count();

            $alerts[] = $this->alert('Contas a pagar vencidas', $overduePayables, 'Priorize baixa ou renegociação.', PayableResource::getUrl(), 'danger');
            $alerts[] = $this->alert('Recebimentos nos próximos 7 dias', $upcomingReceivables, 'Acompanhe cobranças abertas.', ReceivableResource::getUrl(), 'primary');
        }

        return collect($alerts)
            ->filter(fn (array $alert): bool => (int) $alert['count'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $modules
     * @return Collection<int, Appointment>
     */
    protected function todayAgenda(Company $company, array $modules, CarbonImmutable $todayStart, CarbonImmutable $todayEnd): Collection
    {
        if (! $this->has($modules, CompanyModule::Scheduling)) {
            return collect();
        }

        return Appointment::query()
            ->where('company_id', $company->getKey())
            ->where('start_at', '>=', $todayStart)
            ->where('start_at', '<', $todayEnd)
            ->with(['client', 'professional', 'service'])
            ->orderBy('start_at')
            ->limit(6)
            ->get();
    }

    /**
     * @param  list<string>  $modules
     * @return Collection<int, Sale>
     */
    protected function latestSales(Company $company, array $modules): Collection
    {
        if (! $this->has($modules, CompanyModule::Sales)) {
            return collect();
        }

        return Sale::query()
            ->where('company_id', $company->getKey())
            ->with('client')
            ->latest('sold_at')
            ->limit(5)
            ->get();
    }

    /**
     * @return array{label: string, value: string, description: string, color: string, url: string}
     */
    protected function card(string $label, string $value, string $description, string $color, string $url): array
    {
        return compact('label', 'value', 'description', 'color', 'url');
    }

    /**
     * @return array{label: string, count: int, description: string, url: string, color: string}
     */
    protected function alert(string $label, int $count, string $description, string $url, string $color): array
    {
        return compact('label', 'count', 'description', 'url', 'color');
    }

    /**
     * @param  list<string>  $modules
     */
    protected function has(array $modules, CompanyModule $module): bool
    {
        return in_array($module->value, $modules, true);
    }

    protected function money(mixed $amount): string
    {
        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    }
}
