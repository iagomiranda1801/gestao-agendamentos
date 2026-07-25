<?php

namespace Tests\Feature\PublicBooking;

use App\Livewire\PublicBooking\BookingWizard;
use App\Models\Client;
use App\Services\PublicBooking\PublicBookingRateLimiter;
use App\Services\PublicBooking\PublicClientLookupService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class PhoneLookupTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_lookup_by_phone_returns_client_data(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        Client::factory()->forCompany($setup['company'])->active()->create([
            'name' => 'Ana Costa',
            'phone' => '(11) 98888-7777',
            'phone_normalized' => '11988887777',
            'email' => 'ana@example.com',
        ]);

        $result = app(PublicClientLookupService::class)->lookupByPhone(
            $setup['company'],
            '(11) 98888-7777',
        );

        $this->assertTrue($result['found']);
        $this->assertSame('Ana Costa', $result['name']);
        $this->assertSame('(11) 98888-7777', $result['phone']);
        $this->assertSame('ana@example.com', $result['email']);
    }

    public function test_lookup_by_phone_not_found_does_not_block(): void
    {
        $setup = $this->createBookableSetup();

        $result = app(PublicClientLookupService::class)->lookupByPhone(
            $setup['company'],
            '11999998888',
        );

        $this->assertFalse($result['found']);
        $this->assertStringContainsString('não cadastrado', $result['message']);
    }

    public function test_wizard_autofills_from_phone_lookup(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        Client::factory()->forCompany($setup['company'])->active()->create([
            'name' => 'Pedro Lima',
            'phone' => '(21) 97777-6666',
            'phone_normalized' => '21977776666',
            'email' => 'pedro@example.com',
        ]);

        Livewire::test(BookingWizard::class, ['company' => $setup['company']])
            ->set('clientPhone', '21977776666')
            ->assertSet('clientName', 'Pedro Lima')
            ->assertSet('clientEmail', 'pedro@example.com')
            ->assertSet('clientLookupFound', true);
    }

    public function test_client_lookup_is_rate_limited(): void
    {
        $setup = $this->createBookableSetup();
        $limiter = app(PublicBookingRateLimiter::class);

        for ($i = 0; $i < 20; $i++) {
            $limiter->assertClientLookupAllowed((int) $setup['company']->getKey(), '127.0.0.1');
        }

        $this->expectException(ValidationException::class);

        $limiter->assertClientLookupAllowed((int) $setup['company']->getKey(), '127.0.0.1');
    }
}
