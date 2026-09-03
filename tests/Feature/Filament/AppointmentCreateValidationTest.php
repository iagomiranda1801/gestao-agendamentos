<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Pages\CalendarPage;
use App\Filament\App\Resources\Appointments\Pages\CreateAppointment;
use App\Models\Appointment;
use Livewire\Livewire;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class AppointmentCreateValidationTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_calendar_create_shows_error_for_unaligned_time(): void
    {
        $setup = $this->createBookableSetup();
        $this->authenticateForAppTenant($setup['admin'], $setup['company']);

        $date = $setup['localStart']->toDateString();

        Livewire::test(CalendarPage::class)
            ->callAction('createFromSelection', [
                'client_id' => $setup['client']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'appointment_date' => $date,
                'appointment_time' => '09:10',
            ])
            ->assertHasActionErrors(['appointment_time'])
            ->assertNotified('Não foi possível agendar');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_calendar_create_succeeds_for_aligned_time(): void
    {
        $setup = $this->createBookableSetup();
        $this->authenticateForAppTenant($setup['admin'], $setup['company']);

        Livewire::test(CalendarPage::class)
            ->callAction('createFromSelection', [
                'client_id' => $setup['client']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'appointment_date' => $setup['localStart']->toDateString(),
                'appointment_time' => $setup['localStart']->format('H:i'),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Agendamento criado');

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_listing_create_shows_error_for_unaligned_time(): void
    {
        $setup = $this->createBookableSetup();
        $this->authenticateForAppTenant($setup['admin'], $setup['company']);

        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'client_id' => $setup['client']->getKey(),
                'service_selection_mode' => 'defined',
                'service_id' => $setup['service']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'appointment_date' => $setup['localStart']->toDateString(),
                'appointment_time' => '09:10',
            ])
            ->call('create')
            ->assertHasFormErrors(['appointment_time'])
            ->assertNotified('Não foi possível agendar');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_listing_create_succeeds_for_aligned_time(): void
    {
        $setup = $this->createBookableSetup();
        $this->authenticateForAppTenant($setup['admin'], $setup['company']);

        Livewire::test(CreateAppointment::class)
            ->fillForm([
                'client_id' => $setup['client']->getKey(),
                'service_selection_mode' => 'defined',
                'service_id' => $setup['service']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'appointment_date' => $setup['localStart']->toDateString(),
                'appointment_time' => $setup['localStart']->format('H:i'),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame(1, Appointment::query()->count());
    }
}
