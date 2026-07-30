<?php

namespace App\Filament\App\Resources\WhatsAppInstances\Tables;

use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Http\Client\RequestException;
use Throwable;

class WhatsAppInstancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('instance_name')
                    ->label('Instância')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sender_phone')
                    ->label('Número')
                    ->toggleable(),
                ViewColumn::make('qr_code')
                    ->label('QR code')
                    ->view('filament.app.resources.whatsapp-instances.tables.qr-code'),
                TextColumn::make('status')
                    ->label('Status')
                    ->placeholder('Não verificado')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'open', 'connected' => 'success',
                        'qrcode', 'connecting' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_default')
                    ->label('Padrão')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray'),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                EditAction::make(),
                Action::make('generateQr')
                    ->label('Gerar QR')
                    ->icon('heroicon-o-qr-code')
                    ->requiresConfirmation()
                    ->action(function (CompanyWhatsAppInstance $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        try {
                            app(CompanyWhatsAppInstanceService::class)->createOrRefreshQrCode($company, $record);

                            Notification::make()
                                ->success()
                                ->title('QR code atualizado')
                                ->send();
                        } catch (Throwable $exception) {
                            report($exception);
                            $record->update(['status' => 'error']);

                            Notification::make()
                                ->danger()
                                ->title('Não foi possível criar a instância na Evolution')
                                ->body(self::evolutionErrorMessage($exception))
                                ->send();
                        }
                    }),
                Action::make('refreshState')
                    ->label('Atualizar status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function (CompanyWhatsAppInstance $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        try {
                            app(CompanyWhatsAppInstanceService::class)->refreshConnectionState($company, $record);

                            Notification::make()
                                ->success()
                                ->title('Status atualizado')
                                ->send();
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->danger()
                                ->title('Não foi possível atualizar o status')
                                ->body(self::evolutionErrorMessage($exception))
                                ->send();
                        }
                    }),
            ])
            ->searchable();
    }

    protected static function evolutionErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof RequestException && $exception->response->status() === 401) {
            return 'A chave atual não tem permissão para criar instâncias. Use a chave global AUTHENTICATION_API_KEY da Evolution em EVOLUTION_API_KEY.';
        }

        return 'Revise a URL e a chave da Evolution e tente novamente.';
    }
}
