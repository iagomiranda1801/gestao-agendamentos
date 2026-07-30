<?php

namespace Tests\Feature\Dashboard;

use App\Filament\App\Pages\Dashboard;
use Tests\TestCase;

class OperationalDashboardTest extends TestCase
{
    public function test_company_user_can_see_operational_dashboard(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);

        $this->authenticateForAppTenant($user, $company);

        $this->get(Dashboard::getUrl(['tenant' => $company]))
            ->assertOk()
            ->assertSee('Agenda hoje')
            ->assertSee('Ações pendentes')
            ->assertSee('Últimas vendas');
    }
}
