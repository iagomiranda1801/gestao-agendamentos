<?php

namespace Tests\Feature\PublicBooking;

use App\Livewire\PublicBooking\BookingWizard;
use App\Services\Scheduling\CompanySchedulingSettingService;
use Livewire\Livewire;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class PublicBookingPageTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_returns_404_for_inactive_company(): void
    {
        $setup = $this->createBookableSetup(
            $this->createSchedulingCompany(['is_active' => false]),
        );
        $this->enablePublicBooking($setup['company']);

        $this->get(route('public.booking.show', ['company' => $setup['company']->slug]))
            ->assertNotFound();
    }

    public function test_returns_404_when_public_booking_is_disabled(): void
    {
        $setup = $this->createBookableSetup();

        app(CompanySchedulingSettingService::class)->update($setup['company'], [
            'public_booking_enabled' => false,
        ]);

        $this->get(route('public.booking.show', ['company' => $setup['company']->slug]))
            ->assertNotFound();
    }

    public function test_returns_404_for_missing_company_slug(): void
    {
        $this->get('/agendar/empresa-inexistente')
            ->assertNotFound();
    }

    public function test_returns_200_when_public_booking_is_enabled(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $this->get(route('public.booking.show', ['company' => $setup['company']->slug]))
            ->assertOk();
    }

    public function test_page_is_accessible_without_authentication(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $this->assertGuest();

        $this->get(route('public.booking.show', ['company' => $setup['company']->slug]))
            ->assertOk()
            ->assertSee('Serviço', false);
    }

    public function test_custom_page_content_is_escaped(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company'], [
            'booking_page_title' => '<script>alert("xss")</script>Agendar',
            'booking_page_description' => '<img src=x onerror=alert(1)>Descrição',
        ]);

        $response = $this->get(route('public.booking.show', ['company' => $setup['company']->slug]));

        $response->assertOk();
        $response->assertDontSee('<script>alert("xss")</script>', false);
        $response->assertDontSee('onerror=alert(1)', false);
        $response->assertSee('Agendar', false);
        $response->assertSee('Descrição', false);
    }

    public function test_livewire_wizard_mounts_for_enabled_company(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        Livewire::test(BookingWizard::class, ['company' => $setup['company']])
            ->assertOk()
            ->assertSet('step', BookingWizard::STEP_SERVICE);
    }

    public function test_livewire_wizard_aborts_for_disabled_booking(): void
    {
        $setup = $this->createBookableSetup();

        Livewire::test(BookingWizard::class, ['company' => $setup['company']])
            ->assertStatus(404);
    }

    public function test_service_selection_auto_advances_to_schedule(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        Livewire::test(BookingWizard::class, ['company' => $setup['company']])
            ->call('selectService', $setup['service']->getKey())
            ->assertSet('step', BookingWizard::STEP_SCHEDULE)
            ->assertSet('serviceId', $setup['service']->getKey())
            ->assertSet('scheduleDatesLoaded', true);
    }

    public function test_select_time_auto_advances_to_client_step(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);
        $localStart = $setup['localStart'];

        Livewire::test(BookingWizard::class, ['company' => $setup['company']])
            ->call('selectService', $setup['service']->getKey())
            ->set('selectedDate', $localStart->format('Y-m-d'))
            ->call('selectTime', $localStart->format('H:i'))
            ->assertSet('step', BookingWizard::STEP_CLIENT)
            ->assertSet('selectedTime', $localStart->format('H:i'));
    }
}
