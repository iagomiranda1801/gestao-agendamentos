<?php

namespace Tests\Feature\MultiTenancy;

use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    public function test_super_admin_can_access_operations_pages(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->get('/admin/operacao/jobs-falhos')
            ->assertOk()
            ->assertSee('Jobs falhados');

        $this->actingAs($admin)
            ->get('/admin/operacao/rotas')
            ->assertOk()
            ->assertSee('Rotas do sistema')
            ->assertSee('filament.app.pages.dashboard');

        $this->actingAs($admin)
            ->get('/admin/operacao/webhooks')
            ->assertOk()
            ->assertSee('Webhooks recentes');
    }
}
