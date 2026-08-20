<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Enums\CompanyPermission;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Models\Company;
use App\Services\Client\DentalPatientMigrationService;
use App\Services\Clinical\DentalClinicSettingService;
use App\Services\Company\CompanyPermissionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class DentalClinicSettingsPage extends Page
{
    use RequiresCompanyModule;

    protected static ?string $slug = 'configuracoes-odontologicas';

    protected static ?string $navigationLabel = 'Configurações odontológicas';

    protected static ?string $title = 'Configurações odontológicas';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    public ?array $data = [];

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::ClinicalRecords;
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return static::tenantHasRequiredModule() && $company instanceof Company && $company->isDentalClinic()
            && app(CompanyPermissionService::class)->allows(auth()->user(), $company, CompanyPermission::ManagePermissions);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $setting = app(DentalClinicSettingService::class)->getOrCreate(Filament::getTenant());
        $this->form->fill($setting->only(['professional_record_scope', 'minor_guardian_required', 'clinical_entry_required_to_complete']));
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Regras clínicas')->schema([
            Select::make('professional_record_scope')->label('Acesso dos dentistas aos prontuários')->options(['all' => 'Todos os pacientes da clínica', 'related' => 'Somente pacientes relacionados ao dentista'])->required(),
            Toggle::make('minor_guardian_required')->label('Exigir responsável para paciente menor de idade'),
            Toggle::make('clinical_entry_required_to_complete')->label('Exigir evolução finalizada antes de concluir atendimento'),
        ])]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        app(DentalClinicSettingService::class)->update(Filament::getTenant(), $this->form->getState());
        Notification::make()->success()->title('Configurações odontológicas salvas')->send();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepareExistingPatients')
                ->label('Preparar clientes como pacientes')
                ->icon('heroicon-o-user-group')
                ->requiresConfirmation()
                ->modalHeading('Preparar cadastros existentes')
                ->modalDescription('Cria somente os perfis odontológicos e números de prontuário que estiverem ausentes. Nenhum cliente, agenda, atendimento ou dado financeiro será alterado ou removido.')
                ->action(function (): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();
                    $result = app(DentalPatientMigrationService::class)->prepareExistingClients($company);

                    Notification::make()
                        ->success()
                        ->title('Preparação concluída')
                        ->body("{$result['converted']} paciente(s) preparado(s); {$result['already_prepared']} já possuíam prontuário.")
                        ->send();
                }),
        ];
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Salvar configurações')->submit('save')];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([Form::make([EmbeddedSchema::make('form')])->id('dental-settings-form')->livewireSubmitHandler('save')->footer([Actions::make($this->getFormActions())])]);
    }
}
