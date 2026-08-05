<?php

namespace Tests\Feature\Scheduling;

use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentCreatedEmailJob;
use App\Jobs\SendProfessionalAppointmentEmailJob;
use App\Jobs\SendProfessionalAppointmentWhatsAppJob;
use App\Mail\AppointmentChangeMail;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AppointmentStatusService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class ProfessionalAppointmentNotificationTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    public function test_internal_creation_queues_both_professional_channels_and_stores_client_snapshots(): void
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

        Queue::assertPushed(SendProfessionalAppointmentEmailJob::class, fn ($job): bool => $job->appointmentId === $appointment->getKey() && $job->notificationType === 'created');
        Queue::assertPushed(SendProfessionalAppointmentWhatsAppJob::class, fn ($job): bool => $job->appointmentId === $appointment->getKey() && $job->notificationType === 'created');

        $this->assertSame($setup['client']->name, $appointment->client_name_snapshot);
        $this->assertSame($setup['client']->phone, $appointment->client_phone_snapshot);
        $this->assertSame($setup['client']->email, $appointment->client_email_snapshot);
    }

    public function test_email_is_sent_to_professional_direct_email(): void
    {
        Queue::fake();
        Mail::fake();
        $setup = $this->createBookableSetup();
        $setup['professional']->update(['email' => 'profissional@example.test']);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        (new SendProfessionalAppointmentEmailJob($appointment->getKey(), 'created'))->handle(
            app(WhatsAppConfirmationMessageBuilder::class),
            app(AppointmentNotificationRecipientService::class),
        );

        Mail::assertSent(AppointmentChangeMail::class, fn (AppointmentChangeMail $mail): bool => $mail->hasTo('profissional@example.test')
            && $mail->subjectText === "Novo agendamento - {$setup['company']->name}"
            && str_contains($mail->bodyText, $setup['client']->name)
            && str_contains($mail->bodyText, 'Agendamento interno'));
    }

    public function test_online_creation_queues_both_professional_channels(): void
    {
        Queue::fake();
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        Queue::assertPushed(SendProfessionalAppointmentEmailJob::class, fn ($job): bool => $job->appointmentId === $result->appointment->getKey() && $job->notificationType === 'created');
        Queue::assertPushed(SendProfessionalAppointmentWhatsAppJob::class, fn ($job): bool => $job->appointmentId === $result->appointment->getKey() && $job->notificationType === 'created');
    }

    public function test_email_falls_back_to_linked_user(): void
    {
        Queue::fake();
        Mail::fake();
        $setup = $this->createBookableSetup();
        $professionalUser = $this->createCompanyUser($setup['company'], [
            'email' => 'usuario-profissional@example.test',
        ]);
        $setup['professional']->update([
            'email' => null,
            'user_id' => $professionalUser->getKey(),
        ]);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        (new SendProfessionalAppointmentEmailJob($appointment->getKey(), 'created'))->handle(
            app(WhatsAppConfirmationMessageBuilder::class),
            app(AppointmentNotificationRecipientService::class),
        );

        Mail::assertSent(AppointmentChangeMail::class, fn (AppointmentChangeMail $mail): bool => $mail->hasTo('usuario-profissional@example.test'));
    }

    public function test_professional_who_is_also_admin_does_not_receive_duplicate_email(): void
    {
        Queue::fake();
        Mail::fake();
        $setup = $this->createBookableSetup();
        $setup['admin']->update(['email' => 'profissional-admin@example.test']);
        $setup['professional']->update([
            'email' => 'profissional-admin@example.test',
            'user_id' => $setup['admin']->getKey(),
        ]);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        (new SendAppointmentCreatedEmailJob($appointment->getKey()))->handle(
            app(WhatsAppConfirmationMessageBuilder::class),
            app(AppointmentNotificationRecipientService::class),
        );
        (new SendProfessionalAppointmentEmailJob($appointment->getKey(), 'created'))->handle(
            app(WhatsAppConfirmationMessageBuilder::class),
            app(AppointmentNotificationRecipientService::class),
        );

        $messagesToProfessional = Mail::sent(AppointmentChangeMail::class)
            ->filter(fn (AppointmentChangeMail $mail): bool => $mail->hasTo('profissional-admin@example.test'));

        $this->assertCount(1, $messagesToProfessional);
    }

    public function test_whatsapp_is_sent_only_to_professional_phone_with_correct_message(): void
    {
        Queue::fake();
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
        ]);
        Http::fake([
            'https://evolution.test/*' => Http::response(['key' => ['id' => 'ok']], 201),
        ]);

        $setup = $this->createBookableSetup();
        $setup['professional']->update(['phone' => '(11) 98888-7777']);
        app(CompanySchedulingSettingService::class)->update($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'empresa-1',
            'whatsapp_sender_phone' => '(11) 97777-6666',
            'notify_professional_by_whatsapp' => true,
        ]);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        (new SendProfessionalAppointmentWhatsAppJob($appointment->getKey(), 'created'))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(AppointmentNotificationRecipientService::class),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => ($request['number'] ?? null) === '5511988887777'
            && str_contains((string) ($request['text'] ?? ''), 'Novo agendamento')
            && str_contains((string) ($request['text'] ?? ''), $setup['client']->name));
    }

    public function test_manual_confirmation_queues_confirmed_notification(): void
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
        $appointment->update(['status' => AppointmentStatus::Pending, 'confirmed_at' => null]);

        app(AppointmentStatusService::class)->confirm(
            $setup['company'],
            $setup['admin'],
            $appointment->fresh(),
        );

        Queue::assertPushed(SendProfessionalAppointmentEmailJob::class, fn ($job): bool => $job->appointmentId === $appointment->getKey() && $job->notificationType === 'confirmed');
        Queue::assertPushed(SendProfessionalAppointmentWhatsAppJob::class, fn ($job): bool => $job->appointmentId === $appointment->getKey() && $job->notificationType === 'confirmed');
    }

    public function test_disabled_professional_channels_skip_delivery(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake();
        $setup = $this->createBookableSetup();
        $setup['professional']->update([
            'email' => 'profissional@example.test',
            'phone' => '(11) 98888-7777',
        ]);
        app(CompanySchedulingSettingService::class)->update($setup['company'], [
            'notify_professional_by_email' => false,
            'notify_professional_by_whatsapp' => false,
        ]);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        (new SendProfessionalAppointmentEmailJob($appointment->getKey(), 'created'))->handle(
            app(WhatsAppConfirmationMessageBuilder::class),
            app(AppointmentNotificationRecipientService::class),
        );
        (new SendProfessionalAppointmentWhatsAppJob($appointment->getKey(), 'created'))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(AppointmentNotificationRecipientService::class),
        );

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_reschedule_and_cancellation_queue_their_professional_notifications(): void
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

        $appointment = app(AppointmentService::class)->reschedule(
            $setup['company'],
            $setup['admin'],
            $appointment,
            $setup['localStart']->addHour(),
        );

        Queue::assertPushed(SendProfessionalAppointmentEmailJob::class, fn ($job): bool => $job->appointmentId === $appointment->getKey() && $job->notificationType === 'rescheduled');
        Queue::assertPushed(SendProfessionalAppointmentWhatsAppJob::class, fn ($job): bool => $job->appointmentId === $appointment->getKey() && $job->notificationType === 'rescheduled');

        app(AppointmentStatusService::class)->cancel(
            $setup['company'],
            $setup['admin'],
            $appointment,
            'Solicitação do cliente',
        );

        Queue::assertPushed(SendProfessionalAppointmentEmailJob::class, fn ($job): bool => $job->appointmentId === $appointment->getKey() && $job->notificationType === 'cancelled');
        Queue::assertPushed(SendProfessionalAppointmentWhatsAppJob::class, fn ($job): bool => $job->appointmentId === $appointment->getKey() && $job->notificationType === 'cancelled');
    }
}
