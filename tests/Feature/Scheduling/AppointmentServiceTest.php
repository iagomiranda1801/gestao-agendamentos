<?php

namespace Tests\Feature\Scheduling;

use App\Enums\AppointmentFlowKind;
use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\CompanyRole;
use App\Enums\ScheduleBlockType;
use App\Jobs\SendAppointmentChangeEmailJob;
use App\Jobs\SendAppointmentChangeWhatsAppJob;
use App\Jobs\SendAppointmentCreatedEmailJob;
use App\Jobs\SendWhatsAppAppointmentConfirmationJob;
use App\Models\Appointment;
use App\Models\InventoryBalance;
use App\Models\Professional;
use App\Models\Service;
use App\Models\StockMovement;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AppointmentStatusService;
use App\Services\Scheduling\ProfessionalBreakService;
use App\Services\Scheduling\ScheduleBlockService;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class AppointmentServiceTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_internal_appointment_is_created_confirmed(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame(AppointmentOrigin::Internal, $appointment->origin);
    }

    public function test_internal_appointment_queues_whatsapp_and_email_notifications(): void
    {
        Queue::fake();
        $setup = $this->createBookableSetup();

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        Queue::assertPushed(SendWhatsAppAppointmentConfirmationJob::class);
        Queue::assertPushed(SendAppointmentCreatedEmailJob::class);
    }

    public function test_internal_appointment_can_skip_initial_whatsapp_confirmation(): void
    {
        Queue::fake();
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
            ['send_whatsapp_confirmation' => false],
        );

        $this->assertFalse($appointment->send_whatsapp_confirmation);
        Queue::assertNotPushed(SendWhatsAppAppointmentConfirmationJob::class);
        Queue::assertPushed(SendAppointmentCreatedEmailJob::class);
    }

    public function test_end_at_is_calculated_by_backend(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $expectedEnd = CompanyDateTime::localToUtc(
            $setup['company'],
            $setup['localStart']->addMinutes(60),
        );

        $this->assertSame(
            $expectedEnd->format('Y-m-d H:i'),
            $appointment->end_at->format('Y-m-d H:i'),
        );
    }

    public function test_snapshots_are_stored_on_creation(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertSame($setup['service']->name, $appointment->service_name_snapshot);
        $this->assertSame((string) $setup['service']->price, (string) $appointment->price_snapshot);
        $this->assertSame(60, $appointment->duration_minutes_snapshot);
    }

    public function test_created_history_is_recorded(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertDatabaseHas('appointment_histories', [
            'appointment_id' => $appointment->getKey(),
            'type' => AppointmentHistoryType::Created->value,
        ]);
    }

    public function test_service_change_does_not_alter_existing_snapshot(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $originalName = $appointment->service_name_snapshot;
        $setup['service']->update(['name' => 'Nome alterado']);

        $appointment->refresh();

        $this->assertSame($originalName, $appointment->service_name_snapshot);
    }

    public function test_unaligned_slot_is_rejected_on_appointment_time(): void
    {
        $setup = $this->createBookableSetup();
        $unalignedStart = $setup['localStart']->setTime(9, 10);

        try {
            app(AppointmentService::class)->createInternalAppointment(
                $setup['company'],
                $setup['admin'],
                $setup['client'],
                $setup['professional'],
                $setup['service'],
                $unalignedStart,
            );
            $this->fail('Expected ValidationException for unaligned slot.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('appointment_time', $exception->errors());
            $this->assertArrayHasKey('start_at', $exception->errors());
            $this->assertSame(
                'Horário não alinhado ao intervalo da agenda.',
                $exception->errors()['appointment_time'][0],
            );
        }
    }

    public function test_overlapping_appointments_are_rejected(): void
    {
        $setup = $this->createBookableSetup();

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->expectException(ValidationException::class);

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart']->addMinutes(30),
        );
    }

    public function test_same_time_for_different_professionals_is_allowed(): void
    {
        $setup = $this->createBookableSetup();
        $otherProfessional = Professional::factory()->forCompany($setup['company'])->bookable()->active()->create();

        $setup['professional']->services()->syncWithoutDetaching([
            $setup['service']->getKey() => [
                'company_id' => $setup['company']->getKey(),
                'is_active' => true,
            ],
        ]);

        $otherProfessional->services()->attach($setup['service']->getKey(), [
            'company_id' => $setup['company']->getKey(),
            'is_active' => true,
        ]);

        $this->seedStandardWorkingHours($setup['company'], $otherProfessional);

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $second = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $otherProfessional,
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertNotNull($second);
    }

    public function test_cancelled_appointment_does_not_block_time(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        $second = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertNotNull($second);
    }

    public function test_reschedule_keeps_duration(): void
    {
        Queue::fake();

        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $newStart = $setup['localStart']->addHours(2);

        $rescheduled = app(AppointmentService::class)->reschedule(
            $setup['company'],
            $setup['admin'],
            $appointment,
            $newStart,
        );

        $this->assertSame(60, $rescheduled->duration_minutes_snapshot);
        $this->assertDatabaseHas('appointment_histories', [
            'appointment_id' => $appointment->getKey(),
            'type' => AppointmentHistoryType::Rescheduled->value,
        ]);
        Queue::assertPushed(SendAppointmentChangeWhatsAppJob::class);
        Queue::assertPushed(SendAppointmentChangeEmailJob::class);
    }

    public function test_appointment_does_not_move_stock_or_financial(): void
    {
        $setup = $this->createBookableSetup();
        $movementsBefore = StockMovement::query()->count();
        $balancesBefore = InventoryBalance::query()->count();

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame($balancesBefore, InventoryBalance::query()->count());
    }

    public function test_internal_appointment_can_be_created_with_service_to_be_defined(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            null,
            $setup['localStart'],
            [
                'service_selection_mode' => 'to_be_defined',
                'duration_minutes_snapshot' => 45,
                'appointment_reason' => 'Avaliação inicial',
            ],
        );

        $this->assertNull($appointment->service_id);
        $this->assertSame('to_be_defined', $appointment->service_selection_mode);
        $this->assertSame('A definir no atendimento', $appointment->service_name_snapshot);
        $this->assertNull($appointment->price_snapshot);
        $this->assertSame(45, $appointment->duration_minutes_snapshot);
        $this->assertSame('Avaliação inicial', $appointment->appointment_reason);
    }

    public function test_empty_service_automatically_creates_an_open_appointment(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            null,
            $setup['localStart'],
            ['duration_minutes_snapshot' => 30],
        );

        $this->assertTrue($appointment->hasServiceToBeDefined());
        $this->assertSame(30, $appointment->duration_minutes_snapshot);
    }

    public function test_open_appointment_can_be_rescheduled_to_any_bookable_professional(): void
    {
        $setup = $this->createBookableSetup();
        $otherProfessional = Professional::factory()->forCompany($setup['company'])->bookable()->active()->create();
        $this->seedStandardWorkingHours($setup['company'], $otherProfessional);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            null,
            $setup['localStart'],
            ['duration_minutes_snapshot' => 30],
        );

        $rescheduled = app(AppointmentService::class)->reschedule(
            $setup['company'],
            $setup['admin'],
            $appointment,
            $setup['localStart']->addHours(2),
            $otherProfessional,
        );

        $this->assertSame($otherProfessional->getKey(), $rescheduled->professional_id);
    }

    public function test_fit_in_overlaps_another_appointment_and_records_authorization(): void
    {
        $setup = $this->createBookableSetup();
        $setup['service']->update([
            'buffer_before_minutes' => 10,
            'buffer_after_minutes' => 5,
        ]);

        $existing = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $fitIn = app(AppointmentService::class)->createFitInAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
            ['fit_in_reason' => 'Cliente chegou antes'],
        );

        $this->assertSame(AppointmentStatus::Confirmed, $fitIn->status);
        $this->assertSame(AppointmentFlowKind::FitIn, $fitIn->flow_kind);
        $this->assertSame('Cliente chegou antes', $fitIn->fit_in_reason);
        $this->assertSame($setup['admin']->getKey(), $fitIn->fit_in_authorized_by);
        $this->assertNotNull($fitIn->fit_in_authorized_at);

        $history = $fitIn->histories()->where('type', AppointmentHistoryType::Created)->first();
        $this->assertNotNull($history);
        $this->assertTrue($history->metadata['had_conflict']);
        $this->assertSame($existing->getKey(), $history->metadata['conflicts'][0]['id']);
        $this->assertStringContainsString('preparação 10 min', $history->description);
        $this->assertStringContainsString('Encaixe autorizado', $history->description);

        $events = app(AppointmentService::class)->fetchCalendarEvents(
            $setup['company'],
            $setup['admin'],
            $setup['localStart']->startOfDay()->utc(),
            $setup['localStart']->endOfDay()->utc(),
        );
        $fitEvent = collect($events)->first(fn (array $event): bool => ($event['extendedProps']['fitIn'] ?? false) === true);

        $this->assertNotNull($fitEvent);
        $this->assertStringStartsWith('Encaixe · ', $fitEvent['title']);
        $this->assertContains('fc-event-fit-in', $fitEvent['classNames']);
    }

    public function test_fit_in_without_a_conflict_stays_marked_as_fit_in(): void
    {
        $setup = $this->createBookableSetup();

        $fitIn = app(AppointmentService::class)->createFitInAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
            ['fit_in_reason' => 'Encaixe preventivo'],
        );

        $history = $fitIn->histories()->where('type', AppointmentHistoryType::Created)->first();

        $this->assertSame(AppointmentFlowKind::FitIn, $fitIn->flow_kind);
        $this->assertFalse($history->metadata['had_conflict']);
        $this->assertStringContainsString('sem conflito no momento de salvar', $history->description);
    }

    public function test_fit_in_still_respects_schedule_constraints(): void
    {
        $setup = $this->createBookableSetup();
        $unlinked = Service::factory()->forCompany($setup['company'])->bookable()->active()->create();

        $this->assertSchedulingRejected(
            fn () => app(AppointmentService::class)->createFitInAppointment(
                $setup['company'],
                $setup['admin'],
                $setup['client'],
                $setup['professional'],
                $unlinked,
                $setup['localStart'],
                ['fit_in_reason' => 'Tentativa'],
            ),
            'Profissional não está associado a este serviço.',
        );

        $this->assertSchedulingRejected(
            fn () => app(AppointmentService::class)->createFitInAppointment(
                $setup['company'],
                $setup['admin'],
                $setup['client'],
                $setup['professional'],
                $setup['service'],
                $setup['localStart']->setTime(8, 0),
                ['fit_in_reason' => 'Cedo demais'],
            ),
            'Horário fora da jornada do profissional.',
        );

        app(ProfessionalBreakService::class)->create($setup['company'], $setup['professional'], [
            'name' => 'Intervalo',
            'weekday' => $setup['localStart']->dayOfWeek,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        $this->assertSchedulingRejected(
            fn () => app(AppointmentService::class)->createFitInAppointment(
                $setup['company'],
                $setup['admin'],
                $setup['client'],
                $setup['professional'],
                $setup['service'],
                $setup['localStart'],
                ['fit_in_reason' => 'No intervalo'],
            ),
            'Horário conflita com intervalo do profissional.',
        );
    }

    public function test_fit_in_still_respects_schedule_blocks(): void
    {
        $setup = $this->createBookableSetup();

        app(ScheduleBlockService::class)->create($setup['company'], $setup['admin'], [
            'type' => ScheduleBlockType::Manual,
            'title' => 'Reunião',
            'professional_id' => $setup['professional']->getKey(),
            'start_date' => $setup['localStart']->toDateString(),
            'start_time' => '09:30',
            'end_date' => $setup['localStart']->toDateString(),
            'end_time' => '11:30',
        ]);

        $this->assertSchedulingRejected(
            fn () => app(AppointmentService::class)->createFitInAppointment(
                $setup['company'],
                $setup['admin'],
                $setup['client'],
                $setup['professional'],
                $setup['service'],
                $setup['localStart'],
                ['fit_in_reason' => 'Durante o bloqueio'],
            ),
            'Horário conflita com bloqueio da agenda.',
        );

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_quick_attendance_starts_now_without_slot_alignment_or_whatsapp(): void
    {
        Queue::fake();
        $setup = $this->createBookableSetup();
        $moment = $setup['localStart']->setTime(10, 7, 30);

        $appointment = $this->freeze($moment, fn () => app(AppointmentService::class)->createQuickAttendance(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
        ));

        $localStart = CompanyDateTime::utcToLocal($setup['company'], $appointment->start_at);

        $this->assertSame(AppointmentStatus::InProgress, $appointment->status);
        $this->assertSame(AppointmentFlowKind::QuickAttendance, $appointment->flow_kind);
        $this->assertFalse($appointment->send_whatsapp_confirmation);
        $this->assertNull($appointment->fit_in_authorized_at);
        $this->assertSame('10:07:30', $localStart->format('H:i:s'));
        $this->assertSame($setup['admin']->getKey(), $appointment->started_by);
        $this->assertSame(1, Appointment::query()->count());
        Queue::assertNotPushed(SendWhatsAppAppointmentConfirmationJob::class);
    }

    public function test_quick_attendance_with_overlap_requires_a_reason_and_rolls_back(): void
    {
        $setup = $this->createBookableSetup();
        $moment = $setup['localStart']->setTime(10, 7, 30);

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->freeze($moment, function () use ($setup): void {
            $this->assertSchedulingRejected(
                fn () => app(AppointmentService::class)->createQuickAttendance(
                    $setup['company'],
                    $setup['admin'],
                    $setup['client'],
                    $setup['professional'],
                    $setup['service'],
                ),
                'Há outro compromisso neste horário. Informe o motivo do encaixe para atender agora.',
            );
        });

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_quick_attendance_with_overlap_and_reason_stays_in_progress(): void
    {
        $setup = $this->createBookableSetup();
        $moment = $setup['localStart']->setTime(10, 7, 30);

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $appointment = $this->freeze($moment, fn () => app(AppointmentService::class)->createQuickAttendance(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            ['fit_in_reason' => 'Começou pela cliente que chegou antes'],
        ));

        $this->assertSame(AppointmentStatus::InProgress, $appointment->status);
        $this->assertSame(AppointmentFlowKind::QuickAttendance, $appointment->flow_kind);
        $this->assertSame('Começou pela cliente que chegou antes', $appointment->fit_in_reason);
        $this->assertNotNull($appointment->fit_in_authorized_at);
        $this->assertTrue($appointment->isMarkedAsFitIn());
        $this->assertSame(2, Appointment::query()->count());
    }

    public function test_quick_attendance_rolls_back_when_start_fails(): void
    {
        Queue::fake();
        $setup = $this->createBookableSetup();
        $moment = $setup['localStart']->setTime(10, 7, 30);

        $this->mock(AppointmentStatusService::class, function ($mock): void {
            $mock->shouldReceive('start')->once()->andThrow(ValidationException::withMessages([
                'status' => 'Falha ao iniciar.',
            ]));
        });

        $this->freeze($moment, function () use ($setup): void {
            $this->assertSchedulingRejected(
                fn () => app(AppointmentService::class)->createQuickAttendance(
                    $setup['company'],
                    $setup['admin'],
                    $setup['client'],
                    $setup['professional'],
                    $setup['service'],
                ),
                'Falha ao iniciar.',
            );
        });

        $this->assertSame(0, Appointment::query()->count());
        Queue::assertNotPushed(SendWhatsAppAppointmentConfirmationJob::class);
    }

    public function test_quick_attendance_still_respects_blocks_breaks_and_links(): void
    {
        $setup = $this->createBookableSetup();
        $moment = $setup['localStart']->setTime(10, 7, 30);
        $unlinked = Service::factory()->forCompany($setup['company'])->bookable()->active()->create();

        $this->freeze($moment, function () use ($setup, $unlinked): void {
            $this->assertSchedulingRejected(
                fn () => app(AppointmentService::class)->createQuickAttendance(
                    $setup['company'],
                    $setup['admin'],
                    $setup['client'],
                    $setup['professional'],
                    $unlinked,
                ),
                'Profissional não está associado a este serviço.',
            );
        });

        app(ProfessionalBreakService::class)->create($setup['company'], $setup['professional'], [
            'name' => 'Intervalo',
            'weekday' => $moment->dayOfWeek,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        $this->freeze($moment, function () use ($setup): void {
            $this->assertSchedulingRejected(
                fn () => app(AppointmentService::class)->createQuickAttendance(
                    $setup['company'],
                    $setup['admin'],
                    $setup['client'],
                    $setup['professional'],
                    $setup['service'],
                ),
                'Horário conflita com intervalo do profissional.',
            );
        });

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_quick_attendance_outside_working_hours_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $moment = $setup['localStart']->setTime(8, 0);

        $this->freeze($moment, function () use ($setup): void {
            $this->assertSchedulingRejected(
                fn () => app(AppointmentService::class)->createQuickAttendance(
                    $setup['company'],
                    $setup['admin'],
                    $setup['client'],
                    $setup['professional'],
                    $setup['service'],
                ),
                'Horário fora da jornada do profissional.',
            );
        });
    }

    public function test_quick_attendance_during_a_block_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $moment = $setup['localStart']->setTime(10, 7, 30);

        app(ScheduleBlockService::class)->create($setup['company'], $setup['admin'], [
            'type' => ScheduleBlockType::Manual,
            'title' => 'Reunião',
            'professional_id' => $setup['professional']->getKey(),
            'start_date' => $moment->toDateString(),
            'start_time' => '09:30',
            'end_date' => $moment->toDateString(),
            'end_time' => '11:30',
        ]);

        $this->freeze($moment, function () use ($setup): void {
            $this->assertSchedulingRejected(
                fn () => app(AppointmentService::class)->createQuickAttendance(
                    $setup['company'],
                    $setup['admin'],
                    $setup['client'],
                    $setup['professional'],
                    $setup['service'],
                ),
                'Horário conflita com bloqueio da agenda.',
            );
        });

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_normal_update_cannot_overlap_and_fit_in_update_requires_a_new_reason(): void
    {
        $setup = $this->createBookableSetup();
        $service = app(AppointmentService::class);

        $first = $service->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );
        $second = $service->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart']->addHours(2),
        );

        $this->assertSchedulingRejected(
            fn () => $service->updateAppointment($setup['company'], $setup['admin'], $second, [
                'appointment_date' => $setup['localStart']->toDateString(),
                'appointment_time' => $setup['localStart']->addMinutes(30)->format('H:i'),
            ]),
            'Horário conflita com outro agendamento.',
        );

        $fitIn = $service->createFitInAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
            ['fit_in_reason' => 'Chegou antes'],
        );

        $service->updateAppointment($setup['company'], $setup['admin'], $fitIn, [
            'notes' => 'Observação interna da recepção',
        ]);
        $fitIn->refresh();
        $this->assertSame('Chegou antes', $fitIn->fit_in_reason);
        $this->assertSame('Observação interna da recepção', $fitIn->notes);

        $this->assertSchedulingRejected(
            fn () => $service->updateAppointment($setup['company'], $setup['admin'], $fitIn, [
                'appointment_date' => $setup['localStart']->toDateString(),
                'appointment_time' => $setup['localStart']->addHours(2)->format('H:i'),
            ]),
            'O novo horário conflita com outro compromisso. Informe o motivo para autorizar a sobreposição.',
        );

        $fitIn->refresh();
        $this->assertSame('Chegou antes', $fitIn->fit_in_reason);

        $updated = $service->updateAppointment($setup['company'], $setup['admin'], $fitIn, [
            'appointment_date' => $setup['localStart']->toDateString(),
            'appointment_time' => $setup['localStart']->addHours(2)->format('H:i'),
            'fit_in_reason' => 'A outra cliente atrasou',
        ]);

        $this->assertSame('A outra cliente atrasou', $updated->fit_in_reason);
        $this->assertSame(AppointmentFlowKind::FitIn, $updated->flow_kind);

        $created = $updated->histories()->where('type', AppointmentHistoryType::Created)->first();
        $reauthorized = $updated->histories()->where('type', AppointmentHistoryType::Updated)->latest('id')->first();

        $this->assertSame('Chegou antes', $created->metadata['fit_in_reason']);
        $this->assertSame('A outra cliente atrasou', $reauthorized->metadata['fit_in_reason']);
        $this->assertSame($second->getKey(), $reauthorized->metadata['conflicts'][0]['id']);
        $this->assertNotSame($first->getKey(), $updated->getKey());
    }

    public function test_dragging_a_fit_in_onto_another_appointment_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $service = app(AppointmentService::class);

        $fitIn = $service->createFitInAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
            ['fit_in_reason' => 'Encaixe'],
        );

        $service->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart']->addHours(2),
        );

        $this->expectException(ValidationException::class);

        $service->reschedule(
            $setup['company'],
            $setup['admin'],
            $fitIn,
            $setup['localStart']->addHours(2),
        );
    }

    public function test_employee_cannot_create_fit_in_and_other_company_data_is_isolated(): void
    {
        $setup = $this->createBookableSetup();
        $other = $this->createBookableSetup();
        $employee = $this->createCompanyUser($setup['company'], [], CompanyRole::Employee);
        $receptionist = $this->createCompanyUser($setup['company'], [], CompanyRole::Receptionist);

        try {
            app(AppointmentService::class)->createFitInAppointment(
                $setup['company'],
                $employee,
                $setup['client'],
                $setup['professional'],
                $setup['service'],
                $setup['localStart'],
                ['fit_in_reason' => 'Sem permissão'],
            );
            $this->fail('Expected the employee to be forbidden.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(0, Appointment::query()->where('company_id', $setup['company']->getKey())->count());

        try {
            app(AppointmentService::class)->createFitInAppointment(
                $setup['company'],
                $setup['admin'],
                $other['client'],
                $setup['professional'],
                $setup['service'],
                $setup['localStart'],
                ['fit_in_reason' => 'Outra empresa'],
            );
            $this->fail('Expected a missing record for the other company.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        Appointment::factory()->create([
            'company_id' => $other['company']->getKey(),
            'client_id' => $other['client']->getKey(),
            'professional_id' => $setup['professional']->getKey(),
            'service_id' => $other['service']->getKey(),
            'start_at' => CompanyDateTime::localToUtc($setup['company'], $setup['localStart']),
            'end_at' => CompanyDateTime::localToUtc($setup['company'], $setup['localStart']->addHour()),
            'status' => AppointmentStatus::Confirmed,
            'created_by' => $other['admin']->getKey(),
        ]);

        $fitIn = app(AppointmentService::class)->createFitInAppointment(
            $setup['company'],
            $receptionist,
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
            ['fit_in_reason' => 'Recepção autorizou'],
        );

        $history = $fitIn->histories()->where('type', AppointmentHistoryType::Created)->first();

        $this->assertSame($receptionist->getKey(), $fitIn->fit_in_authorized_by);
        $this->assertFalse($history->metadata['had_conflict']);
        $this->assertSame([], $history->metadata['conflicts']);
    }

    private function assertSchedulingRejected(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected validation to reject the appointment.');
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten();
            $this->assertTrue(
                $messages->contains($message),
                $messages->implode(' | '),
            );
        }
    }

    private function freeze(CarbonImmutable $moment, callable $callback): mixed
    {
        Carbon::setTestNow($moment);

        try {
            return $callback();
        } finally {
            Carbon::setTestNow();
        }
    }
}
