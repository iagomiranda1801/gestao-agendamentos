<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use App\Services\Company\CompanyPermissionService;
use App\Services\ServiceOrders\DeviceCompatibilityService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Throwable;
use UnitEnum;

class DeviceCompatibilityPage extends Page
{
    protected static ?string $slug = 'compatibilidade';

    protected static ?string $navigationLabel = 'Compatibilidade';

    protected static ?string $title = 'Compatibilidade';

    protected static string|UnitEnum|null $navigationGroup = 'Assistência técnica';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected string $view = 'filament.app.pages.device-compatibility';

    public string $query = '';

    #[Locked]
    public string $category = 'film';

    #[Locked]
    public array $results = [];

    #[Locked]
    public bool $hasSearched = false;

    #[Locked]
    public string $searchedQuery = '';

    #[Locked]
    public ?string $searchError = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User && $user->is_active
            && $company instanceof Company && $company->is_active && $company->isTechnicalAssistance()
            && app(CompanyModuleService::class)->hasModule($company, CompanyModule::ServiceOrders)
            && app(CompanyPermissionService::class)->allows($user, $company, CompanyPermission::ManageServiceOrders);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function selectCategory(string $category): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(array_key_exists($category, DeviceCompatibilityService::CATEGORIES), 422);
        $this->category = $category;
        $this->results = [];
        $this->hasSearched = false;
        $this->searchedQuery = '';
        $this->searchError = null;
        $this->resetValidation();
    }

    public function search(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->results = [];
        $this->hasSearched = false;
        $this->searchError = null;
        $this->query = Str::squish($this->query);
        $this->validate(['query' => 'required|string|min:2|max:100'], [
            'query.required' => 'Informe o aparelho que deseja consultar.',
            'query.min' => 'Digite pelo menos 2 caracteres para pesquisar.',
            'query.max' => 'Use até 100 caracteres para pesquisar.',
        ]);

        $key = 'device-compatibility-search:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 30)) {
            $this->searchError = 'Muitas consultas em sequência. Aguarde um minuto e tente novamente.';

            return;
        }
        RateLimiter::hit($key, 60);

        try {
            $this->results = app(DeviceCompatibilityService::class)->search($this->category, $this->query);
            $this->searchedQuery = $this->query;
            $this->hasSearched = true;
        } catch (Throwable $exception) {
            report($exception);
            $this->searchError = 'Não foi possível consultar agora. Tente novamente em instantes.';
        }
    }
}
