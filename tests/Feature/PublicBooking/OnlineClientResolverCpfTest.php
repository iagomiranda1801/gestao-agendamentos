<?php

namespace Tests\Feature\PublicBooking;

use App\Models\Client;
use App\Services\PublicBooking\OnlineClientResolver;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class OnlineClientResolverCpfTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_resolves_existing_client_by_cpf(): void
    {
        $company = $this->createSchedulingCompany();

        $existing = Client::factory()->forCompany($company)->active()->create([
            'name' => 'Cliente Antigo',
            'phone' => '(11) 90000-0000',
            'phone_normalized' => '11900000000',
            'email' => null,
            'document' => '52998224725',
        ]);

        $resolved = app(OnlineClientResolver::class)->resolve(
            $company,
            'Nome Novo',
            '(11) 91111-1111',
            'novo@example.com',
            '529.982.247-25',
        );

        $this->assertTrue($resolved->is($existing));
        $this->assertSame('11911111111', $resolved->phone_normalized);
        $this->assertSame('novo@example.com', $resolved->email);
        $this->assertSame(1, Client::query()->where('company_id', $company->getKey())->count());
    }

    public function test_creates_client_with_document(): void
    {
        $company = $this->createSchedulingCompany();

        $resolved = app(OnlineClientResolver::class)->resolve(
            $company,
            'Maria Silva',
            '(11) 98765-4321',
            'maria@example.com',
            '52998224725',
        );

        $this->assertSame('52998224725', $resolved->document);
        $this->assertDatabaseHas('clients', [
            'id' => $resolved->getKey(),
            'document' => '52998224725',
            'name' => 'Maria Silva',
        ]);
    }

    public function test_attaches_document_when_matching_by_phone_and_name(): void
    {
        $company = $this->createSchedulingCompany();

        $existing = Client::factory()->forCompany($company)->active()->create([
            'name' => 'Maria Silva',
            'phone' => '(11) 98765-4321',
            'phone_normalized' => '11987654321',
            'document' => null,
        ]);

        $resolved = app(OnlineClientResolver::class)->resolve(
            $company,
            'Maria Silva',
            '(11) 98765-4321',
            null,
            '52998224725',
        );

        $this->assertTrue($resolved->is($existing));
        $this->assertSame('52998224725', $resolved->document);
    }

    public function test_rejects_invalid_document(): void
    {
        $company = $this->createSchedulingCompany();

        $this->expectException(ValidationException::class);

        app(OnlineClientResolver::class)->resolve(
            $company,
            'Maria Silva',
            '(11) 98765-4321',
            null,
            '11111111111',
        );
    }
}
