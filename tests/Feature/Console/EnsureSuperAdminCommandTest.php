<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;

class EnsureSuperAdminCommandTest extends TestCase
{
    public function test_it_restores_super_admin_flag_on_existing_user(): void
    {
        $user = User::factory()->create([
            'email' => 'ops@example.test',
            'is_super_admin' => false,
            'is_active' => false,
        ]);

        $this->artisan('users:ensure-super-admin', ['email' => 'ops@example.test'])
            ->assertSuccessful();

        $user->refresh();

        $this->assertTrue($user->is_super_admin);
        $this->assertTrue($user->is_active);
    }

    public function test_it_creates_super_admin_when_requested(): void
    {
        $this->artisan('users:ensure-super-admin', [
            'email' => 'novo-admin@example.test',
            '--create' => true,
            '--name' => 'Novo Admin',
            '--password' => 'secret123',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'novo-admin@example.test')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->is_super_admin);
        $this->assertTrue($user->is_active);
        $this->assertSame('Novo Admin', $user->name);
    }

    public function test_it_fails_when_user_is_missing_without_create(): void
    {
        $this->artisan('users:ensure-super-admin', ['email' => 'missing@example.test'])
            ->assertFailed();
    }
}
