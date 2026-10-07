<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyProfile;
use App\Filament\App\Resources\TattooRequests\Pages\EditTattooRequest;
use App\Filament\App\Resources\TattooRequests\Pages\ListTattooRequests;
use App\Filament\App\Resources\TattooRequests\TattooRequestResource;
use App\Jobs\SendTattooQuoteWhatsAppJob;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooAiConversation;
use App\Models\TattooRequest;
use App\Models\TattooRequestImage;
use App\Services\Tattoo\TattooQuoteService;
use App\Services\Tattoo\TattooRequestDeletionService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class TattooRequestDeletionTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_admin_deletes_draft_request_and_files_but_keeps_client(): void
    {
        Storage::fake('local');
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $user = $this->createCompanyUser($company);
        $client = Client::factory()->forCompany($company)->create();
        $request = $this->requestFor($company->id, $client->id);
        $quote = app(TattooQuoteService::class)->create($request, $user, [
            'price_type' => 'fixed', 'amount_min' => 350, 'sessions' => 1,
        ]);
        $path = "agendaqui/{$company->id}/tatuagem/{$request->id}/foto.png";
        Storage::disk('local')->put($path, 'photo');
        $image = new TattooRequestImage(['tattoo_request_id' => $request->id, 'disk' => 'local',
            'path' => $path, 'mime_type' => 'image/png', 'size_bytes' => 5]);
        $image->company_id = $company->id;
        $image->save();
        $this->authenticateForAppTenant($user, $company);

        Livewire::test(EditTattooRequest::class, ['record' => $request->id])
            ->callAction('delete_request')
            ->assertHasNoActionErrors()
            ->assertRedirect(TattooRequestResource::getUrl('index'));

        $this->assertDatabaseMissing('tattoo_requests', ['id' => $request->id]);
        $this->assertDatabaseMissing('tattoo_quotes', ['id' => $quote->id]);
        $this->assertDatabaseMissing('tattoo_request_images', ['id' => $image->id]);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
        Storage::disk('local')->assertMissing($path);
        app(SendTattooQuoteWhatsAppJob::class, ['quoteId' => $quote->id])->handle(app(TattooQuoteService::class));
    }

    public function test_sent_quote_cannot_be_deleted(): void
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $user = $this->createCompanyUser($company);
        $client = Client::factory()->forCompany($company)->create();
        $request = $this->requestFor($company->id, $client->id);
        $quote = app(TattooQuoteService::class)->create($request, $user, [
            'price_type' => 'fixed', 'amount_min' => 350, 'sessions' => 1,
        ]);
        $quote->update(['sent_at' => now()]);
        $this->authenticateForAppTenant($user, $company);

        Livewire::test(EditTattooRequest::class, ['record' => $request->id])
            ->assertActionDisabled('delete_request');
        $this->expectException(ValidationException::class);
        app(TattooRequestDeletionService::class)->delete($company, $request);
    }

    public function test_admin_can_delete_request_from_list(): void
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $user = $this->createCompanyUser($company);
        $client = Client::factory()->forCompany($company)->create();
        $request = $this->requestFor($company->id, $client->id);
        $this->authenticateForAppTenant($user, $company);

        Livewire::test(ListTattooRequests::class)
            ->assertTableActionVisible('delete_request', $request)
            ->callTableAction('delete_request', $request)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('tattoo_requests', ['id' => $request->id]);
    }

    public function test_deleting_ai_request_keeps_conversation_and_pauses_ai(): void
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $client = Client::factory()->forCompany($company)->create();
        $request = $this->requestFor($company->id, $client->id);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'delete-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        $conversation = TattooAiConversation::query()->create([
            'company_id' => $company->id, 'company_whatsapp_instance_id' => $instance->id,
            'phone_normalized' => '5511999999999', 'remote_jid' => '5511999999999@s.whatsapp.net',
            'client_id' => $client->id, 'tattoo_request_id' => $request->id,
            'status' => 'waiting_professional_quote',
        ]);

        app(TattooRequestDeletionService::class)->delete($company, $request);

        $this->assertDatabaseMissing('tattoo_requests', ['id' => $request->id]);
        $conversation->refresh();
        $this->assertSame($client->id, $conversation->client_id);
        $this->assertNull($conversation->tattoo_request_id);
        $this->assertTrue($conversation->human_takeover);
    }

    private function requestFor(int $companyId, int $clientId): TattooRequest
    {
        $request = new TattooRequest(['client_id' => $clientId, 'description' => 'Rosa pequena',
            'body_placement' => 'Braço', 'status' => 'awaiting_review']);
        $request->company_id = $companyId;
        $request->save();

        return $request;
    }
}
