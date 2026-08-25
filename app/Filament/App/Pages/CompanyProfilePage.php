<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CompanyProfilePage extends Page
{
    protected static ?string $slug = 'minha-empresa';

    protected static ?string $navigationLabel = 'Minha empresa';

    protected static ?string $title = 'Minha empresa';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User
            && $company instanceof Company
            && $user->hasActiveRoleInCompany($company, CompanyRole::CompanyAdmin, CompanyRole::Manager);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();

        $this->form->fill([
            'name' => $company->name,
            'document' => $company->document,
            'phone' => $company->phone,
            'email' => $company->email,
            'logo_path' => filled($company->logo_path) ? [$company->logo_path] : [],
            'timezone' => $company->timezone,
            'public_booking_url' => route('public.booking.show', ['company' => $company->slug]),
        ]);
    }

    public function save(): void
    {
        abort_unless($this->canEditCompany(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();

        $data = $this->form->getState();

        $company->update([
            'name' => $data['name'],
            'document' => $data['document'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'logo_path' => $this->normalizeLogoPath($data['logo_path'] ?? null),
            'logo_disk' => $this->logoDiskFor($company, $data['logo_path'] ?? null),
            'timezone' => $data['timezone'],
        ]);

        Notification::make()
            ->success()
            ->title('Dados da empresa salvos')
            ->send();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identidade')
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label('Logo da empresa')
                            ->helperText('Usada no painel da empresa e na página pública de agendamento. Se ficar vazia, o sistema mostra a logo padrão.')
                            ->disk((string) config('filesystems.company_logo_disk', 's3'))
                            ->directory(function (): string {
                                /** @var Company $company */
                                $company = Filament::getTenant();

                                return 'agendaqui/'.$company->slug.'/empresa/logo';
                            })
                            ->visibility('public')
                            ->image()
                            ->imageEditor()
                            ->imagePreviewHeight('96')
                            ->maxSize(2048)
                            ->disabled(fn (): bool => ! $this->canEditCompany())
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->label('Nome da empresa')
                            ->required()
                            ->maxLength(255)
                            ->disabled(fn (): bool => ! $this->canEditCompany()),
                        TextInput::make('document')
                            ->label('Documento')
                            ->maxLength(255)
                            ->disabled(fn (): bool => ! $this->canEditCompany()),
                        TextInput::make('phone')
                            ->label('Telefone')
                            ->tel()
                            ->maxLength(255)
                            ->disabled(fn (): bool => ! $this->canEditCompany()),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->maxLength(255)
                            ->disabled(fn (): bool => ! $this->canEditCompany()),
                        TextInput::make('timezone')
                            ->label('Fuso horário')
                            ->required()
                            ->maxLength(255)
                            ->disabled(fn (): bool => ! $this->canEditCompany()),
                    ])
                    ->columns(2),
                Section::make('Agendamento público')
                    ->schema([
                        Placeholder::make('public_booking_url')
                            ->label('Link público')
                            ->content(function (): string {
                                /** @var Company $company */
                                $company = Filament::getTenant();

                                return route('public.booking.show', ['company' => $company->slug]);
                            }),
                    ]),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Salvar dados da empresa')
                ->submit('save')
                ->visible(fn (): bool => $this->canEditCompany()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('company-profile-form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make($this->getFormActions()),
                    ]),
            ]);
    }

    protected function canEditCompany(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User
            && $company instanceof Company
            && $user->hasActiveRoleInCompany($company, CompanyRole::CompanyAdmin);
    }

    protected function normalizeLogoPath(mixed $logoPath): ?string
    {
        if (is_array($logoPath)) {
            $logoPath = array_values($logoPath)[0] ?? null;
        }

        return filled($logoPath) ? (string) $logoPath : null;
    }

    protected function logoDiskFor(Company $company, mixed $logoPath): string
    {
        return $this->normalizeLogoPath($logoPath) === $company->logo_path
            ? ($company->logo_disk ?: 'public')
            : (string) config('filesystems.company_logo_disk', 's3');
    }
}
