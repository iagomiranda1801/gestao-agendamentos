<?php

namespace App\Filament\App\Resources\Attendances\Schemas;

use App\Models\Attendance;
use App\Models\Company;
use App\Policies\AttendancePolicy;
use App\Support\CompanyDateTime;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttendanceForm
{
    public static function configure(Schema $schema, bool $readOnly = true): Schema
    {
        $canViewDistribution = fn (): bool => (new AttendancePolicy)->viewFinancialDistribution(auth()->user());

        return $schema
            ->components([
                Section::make('Informações gerais')
                    ->schema([
                        Placeholder::make('completed_at_label')
                            ->label('Concluído em')
                            ->content(function (?Attendance $record): string {
                                if (! $record?->completed_at) {
                                    return '—';
                                }

                                /** @var Company $company */
                                $company = Filament::getTenant();

                                return CompanyDateTime::formatLocal($company, $record->completed_at);
                            }),
                        Placeholder::make('client_name_snapshot')
                            ->label('Cliente')
                            ->content(fn (?Attendance $record): string => $record?->client_name_snapshot ?? '—'),
                        Placeholder::make('service_name_snapshot')
                            ->label('Serviço')
                            ->content(fn (?Attendance $record): string => $record?->service_name_snapshot ?? '—'),
                        Placeholder::make('professional_name_snapshot')
                            ->label('Profissional')
                            ->content(fn (?Attendance $record): string => $record?->professional_name_snapshot ?? '—'),
                        Placeholder::make('completedBy.name')
                            ->label('Concluído por')
                            ->content(fn (?Attendance $record): string => $record?->completedBy?->name ?? '—'),
                    ])
                    ->columns(2),
                Section::make('Valores')
                    ->schema([
                        Placeholder::make('gross_amount')
                            ->label('Valor bruto')
                            ->content(fn (?Attendance $record): string => self::formatMoney($record?->gross_amount)),
                        Placeholder::make('discount_amount')
                            ->label('Desconto')
                            ->content(fn (?Attendance $record): string => self::formatMoney($record?->discount_amount)),
                        Placeholder::make('final_amount')
                            ->label('Valor final')
                            ->content(fn (?Attendance $record): string => self::formatMoney($record?->final_amount)),
                        Placeholder::make('commission_amount')
                            ->label('Comissão')
                            ->content(fn (?Attendance $record): string => self::formatMoney($record?->commission_amount)),
                        Placeholder::make('actual_material_cost')
                            ->label('Custo de materiais')
                            ->content(fn (?Attendance $record): string => self::formatMoney($record?->actual_material_cost)),
                    ])
                    ->columns(2),
                Section::make('Distribuição gerencial')
                    ->schema([
                        Placeholder::make('materials_reserve_amount')
                            ->label('Reserva de materiais')
                            ->content(fn (?Attendance $record): string => self::formatMoney($record?->materials_reserve_amount)),
                        Placeholder::make('business_reserve_amount')
                            ->label('Reserva do negócio')
                            ->content(fn (?Attendance $record): string => self::formatMoney($record?->business_reserve_amount)),
                        Placeholder::make('owner_allocation_amount')
                            ->label('Parcela do proprietário')
                            ->content(fn (?Attendance $record): string => self::formatMoney($record?->owner_allocation_amount)),
                        Placeholder::make('payment_fee_amount')
                            ->label('Taxas de pagamento')
                            ->content(fn (?Attendance $record): string => self::formatMoney($record?->payment_fee_amount)),
                        Placeholder::make('operational_result')
                            ->label('Resultado operacional')
                            ->content(fn (?Attendance $record): string => self::formatMoney($record?->operational_result)),
                    ])
                    ->columns(2)
                    ->visible($canViewDistribution),
                Section::make('Observações')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(3)
                            ->disabled($readOnly),
                        Textarea::make('internal_notes')
                            ->label('Observações internas')
                            ->rows(3)
                            ->disabled($readOnly)
                            ->visible($canViewDistribution),
                    ]),
            ]);
    }

    protected static function formatMoney(mixed $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    }
}
