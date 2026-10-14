<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\ExternalIntegration;
use App\Models\User;
use App\Services\Integrations\IntegrationKeyService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class ExternalIntegrationsPage extends Page
{
    protected static ?string $slug = 'integracoes-externas';

    protected static ?string $navigationLabel = 'Integrações externas';

    protected static ?string $title = 'Integrações externas';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected string $view = 'filament.app.pages.external-integrations';

    public string $name = '';

    public ?string $oneTimeKey = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User
            && $company instanceof Company
            && $user->hasActiveRoleInCompany($company, CompanyRole::CompanyAdmin);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function createIntegration(IntegrationKeyService $keys): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['name' => ['required', 'string', 'min:2', 'max:120']]);
        [, $this->oneTimeKey] = $keys->create($this->company(), trim($this->name));
        $this->name = '';
    }

    public function regenerate(int $id, IntegrationKeyService $keys): void
    {
        abort_unless(static::canAccess(), 403);
        $this->oneTimeKey = $keys->regenerate($this->ownedIntegration($id));
    }

    public function revoke(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $this->ownedIntegration($id)->update(['revoked_at' => now(), 'is_active' => false]);
        $this->oneTimeKey = null;
    }

    public function toggle(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $integration = $this->ownedIntegration($id);
        if ($integration->revoked_at !== null) {
            throw ValidationException::withMessages(['integration' => 'Gere uma nova chave para reativar esta integração.']);
        }

        $integration->update(['is_active' => ! $integration->is_active]);
        $this->oneTimeKey = null;
    }

    private function ownedIntegration(int $id): ExternalIntegration
    {
        return $this->company()->externalIntegrations()->findOrFail($id);
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return $company;
    }

    public function integrations(): Collection
    {
        return $this->company()->externalIntegrations()->latest()->get();
    }
}
