<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\Clients\Pages\CreateClient;
use App\Filament\App\Resources\Clients\Pages\EditClient;
use App\Models\Client;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationDisplayTest extends TestCase
{
    public function test_success_notification_is_rendered_after_create_redirect(): void
    {
        $company = $this->createCompany(['slug' => 'notif-display']);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Cliente Notificacao',
                'phone' => '(11) 98765-4321',
                'is_active' => true,
            ])
            ->call('create');

        $client = Client::query()->where('company_id', $company->getKey())->firstOrFail();

        $editResponse = $this->get("/app/empresa/{$company->slug}/clientes/{$client->getKey()}/edit");

        $editResponse->assertSuccessful();
        $editResponse->assertSee('Criado', false);
    }

    public function test_success_notification_is_sent_on_same_page_save(): void
    {
        $company = $this->createCompany(['slug' => 'notif-same-page']);
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
