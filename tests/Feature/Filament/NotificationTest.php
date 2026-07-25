<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\Clients\Pages\CreateClient;
use App\Filament\App\Resources\Clients\Pages\EditClient;
use App\Models\Client;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    public function test_create_client_shows_success_notification(): void
    {
        $company = $this->createCompany(['slug' => 'notif-test']);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Maria Teste',
                'phone' => '(11) 98765-4321',
                'is_active' => true,
            ])
            ->call('create')
            ->assertNotified();
    }

    public function test_edit_client_shows_success_notification(): void
    {
        $company = $this->createCompany(['slug' => 'notif-edit']);
        $admin = $this->createCompanyUser($company);
        $client = Client::factory()->forCompany($company)->create([
            'name' => 'João',
            'phone' => '(11) 98765-4321',
        ]);
        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(EditClient::class, ['record' => $client->getKey()])
            ->fillForm([
                'name' => 'João Atualizado',
                'phone' => '(11) 98765-4321',
                'is_active' => true,
            ])
            ->call('save')
            ->assertNotified('Salvo');
    }
}
