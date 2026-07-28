<?php

namespace App\Filament\App\Resources\Appointments\Concerns;

use App\DataTransferObjects\Financial\AttendanceCompletionData;
use App\DataTransferObjects\Financial\AttendanceMaterialInput;
use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Filament\App\Resources\Attendances\AttendanceResource;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\Professional;
use App\Policies\AttendancePolicy;
use App\Services\Financial\AttendanceCompletionService;
use App\Services\PublicBooking\PublicAppointmentTokenService;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AppointmentStatusService;
use App\Support\CompanyDateTime;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

trait InteractsWithAppointmentActions
{
    /**
     * @return array<Action>
     */
    protected function getAppointmentActions(): array
    {
        return [
            Action::make('copyPublicLink')
                ->label('Link público')
                ->icon('heroicon-o-link')
                ->iconButton()
                ->tooltip('Copiar link público')
                ->size(Size::Small)
                ->visible(fn (): bool => $this->getRecord()->origin === AppointmentOrigin::Online
                    && Gate::allows('view', $this->getRecord()))
                ->action(function (): void {
                    $record = $this->getRecord();
                    $tokenService = app(PublicAppointmentTokenService::class);

                    $tokenService->revoke($record);
                    $plainToken = $tokenService->issue($record->refresh());
                    $manageUrl = route('public.appointment.manage', ['token' => $plainToken]);

                    $this->js('navigator.clipboard.writeText('.json_encode($manageUrl).')');

                    Notification::make()
                        ->success()
                        ->title('Link público copiado')
                        ->body('Um novo link seguro foi gerado.')
                        ->send();
                }),
            Action::make('confirm')
                ->label('Confirmar')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->iconButton()
                ->tooltip('Confirmar agendamento')
                ->size(Size::Small)
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->canBeConfirmed() && Gate::allows('confirm', $this->getRecord()))
                ->action(function (): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();
                    $record = $this->getRecord();

                    app(AppointmentStatusService::class)->confirm($company, auth()->user(), $record);

                    Notification::make()->success()->title('Agendamento confirmado')->send();
                }),
            Action::make('start')
                ->label('Iniciar')
                ->icon('heroicon-o-play')
                ->color('info')
                ->size(Size::Small)
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->status === AppointmentStatus::Confirmed && Gate::allows('start', $this->getRecord()))
                ->action(function (): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();
                    $record = $this->getRecord();

                    app(AppointmentStatusService::class)->start($company, auth()->user(), $record);

                    Notification::make()->success()->title('Atendimento iniciado')->send();
                }),
            Action::make('reschedule')
                ->label('Remarcar')
                ->icon('heroicon-o-calendar-days')
                ->color('warning')
                ->iconButton()
                ->tooltip('Remarcar')
                ->size(Size::Small)
                ->visible(fn (): bool => $this->getRecord()->canBeRescheduled() && Gate::allows('reschedule', $this->getRecord()))
                ->schema([
                    Select::make('professional_id')
                        ->label('Profissional')
                        ->options(fn (): array => self::rescheduleProfessionalOptions($this->getRecord()))
                        ->default(fn (): int => $this->getRecord()->professional_id)
                        ->native(false),
                    DatePicker::make('appointment_date')
                        ->label('Nova data')
                        ->required()
                        ->native(false),
                    TimePicker::make('appointment_time')
                        ->label('Nova hora')
                        ->seconds(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();
                    $record = $this->getRecord();

                    $localStart = CompanyDateTime::parseLocal(
                        $company,
                        $data['appointment_date'],
                        $data['appointment_time'],
                    );

                    $professional = isset($data['professional_id'])
                        ? Professional::query()->findOrFail($data['professional_id'])
                        : null;

                    app(AppointmentService::class)->reschedule($company, auth()->user(), $record, $localStart, $professional);

                    Notification::make()->success()->title('Agendamento remarcado')->send();
                }),
            Action::make('cancel')
                ->label('Cancelar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->iconButton()
                ->tooltip('Cancelar agendamento')
                ->size(Size::Small)
                ->visible(fn (): bool => $this->getRecord()->canBeCancelled() && Gate::allows('cancel', $this->getRecord()))
                ->schema([
                    Textarea::make('cancellation_reason')
                        ->label('Motivo do cancelamento')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();
                    $record = $this->getRecord();

                    app(AppointmentStatusService::class)->cancel(
                        $company,
                        auth()->user(),
                        $record,
                        $data['cancellation_reason'],
                    );

                    Notification::make()->success()->title('Agendamento cancelado')->send();
                }),
            Action::make('no_show')
                ->label('Não compareceu')
                ->icon('heroicon-o-user-minus')
                ->color('gray')
                ->iconButton()
                ->tooltip('Marcar como não compareceu')
                ->size(Size::Small)
                ->requiresConfirmation()
                ->visible(fn (): bool => in_array($this->getRecord()->status, [
                    AppointmentStatus::Pending,
                    AppointmentStatus::Confirmed,
                ], true) && Gate::allows('markNoShow', $this->getRecord()))
                ->action(function (): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();
                    $record = $this->getRecord();

                    app(AppointmentStatusService::class)->markNoShow($company, auth()->user(), $record);

                    Notification::make()->success()->title('Registrado como não compareceu')->send();
                }),
            $this->makeCompleteAppointmentAction(),
        ];
    }

    protected function makeCompleteAppointmentAction(): Action
    {
        return Action::make('complete')
            ->label('Concluir')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->size(Size::Small)
            ->modalWidth(Width::SevenExtraLarge)
            ->visible(fn (): bool => Gate::allows('complete', $this->getRecord()))
            ->fillForm(fn (): array => $this->getCompleteAppointmentFormDefaults())
            ->steps([
                Step::make('Resumo')
                    ->description('Confira os dados do agendamento')
                    ->schema($this->getCompleteAppointmentSummarySchema()),
                Step::make('Valores')
                    ->description('Informe descontos e valores')
                    ->schema($this->getCompleteAppointmentValuesSchema()),
                Step::make('Materiais')
                    ->description('Registre os materiais utilizados')
                    ->schema($this->getCompleteAppointmentMaterialsSchema()),
                Step::make('Pagamentos')
                    ->description('Registre os pagamentos recebidos')
                    ->schema($this->getCompleteAppointmentPaymentsSchema()),
                Step::make('Observações')
                    ->description('Notas do atendimento')
                    ->schema($this->getCompleteAppointmentNotesSchema()),
                Step::make('Confirmação')
                    ->description('Revise antes de concluir')
                    ->schema($this->getCompleteAppointmentConfirmationSchema()),
            ])
            ->action(function (array $data): void {
                /** @var Company $company */
                $company = Filament::getTenant();
                $record = $this->getRecord();

                $attendance = app(AttendanceCompletionService::class)->complete(
                    $company,
                    auth()->user(),
                    $record,
                    $this->buildAttendanceCompletionData($data),
                );

                Notification::make()
                    ->success()
                    ->title('Atendimento concluído')
                    ->body("Atendimento #{$attendance->getKey()} registrado com sucesso.")
                    ->send();

                $this->redirect(
                    AttendanceResource::getUrl('view', [
                        'record' => $attendance,
                    ]),
                );
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCompleteAppointmentFormDefaults(): array
    {
        $record = $this->getRecord()->loadMissing(['client', 'professional', 'service.consumptions.product']);

        return [
            'gross_amount' => (string) $record->price_snapshot,
            'discount_amount' => '0.00',
            'materials' => self::defaultMaterialsForAppointment($record),
            'payments' => [],
            'completed_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    protected function getCompleteAppointmentSummarySchema(): array
    {
        return [
            Placeholder::make('summary_client')
                ->label('Cliente')
                ->content(fn (): string => $this->getRecord()->client_name_snapshot
                    ?? $this->getRecord()->client?->name
                    ?? '—'),
            Placeholder::make('summary_service')
                ->label('Serviço')
                ->content(fn (): string => $this->getRecord()->service_name_snapshot),
            Placeholder::make('summary_professional')
                ->label('Profissional')
                ->content(fn (): string => $this->getRecord()->professional?->name ?? '—'),
            Placeholder::make('summary_datetime')
                ->label('Data e hora')
                ->content(function (): string {
                    /** @var Company $company */
                    $company = Filament::getTenant();
                    $record = $this->getRecord();

                    return CompanyDateTime::formatLocal($company, $record->start_at);
                }),
            Placeholder::make('summary_status')
                ->label('Status')
                ->content(fn (): string => $this->getRecord()->status->label()),
            Placeholder::make('summary_price')
                ->label('Valor previsto')
                ->content(fn (): string => 'R$ '.number_format((float) $this->getRecord()->price_snapshot, 2, ',', '.')),
        ];
    }

    /**
     * @return array<int, Component>
     */
    protected function getCompleteAppointmentValuesSchema(): array
    {
        $canManageFinancial = fn (): bool => (new AttendancePolicy)->viewFinancialDistribution(auth()->user());

        return [
            TextInput::make('gross_amount')
                ->label('Valor bruto')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('R$')
                ->required()
                ->visible($canManageFinancial),
            TextInput::make('discount_amount')
                ->label('Desconto')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('R$')
                ->default('0.00')
                ->required(),
            DateTimePicker::make('completed_at')
                ->label('Concluído em')
                ->default(now())
                ->required()
                ->native(false)
                ->visible($canManageFinancial),
        ];
    }

    /**
     * @return array<int, Component>
     */
    protected function getCompleteAppointmentMaterialsSchema(): array
    {
        return [
            Repeater::make('materials')
                ->label('Materiais')
                ->schema([
                    Select::make('product_id')
                        ->label('Produto')
                        ->options(fn (): array => self::consumableProductOptions())
                        ->searchable()
                        ->required()
                        ->native(false),
                    TextInput::make('planned_quantity')
                        ->label('Qtd. planejada')
                        ->numeric()
                        ->step(0.0001)
                        ->minValue(0)
                        ->default('0'),
                    TextInput::make('quantity')
                        ->label('Qtd. utilizada')
                        ->numeric()
                        ->step(0.0001)
                        ->minValue(0)
                        ->required(),
                    Textarea::make('notes')
                        ->label('Observação')
                        ->rows(2),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->addActionLabel('Adicionar material')
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    protected function getCompleteAppointmentPaymentsSchema(): array
    {
        return [
            Repeater::make('payments')
                ->label('Pagamentos')
                ->schema([
                    TextInput::make('amount')
                        ->label('Valor')
                        ->numeric()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->prefix('R$')
                        ->required(),
                    TextInput::make('fee_amount')
                        ->label('Taxa')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->prefix('R$')
                        ->default('0.00'),
                    Select::make('method')
                        ->label('Forma de pagamento')
                        ->options(PaymentMethod::options())
                        ->required()
                        ->native(false),
                    Select::make('financial_account_id')
                        ->label('Conta financeira')
                        ->options(fn (): array => FinancialAccount::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->required()
                        ->searchable()
                        ->native(false),
                    DateTimePicker::make('paid_at')
                        ->label('Pago em')
                        ->default(now())
                        ->required()
                        ->native(false),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->rows(2),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->addActionLabel('Adicionar pagamento')
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    protected function getCompleteAppointmentNotesSchema(): array
    {
        return [
            Textarea::make('notes')
                ->label('Observações')
                ->rows(3),
            Textarea::make('internal_notes')
                ->label('Observações internas')
                ->rows(3)
                ->visible(fn (): bool => (new AttendancePolicy)->viewFinancialDistribution(auth()->user())),
        ];
    }

    /**
     * @return array<int, Component>
     */
    protected function getCompleteAppointmentConfirmationSchema(): array
    {
        $canViewDistribution = fn (): bool => (new AttendancePolicy)->viewFinancialDistribution(auth()->user());

        return [
            Placeholder::make('confirm_discount')
                ->label('Desconto informado')
                ->content(fn ($get): string => 'R$ '.number_format((float) ($get('discount_amount') ?? 0), 2, ',', '.')),
            Placeholder::make('confirm_materials')
                ->label('Materiais informados')
                ->content(fn ($get): string => (string) count($get('materials') ?? [])),
            Placeholder::make('confirm_payments')
                ->label('Pagamentos informados')
                ->content(fn ($get): string => (string) count($get('payments') ?? [])),
            Placeholder::make('confirm_distribution_notice')
                ->label('Distribuição financeira')
                ->content('Comissões, reservas e resultado operacional serão calculados automaticamente pelo sistema.')
                ->visible($canViewDistribution),
            Placeholder::make('confirm_employee_notice')
                ->label('Confirmação')
                ->content('Os valores finais serão calculados automaticamente com base nas configurações financeiras da empresa.')
                ->visible(fn (): bool => ! $canViewDistribution()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function buildAttendanceCompletionData(array $data): AttendanceCompletionData
    {
        $canManageFinancial = (new AttendancePolicy)->viewFinancialDistribution(auth()->user());

        /** @var list<array<string, mixed>> $materialRows */
        $materialRows = $data['materials'] ?? [];
        $materials = [];

        foreach ($materialRows as $row) {
            if (blank($row['product_id'] ?? null)) {
                continue;
            }

            $materials[] = new AttendanceMaterialInput(
                productId: (int) $row['product_id'],
                plannedQuantity: number_format((float) ($row['planned_quantity'] ?? 0), 4, '.', ''),
                quantity: number_format((float) ($row['quantity'] ?? 0), 4, '.', ''),
                notes: $row['notes'] ?? null,
            );
        }

        /** @var list<array<string, mixed>> $paymentRows */
        $paymentRows = $data['payments'] ?? [];
        $payments = [];

        foreach ($paymentRows as $row) {
            if (blank($row['amount'] ?? null)) {
                continue;
            }

            $payments[] = new PaymentData(
                amount: number_format((float) $row['amount'], 2, '.', ''),
                feeAmount: number_format((float) ($row['fee_amount'] ?? 0), 2, '.', ''),
                method: PaymentMethod::from($row['method']),
                paidAt: Carbon::parse($row['paid_at']),
                financialAccountId: (int) $row['financial_account_id'],
                notes: $row['notes'] ?? null,
            );
        }

        return new AttendanceCompletionData(
            discountAmount: number_format((float) ($data['discount_amount'] ?? 0), 2, '.', ''),
            materials: $materials,
            payments: $payments,
            notes: $data['notes'] ?? null,
            internalNotes: $canManageFinancial ? ($data['internal_notes'] ?? null) : null,
            completedAt: $canManageFinancial && filled($data['completed_at'] ?? null)
                ? Carbon::parse($data['completed_at'])
                : null,
            grossAmount: $canManageFinancial && filled($data['gross_amount'] ?? null)
                ? number_format((float) $data['gross_amount'], 2, '.', '')
                : null,
        );
    }

    /**
     * @return array<int, string>
     */
    protected static function defaultMaterialsForAppointment(Appointment $appointment): array
    {
        $appointment->loadMissing('service.consumptions');

        return $appointment->service?->consumptions
            ->map(fn ($consumption): array => [
                'product_id' => $consumption->product_id,
                'planned_quantity' => (string) $consumption->quantity,
                'quantity' => (string) $consumption->quantity,
                'notes' => $consumption->notes,
            ])
            ->all() ?? [];
    }

    /**
     * @return array<int, string>
     */
    protected static function consumableProductOptions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Product::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->where('type', ProductType::Consumable)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function rescheduleProfessionalOptions(Appointment $record): array
    {
        return Professional::query()
            ->where('company_id', $record->company_id)
            ->active()
            ->bookable()
            ->whereHas('services', fn ($query) => $query
                ->where('services.id', $record->service_id)
                ->where('professional_service.is_active', true))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
