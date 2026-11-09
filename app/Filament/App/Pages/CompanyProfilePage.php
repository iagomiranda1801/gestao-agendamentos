<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use App\Support\Segment;
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
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;
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

    public bool $removeCurrentLogo = false;

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
            'logo_path' => [],
            'timezone' => $company->timezone,
            'public_page_url' => $this->publicPageUrl($company),
        ]);
    }

    public function save(): void
    {
        abort_unless($this->canEditCompany(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();

        $data = $this->form->getState();
        $newLogoPath = $this->normalizeLogoPath($data['logo_path'] ?? null);

        $company->update([
            'name' => $data['name'],
            'document' => $data['document'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'logo_path' => $newLogoPath ?? ($this->removeCurrentLogo ? null : $company->logo_path),
            'logo_disk' => $newLogoPath !== null
                ? (string) config('filesystems.company_logo_disk', 's3')
                : ($company->logo_disk ?: 'public'),
            'timezone' => $data['timezone'],
        ]);

        $this->removeCurrentLogo = false;
        $this->form->fill([...$data, 'logo_path' => []]);

        Notification::make()
            ->success()
            ->title('Dados da empresa salvos')
            ->send();
    }

    public function removeLogo(): void
    {
        abort_unless($this->canEditCompany(), 403);

        $this->removeCurrentLogo = true;
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->model(Filament::getTenant())
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identidade')
                    ->schema([
                        Placeholder::make('current_logo')
                            ->label('Logo atual')
                            ->content(fn () => view('filament.app.components.company-logo-preview', ['company' => Filament::getTenant()]))
                            ->visible(fn (): bool => ! $this->removeCurrentLogo && filled(Filament::getTenant()?->logo_path))
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('removeLogo')
                                ->label('Remover logo atual')
                                ->color('danger')
                                ->action(fn () => $this->removeLogo()),
                        ])
                            ->visible(fn (): bool => $this->canEditCompany() && ! $this->removeCurrentLogo && filled(Filament::getTenant()?->logo_path))
                            ->columnSpanFull(),
                        FileUpload::make('logo_path')
                            ->label('Logo da empresa')
                            ->helperText(fn (): string => $this->logoHelperText())
                            ->disk((string) config('filesystems.company_logo_disk', 's3'))
                            ->directory(function (): string {
                                /** @var Company $company */
                                $company = Filament::getTenant();

                                return 'agendaqui/'.$company->slug.'/empresa/logo';
                            })
                            ->visibility('public')
                            ->image()
                            // Only new uploads enter FilePond. Existing logos are shown separately,
                            // so their availability never blocks replacing or removing them.
                            ->fetchFileInformation(false)
                            ->preventFilePathTampering()
                            ->previewable(false)
                            ->saveUploadedFileUsing(function (FileUpload $component, TemporaryUploadedFile $file): string {
                                $stream = null;
                                try {
                                    $path = trim($component->getDirectory().'/'.$component->getUploadedFileNameForStorage($file), '/');
                                    $stream = $file->readStream();
                                    if (is_resource($stream) && $component->getDisk()->put($path, $stream)) {
                                        return $path;
                                    }
                                } catch (Throwable $exception) {
                                    report($exception);
                                } finally {
                                    if (is_resource($stream)) {
                                        fclose($stream);
                                    }
                                }

                                throw ValidationException::withMessages([
                                    'data.logo_path' => 'Não foi possível salvar a logo no armazenamento. Tente novamente ou entre em contato com o suporte.',
                                ]);
                            })
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
                Section::make(fn (): string => $this->usesPublicOrdersChannel()
                    ? 'Pedidos online'
                    : 'Agendamento público')
                    ->visible(fn (): bool => ! Filament::getTenant()?->isTechnicalAssistance()
                        || app(CompanyModuleService::class)->hasModule(Filament::getTenant(), CompanyModule::Scheduling)
                        || $this->usesPublicOrdersChannel())
                    ->description(fn (): ?string => $this->usesPublicOrdersChannel()
                        ? 'Este é o link do cardápio para os clientes. Ative ou desative pedidos online nas configurações de pedidos.'
                        : null)
                    ->schema([
                        Placeholder::make('public_page_url')
                            ->label('Link público')
                            ->content(fn (): string => $this->publicPageUrl()),
                        Actions::make([
                            Action::make('openOrderSettings')
                                ->label('Abrir configurações de pedidos')
                                ->url(fn (): string => OrderSettingsPage::getUrl()),
                        ])
                            ->visible(fn (): bool => $this->usesPublicOrdersChannel()
                                && OrderSettingsPage::canAccess()),
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

    protected function usesPublicOrdersChannel(?Company $company = null): bool
    {
        $company ??= Filament::getTenant();

        return $company instanceof Company
            && app(CompanyModuleService::class)->usesPublicOrdersChannel($company);
    }

    protected function publicPageUrl(?Company $company = null): string
    {
        /** @var Company $company */
        $company ??= Filament::getTenant();

        if ($this->usesPublicOrdersChannel($company)) {
            return Segment::route($company, 'public.orders.show', ['company' => $company->slug]);
        }

        return Segment::route($company, 'public.booking.show', ['company' => $company->slug]);
    }

    protected function logoHelperText(): string
    {
        if ($this->usesPublicOrdersChannel()) {
            return 'Usada no painel da empresa e na página pública de cardápio e pedidos online. Envie uma imagem de até 2 MB para trocar a logo. Para remover a atual, clique em Remover logo atual e salve.';
        }

        return 'Usada no painel da empresa e na página pública de agendamento. Envie uma imagem de até 2 MB para trocar a logo. Para remover a atual, clique em Remover logo atual e salve.';
    }

    protected function normalizeLogoPath(mixed $logoPath): ?string
    {
        if (is_array($logoPath)) {
            $logoPath = array_values($logoPath)[0] ?? null;
        }

        return filled($logoPath) ? (string) $logoPath : null;
    }
}
