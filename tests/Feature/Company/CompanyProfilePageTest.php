<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Filament\App\Pages\CompanyProfilePage;
use App\Services\Scheduling\CompanySchedulingSettingService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompanyProfilePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // These tests exercise the shared app panel, without segment redirects.
        config(['segments.salon.enabled' => false, 'segments.tattoo.enabled' => false]);
        Storage::fake('public');
        Storage::fake('s3');
    }

    public function test_company_admin_can_render_company_profile_page(): void
    {
        $company = $this->createCompany();
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(route('filament.app.pages.minha-empresa', ['tenant' => $company]))
            ->assertOk()
            ->assertSee('Minha empresa')
            ->assertSee('Logo da empresa');
    }

    public function test_employee_cannot_access_company_profile_page(): void
    {
        $company = $this->createCompany();
        $employee = $this->createCompanyUser($company, [], CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $company);

        $this->get(route('filament.app.pages.minha-empresa', ['tenant' => $company]))
            ->assertForbidden();
    }

    public function test_company_admin_can_update_company_profile(): void
    {
        $company = $this->createCompany();
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(CompanyProfilePage::class)
            ->fillForm([
                'name' => 'Clínica Sol',
                'document' => '12.345.678/0001-90',
                'phone' => '(11) 99999-0000',
                'email' => 'contato@clinicasol.test',
                'logo_path' => [UploadedFile::fake()->image('logo.png')],
                'timezone' => 'America/Sao_Paulo',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $company->refresh();

        $this->assertSame('Clínica Sol', $company->name);
        $this->assertStringStartsWith('agendaqui/'.$company->slug.'/empresa/logo/', $company->logo_path);
        $this->assertSame('s3', $company->logo_disk);
        Storage::disk('s3')->assertExists($company->logo_path);
    }

    public function test_manager_cannot_update_company_profile(): void
    {
        $company = $this->createCompany(['name' => 'Original']);
        $manager = $this->createCompanyUser($company, [], CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $company);

        Livewire::test(CompanyProfilePage::class)
            ->fillForm([
                'name' => 'Alterada',
                'document' => null,
                'phone' => null,
                'email' => null,
                'logo_path' => [],
                'timezone' => 'America/Sao_Paulo',
            ])
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Original', $company->refresh()->name);
    }

    public function test_company_logo_path_is_resolved_to_same_origin_url(): void
    {
        $company = $this->createCompany([
            'logo_path' => 'company-logos/1/logo.png',
        ]);

        $this->assertStringStartsWith(route('company.logo', ['company' => $company->slug]).'?v=', $company->logoUrl());
    }

    public function test_public_booking_uses_company_logo_when_available(): void
    {
        $company = $this->createCompany([
            'name' => 'Estúdio com Logo',
            'logo_path' => 'company-logos/1/logo.png',
        ]);
        app(CompanySchedulingSettingService::class)->update($company, [
            'public_booking_enabled' => true,
        ]);

        $this->get(route('public.booking.show', ['company' => $company->slug]))
            ->assertOk()
            ->assertSee('/empresas/'.$company->slug.'/logo', false)
            ->assertDontSee('booking-brand__logo--platform', false);
    }

    public function test_existing_public_logo_is_kept_when_new_uploads_use_s3(): void
    {
        config(['filesystems.company_logo_disk' => 's3']);
        $company = $this->createCompany(['logo_path' => 'legacy/logo.png', 'logo_disk' => 'public']);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        $component = Livewire::test(CompanyProfilePage::class);
        $field = $component->instance()->form->getFlatFields()['logo_path'];
        $this->assertFalse($field->shouldFetchFileInformation());
        $this->assertFalse($field->isPreviewable());
        $this->assertSame([], $field->getUploadedFiles());

        $component->fillForm(['name' => 'Nome atualizado'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('legacy/logo.png', $company->refresh()->logo_path);
        $this->assertSame('public', $company->logo_disk);
    }

    public function test_missing_old_logo_can_be_replaced_with_a_new_upload(): void
    {
        config(['filesystems.company_logo_disk' => 's3']);
        $company = $this->createCompany(['logo_path' => 'legacy/missing.png', 'logo_disk' => 'public']);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(CompanyProfilePage::class)
            ->fillForm(['logo_path' => [UploadedFile::fake()->image('nova-logo.png')]])
            ->call('save')->assertHasNoFormErrors();

        $company->refresh();
        $this->assertSame('s3', $company->logo_disk);
        $this->assertNotSame('legacy/missing.png', $company->logo_path);
        Storage::disk('s3')->assertExists($company->logo_path);
        $this->get($company->logoUrl())->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_current_logo_can_be_removed_even_when_its_file_is_missing(): void
    {
        $company = $this->createCompany(['logo_path' => 'missing.png', 'logo_disk' => 'public']);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(CompanyProfilePage::class)
            ->call('removeLogo')->call('save')->assertHasNoFormErrors();

        $this->assertNull($company->refresh()->logo_path);
        $this->assertNull($company->logoUrl());
    }

    public function test_saved_upload_is_not_loaded_back_into_the_upload_field(): void
    {
        $company = $this->createCompany(['logo_path' => 'missing.png', 'logo_disk' => 'public']);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        $component = Livewire::test(CompanyProfilePage::class)
            ->fillForm(['logo_path' => [UploadedFile::fake()->image('nova.png')]])
            ->call('save')->assertHasNoFormErrors();

        $savedPath = $company->refresh()->logo_path;
        $this->assertSame([], $component->instance()->form->getFlatFields()['logo_path']->getUploadedFiles());

        $component->call('save')->assertHasNoFormErrors();
        $this->assertSame($savedPath, $company->refresh()->logo_path);
        $this->assertSame('s3', $company->logo_disk);

        $component->fillForm(['logo_path' => [UploadedFile::fake()->image('outra.png')]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertNotSame($savedPath, $company->refresh()->logo_path);
        Storage::disk('s3')->assertExists($company->logo_path);
    }

    public function test_manager_cannot_remove_the_current_logo(): void
    {
        $company = $this->createCompany(['logo_path' => 'old/logo.png']);
        $manager = $this->createCompanyUser($company, [], CompanyRole::Manager);
        $this->authenticateForAppTenant($manager, $company);

        Livewire::test(CompanyProfilePage::class)->call('removeLogo')->assertForbidden();
        $this->assertSame('old/logo.png', $company->refresh()->logo_path);
    }

    public function test_logo_endpoint_streams_private_image_from_its_recorded_disk(): void
    {
        $image = UploadedFile::fake()->image('logo.png');
        $contents = file_get_contents($image->getRealPath());
        Storage::disk('s3')->put('private/logo.png', $contents, 'private');
        $company = $this->createCompany(['logo_path' => 'private/logo.png', 'logo_disk' => 's3']);

        $response = $this->get($company->logoUrl())
            ->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($contents, $response->streamedContent());
    }

    public function test_logo_endpoint_returns_not_found_for_missing_or_non_image_files(): void
    {
        $company = $this->createCompany(['logo_path' => 'missing.png', 'logo_disk' => 's3']);
        $this->get($company->logoUrl())->assertNotFound();
        Storage::disk('s3')->put('document.txt', 'not an image');
        $company->update(['logo_path' => 'document.txt']);
        $this->get($company->logoUrl())->assertNotFound();
    }

    public function test_company_cannot_set_another_file_as_its_logo_by_submitting_a_path(): void
    {
        $company = $this->createCompany(['logo_path' => 'own/logo.png', 'logo_disk' => 'public']);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(CompanyProfilePage::class)
            ->fillForm(['logo_path' => ['another-company/private.png']])
            ->call('save')->assertHasFormErrors(['logo_path']);

        $this->assertSame('own/logo.png', $company->refresh()->logo_path);
    }

    public function test_failed_storage_write_keeps_the_previous_logo_and_shows_an_error(): void
    {
        config(['filesystems.company_logo_disk' => 's3']);
        $company = $this->createCompany(['logo_path' => 'old/logo.png', 'logo_disk' => 'public']);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::set('s3', $disk);

        Livewire::test(CompanyProfilePage::class)
            ->fillForm(['logo_path' => [UploadedFile::fake()->image('nova.png')]])
            ->call('save')->assertHasFormErrors(['logo_path'])
            ->assertSee('Não foi possível salvar a logo no armazenamento.');

        $this->assertSame('old/logo.png', $company->refresh()->logo_path);
        $this->assertSame('public', $company->logo_disk);
    }

    public function test_restaurant_company_profile_page_shows_public_orders_link_and_pedidos_copy(): void
    {
        $company = $this->createCompany([
            'name' => 'Petiscaria do Tio Wesley',
            'slug' => 'petiscaria-do-tio-wesley',
            'business_profile' => CompanyProfile::Restaurant,
            'enabled_modules' => [CompanyModule::Orders->value, CompanyModule::WhatsApp->value],
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(route('filament.app.pages.minha-empresa', ['tenant' => $company]))
            ->assertOk()
            ->assertSee('Pedidos online')
            ->assertSee('página pública de cardápio e pedidos online')
            ->assertSee('/pedir/petiscaria-do-tio-wesley')
            ->assertSee(route('public.orders.show', ['company' => $company->slug]))
            ->assertSee('Abrir configurações de pedidos')
            ->assertDontSee('Agendamento público')
            ->assertDontSee('/agendar/petiscaria-do-tio-wesley')
            ->assertDontSee('página pública de agendamento');
    }

    public function test_restaurant_with_scheduling_module_still_shows_public_orders_link(): void
    {
        $company = $this->createCompany([
            'slug' => 'cantina-mista',
            'business_profile' => CompanyProfile::Restaurant,
            'enabled_modules' => [
                CompanyModule::Orders->value,
                CompanyModule::Scheduling->value,
                CompanyModule::WhatsApp->value,
            ],
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(route('filament.app.pages.minha-empresa', ['tenant' => $company]))
            ->assertOk()
            ->assertSee('Pedidos online')
            ->assertSee('/pedir/cantina-mista')
            ->assertDontSee('/agendar/cantina-mista');
    }

    #[DataProvider('schedulingCompanyProfiles')]
    public function test_scheduling_company_profile_page_shows_public_booking_link(
        CompanyProfile $profile,
        string $slug,
    ): void {
        $company = $this->createCompany([
            'slug' => $slug,
            'business_profile' => $profile,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::WhatsApp->value],
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(route('filament.app.pages.minha-empresa', ['tenant' => $company]))
            ->assertOk()
            ->assertSee('Agendamento público')
            ->assertSee('página pública de agendamento')
            ->assertSee('/agendar/'.$slug)
            ->assertSee(route('public.booking.show', ['company' => $company->slug]))
            ->assertDontSee('Pedidos online')
            ->assertDontSee('/pedir/'.$slug)
            ->assertDontSee('página pública de cardápio e pedidos online');
    }

    /**
     * @return array<string, array{0: CompanyProfile, 1: string}>
     */
    public static function schedulingCompanyProfiles(): array
    {
        return [
            'salon' => [CompanyProfile::Salon, 'salao-beleza'],
            'clinic' => [CompanyProfile::Clinic, 'clinica-vida'],
        ];
    }
}
