<?php

namespace App\Filament\App\Resources\ServiceOrders;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Models\Client;
use App\Models\ServiceOrder;
use App\Services\ServiceOrders\ServiceOrderService;
use App\Support\PhoneNormalizer;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class ServiceOrderResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = ServiceOrder::class;

    protected static ?string $slug = 'ordens-de-servico';

    protected static ?string $modelLabel = 'ordem de serviço';

    protected static ?string $pluralModelLabel = 'Ordens de serviço';

    protected static ?string $navigationLabel = 'Consertos';

    protected static string|UnitEnum|null $navigationGroup = 'Assistência técnica';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::ServiceOrders;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Receber aparelho')->schema([
                Select::make('client_id')->label('Cliente — procure pelo nome ou telefone')
                    ->visibleOn('create')
                    ->relationship('client', 'name', fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id))
                    ->getOptionLabelFromRecordUsing(fn (Client $record) => $record->name.' · '.$record->phone)
                    ->searchable(['name', 'phone', 'phone_normalized'])->preload()->required()->disabledOn('edit')
                    ->createOptionForm([
                        TextInput::make('name')->label('Nome')->required()->maxLength(255),
                        TextInput::make('phone')->label('Telefone com DDD')->tel()->required()->maxLength(30),
                        Toggle::make('whatsapp_confirmation_opt_in')->label('Cliente autoriza mensagens sobre o conserto pelo WhatsApp')->default(false),
                    ])->createOptionUsing(function (array $data): int {
                        $company = Filament::getTenant();
                        app(ServiceOrderService::class)->authorize($company, auth()->user());
                        $phone = PhoneNormalizer::normalize($data['phone']);
                        if (! $phone) {
                            throw ValidationException::withMessages(['phone' => 'Informe um telefone válido com DDD.']);
                        }
                        if (Client::where('company_id', $company->id)->where('phone_normalized', $phone)->exists()) {
                            throw ValidationException::withMessages(['phone' => 'Já existe cliente com este telefone. Feche este cadastro e selecione o cliente na busca.']);
                        }
                        $client = new Client($data);
                        $client->company_id = $company->id;
                        $client->save();

                        return $client->id;
                    }),
                Placeholder::make('received_client')->label('Cliente')->visibleOn('edit')->content(fn (?ServiceOrder $record) => $record?->client?->name.' · '.$record?->client?->phone),
                TextInput::make('device')->label('Aparelho')->placeholder('Ex.: iPhone 13 azul')->required()->maxLength(255)->disabledOn('edit'),
                Textarea::make('problem')->label('Problema informado pelo cliente')->required()->maxLength(5000)->disabledOn('edit')->columnSpanFull(),
                Placeholder::make('stage')->label('Etapa')->content(fn (?ServiceOrder $record) => ServiceOrder::STATUSES[$record?->status ?? 'analysis']),
            ])->columns(2)->columnSpanFull(),
            Section::make('Detalhes do recebimento')->collapsed()->schema([
                TextInput::make('brand')->label('Marca')->maxLength(255)->disabledOn('edit'),
                TextInput::make('model')->label('Modelo')->maxLength(255)->disabledOn('edit'),
                TextInput::make('imei')->label('IMEI')->maxLength(255)->disabledOn('edit'),
                Textarea::make('condition')->label('Estado de entrada / checklist')->maxLength(5000)->disabledOn('edit'),
                Textarea::make('accessories')->label('Acessórios recebidos')->maxLength(5000)->disabledOn('edit'),
            ])->columns(2),
            Section::make('Observações da loja')->schema([
                Textarea::make('notes')->label('Observações internas')->maxLength(5000),
                Textarea::make('warranty')->label('Informações de garantia para o comprovante')->maxLength(5000),
            ])->collapsed(),
            Section::make('Orçamento, mensagens e histórico')->visibleOn('edit')->schema([
                Placeholder::make('summary')->label('Resumo do conserto')->content(fn (?ServiceOrder $record) => view('service-orders.internal', ['order' => $record])),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->label('OS')->sortable()->searchable(),
            TextColumn::make('client.name')->label('Cliente')->searchable(),
            TextColumn::make('device')->label('Aparelho')->searchable(),
            TextColumn::make('status')->label('Etapa')->badge()->formatStateUsing(fn ($state) => ServiceOrder::STATUSES[$state] ?? $state),
            TextColumn::make('created_at')->label('Recebido em')->dateTime('d/m/Y H:i')->timezone(fn () => Filament::getTenant()->timezone)->sortable(),
        ])->filters([SelectFilter::make('status')->label('Etapa')->options(ServiceOrder::STATUSES)])
            ->recordActions([EditAction::make()->label('Abrir conserto')])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListServiceOrders::route('/'), 'create' => Pages\CreateServiceOrder::route('/create'), 'edit' => Pages\EditServiceOrder::route('/{record}/edit')];
    }
}
