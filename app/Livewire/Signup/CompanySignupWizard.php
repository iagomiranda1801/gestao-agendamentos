<?php

namespace App\Livewire\Signup;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Filament\App\Pages\Dashboard;
use App\Services\Company\CompanyProvisioningService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.signup')]
class CompanySignupWizard extends Component
{
    public const STEP_COMPANY = 'company';

    public const STEP_MODULES = 'modules';

    public const STEP_ADMIN = 'admin';

    public const STEP_REVIEW = 'review';

    public string $step = self::STEP_COMPANY;

    public string $companyName = '';

    public string $companySlug = '';

    public ?string $companyEmail = null;

    public ?string $companyPhone = null;

    public string $timezone = 'America/Sao_Paulo';

    public string $businessProfile = CompanyProfile::Professional->value;

    /** @var list<string> */
    public array $selectedModules = [];

    public string $adminName = '';

    public string $adminEmail = '';

    public string $adminPassword = '';

    public string $adminPasswordConfirmation = '';

    public function mount(): void
    {
        $this->selectedModules = $this->profileModules($this->businessProfile);
    }

    public function updatedCompanyName(string $value): void
    {
        if (blank($this->companySlug) || $this->companySlug === Str::slug($this->companyName)) {
            $this->companySlug = Str::slug($value);
        }
    }

    public function goToCompanyStep(): void
    {
        $this->step = self::STEP_COMPANY;
    }

    public function goToModulesStep(): void
    {
        $this->validate([
            'companyName' => ['required', 'string', 'max:255'],
            'companySlug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:companies,slug'],
            'companyEmail' => ['nullable', 'email', 'max:255'],
            'companyPhone' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:255'],
        ]);

        $this->step = self::STEP_MODULES;
    }

    public function goToAdminStep(): void
    {
        $this->validate([
            'selectedModules' => ['required', 'array', 'min:1'],
            'selectedModules.*' => ['in:'.implode(',', array_column(CompanyModule::cases(), 'value'))],
        ]);

        $this->step = self::STEP_ADMIN;
    }

    public function updatedBusinessProfile(string $value): void
    {
        $this->selectedModules = $this->profileModules($value);
    }

    public function goToReviewStep(): void
    {
        $this->validate([
            'adminName' => ['required', 'string', 'max:255'],
            'adminEmail' => ['required', 'email', 'max:255', 'unique:users,email'],
            'adminPassword' => ['required', 'same:adminPasswordConfirmation', Password::defaults()],
        ]);

        $this->step = self::STEP_REVIEW;
    }

    public function submit(CompanyProvisioningService $provisioningService): void
    {
        if ($this->step !== self::STEP_REVIEW) {
            return;
        }

        $this->goToReviewStep();

        $result = $provisioningService->provision([
            'name' => $this->companyName,
            'slug' => $this->companySlug,
            'email' => $this->companyEmail,
            'phone' => $this->companyPhone,
            'timezone' => $this->timezone,
            'business_profile' => $this->businessProfile,
            'enabled_modules' => $this->selectedModules,
            'admin_name' => $this->adminName,
            'admin_email' => $this->adminEmail,
            'admin_password' => $this->adminPassword,
        ]);

        Auth::login($result['user']);

        Filament::setCurrentPanel('app');
        Filament::setTenant($result['company'], isQuiet: true);

        $this->redirect(Dashboard::getUrl(['tenant' => $result['company']]));
    }

    /**
     * @return array<string, string>
     */
    public function moduleOptions(): array
    {
        return CompanyModule::options();
    }

    /**
     * @return array<string, string>
     */
    public function moduleDescriptions(): array
    {
        return collect(CompanyModule::cases())
            ->mapWithKeys(fn (CompanyModule $module) => [$module->value => $module->description()])
            ->all();
    }

    /** @return list<string> */
    public function profileModules(string $profile): array
    {
        $selected = CompanyProfile::tryFrom($profile) ?? CompanyProfile::Custom;

        return collect($selected->defaultModules())->map(fn (CompanyModule $module) => $module->value)->all();
    }

    /** @return array<string, string> */
    public function profileOptions(): array
    {
        return CompanyProfile::options();
    }

    public function profileDescription(): string
    {
        return (CompanyProfile::tryFrom($this->businessProfile) ?? CompanyProfile::Custom)->description();
    }

    public function render()
    {
        return view('livewire.signup.company-signup-wizard');
    }
}
