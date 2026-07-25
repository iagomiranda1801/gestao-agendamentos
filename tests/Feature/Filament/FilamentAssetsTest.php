<?php

namespace Tests\Feature\Filament;

use Tests\TestCase;

class FilamentAssetsTest extends TestCase
{
    public function test_notifications_script_loads_before_filament_core(): void
    {
        $company = $this->createCompany(['slug' => 'assets-test']);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        $html = $this->get("/app/empresa/{$company->slug}/clientes")->getContent();

        $notificationsPos = strpos($html, 'filament/notifications');
        $filamentAppPos = strpos($html, 'filament/filament/app.js');

        $this->assertNotFalse($notificationsPos, 'Notifications script missing from page.');
        $this->assertNotFalse($filamentAppPos, 'Filament core script missing from page.');
        $this->assertLessThan($filamentAppPos, $notificationsPos, 'Notifications script must load before Filament core.');
    }

    public function test_notifications_livewire_component_is_present(): void
    {
        $company = $this->createCompany(['slug' => 'assets-test-2']);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        $html = $this->get("/app/empresa/{$company->slug}/clientes")->getContent();

        $this->assertStringContainsString('fi-no', $html);
        $this->assertStringContainsString('isFilamentNotificationsComponent', $html);
    }
}
