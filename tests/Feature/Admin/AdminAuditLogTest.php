<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Filament\Admin\Resources\AdminAuditLogs\AdminAuditLogResource;
use App\Filament\Admin\Resources\AdminAuditLogs\Pages\ListAdminAuditLogs;
use App\Models\AdminAuditLog;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    public function test_admin_panel_records_company_creation_and_changes(): void
    {
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        $company = Company::factory()->create([
            'name' => 'Empresa auditada',
            'is_active' => true,
        ]);

        $created = AdminAuditLog::query()->latest('id')->firstOrFail();

        $this->assertSame(AdminAuditAction::CompanyCreated, $created->action);
        $this->assertSame($admin->getKey(), $created->actor_id);
        $this->assertSame('Empresa: Empresa auditada', $created->subject_label);
        $this->assertSame('Empresa auditada', $created->after['name']);

        $company->update([
            'name' => 'Empresa atualizada',
            'is_active' => false,
        ]);

        $updated = AdminAuditLog::query()->latest('id')->firstOrFail();

        $this->assertSame(AdminAuditAction::CompanyUpdated, $updated->action);
        $this->assertSame('Empresa auditada', $updated->before['name']);
        $this->assertSame('Empresa atualizada', $updated->after['name']);
        $this->assertTrue($updated->before['is_active']);
        $this->assertFalse($updated->after['is_active']);
    }

    public function test_password_changes_are_logged_without_secret_values(): void
    {
        $admin = $this->createSuperAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin);
        $target->update(['password' => 'nova-senha-secreta']);

        $log = AdminAuditLog::query()->latest('id')->firstOrFail();

        $this->assertSame(AdminAuditAction::UserPasswordChanged, $log->action);
        $this->assertSame([], $log->before);
        $this->assertSame([], $log->after);
        $this->assertStringNotContainsString('nova-senha-secreta', json_encode($log->toArray()));
    }

    public function test_deleted_company_keeps_a_readable_audit_entry(): void
    {
        $admin = $this->createSuperAdmin();
        $company = $this->createCompany(['name' => 'Empresa removida']);

        $this->actingAs($admin);
        $company->delete();

        $log = AdminAuditLog::query()->latest('id')->firstOrFail();

        $this->assertSame(AdminAuditAction::CompanyDeleted, $log->action);
        $this->assertNull($log->company_id);
        $this->assertSame('Empresa: Empresa removida', $log->subject_label);
        $this->assertSame('Empresa removida', $log->before['name']);
    }

    public function test_events_outside_the_admin_panel_are_not_recorded(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);

        $this->authenticateForAppTenant($user, $company);
        $company->update(['name' => 'Alteração do painel da empresa']);

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_only_platform_admin_can_access_the_audit_resource(): void
    {
        $admin = $this->createSuperAdmin();
        $regularUser = User::factory()->create();

        Filament::setCurrentPanel('admin');
        $this->actingAs($admin);
        Livewire::test(ListAdminAuditLogs::class)->assertSuccessful();

        $this->actingAs($regularUser);
        Livewire::test(ListAdminAuditLogs::class)->assertForbidden();
    }

    public function test_audit_details_expose_only_changed_fields(): void
    {
        $admin = $this->createSuperAdmin();
        $company = $this->createCompany();

        $log = AdminAuditLog::query()->create([
            'actor_id' => $admin->getKey(),
            'actor_name' => $admin->name,
            'actor_email' => $admin->email,
            'company_id' => $company->getKey(),
            'action' => AdminAuditAction::CompanyUpdated,
            'subject_type' => Company::class,
            'subject_id' => $company->getKey(),
            'subject_label' => 'Empresa: '.$company->name,
            'before' => ['name' => 'Nome anterior'],
            'after' => ['name' => 'Nome atual'],
            'occurred_at' => now(),
        ]);

        $changes = AdminAuditLogResource::changes($log);

        $this->assertSame([[
            'field' => 'Nome',
            'before' => 'Nome anterior',
            'after' => 'Nome atual',
        ]], $changes);
    }
}
