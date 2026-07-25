<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\Clients\Pages\EditClient;
use App\Models\Client;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationSessionTest extends TestCase
{
    public function test_save_leaves_notification_in_session_for_display(): void
    {
        $company = $this->createCompany(['slug' => 'session-notif']);
        $admin = $this->createCompanyUser($company);
        $client = Client::factory()->forCompany($company)->create([
            'name' => 'Joao',
            'phone' => '(11) 98765-4321',
        ]);
        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(EditClient::class, ['record' => $client->getKey()])
            ->fillForm([
                'name' => 'Joao Editado',
                'phone' => '(11) 98765-4321',
                'is_active' => true,
            ])
            ->call('save')
            ->assertNotified('Salvo');
    }
}
