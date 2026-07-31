<?php

namespace Tests\Feature\Scheduling;

use App\Jobs\SendAppointmentChangeEmailJob;
use App\Jobs\SendAppointmentChangeWhatsAppJob;
use App\Jobs\SendAppointmentCreatedEmailJob;
use App\Mail\AppointmentChangeMail;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class AppointmentChangeNotificationTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_whatsapp_change_job_notifies_client_and_staff(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
        ]);

        Http::fake([
            'https://evolution.test/message/sendText/loja-1' => Http::response([
                'key' => ['id' => 'ok'],
                'status' => 'SENT',
            ], 201),
        ]);

        $setup = $this->createBookableSetup();
        $setup['company']->update(['phone' => '(11) 97777-0000']);
        $setup['client']->update(['phone' => '(11) 98888-0000']);

        app(CompanySchedulingSettingService::class)->update($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'loja-1',
            'whatsapp_sender_phone' => '(11) 97777-0000',
        ]);
        app(CompanyWhatsAppInstanceService::class)->create($setup['company'], [
            'name' => 'Principal',
            'instance_name' => 'loja-1',
            'sender_phone' => '(11) 97777-0000',
            'is_default' => true,
        ]);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        (new SendAppointmentChangeWhatsAppJob(
            $appointment->getKey(),
            'rescheduled',
            $appointment->start_at->toIso8601String(),
        ))->handle(
            app(EvolutionApiClient::class),
            app(CompanySchedulingSettingService::class),
            app(CompanyWhatsAppInstanceService::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(AppointmentNotificationRecipientService::class),
        );

        Http::assertSent(fn ($request): bool => ($request['number'] ?? null) === '5511988880000');
        Http::assertSent(fn ($request): bool => ($request['number'] ?? null) === '5511977770000');
    }

    public function test_email_change_job_notifies_client_and_staff(): void
    {
        Mail::fake();

        $setup = $this->createBookableSetup();
        $setup['company']->update(['email' => 'empresa@example.test']);
        $setup['client']->update(['email' => 'cliente@example.test']);
        $setup['admin']->update(['email' => 'admin@example.test']);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        (new SendAppointmentChangeEmailJob(
            $appointment->getKey(),
            'cancelled',
            $appointment->start_at->toIso8601String(),
        ))->handle(
            app(WhatsAppConfirmationMessageBuilder::class),
            app(AppointmentNotificationRecipientService::class),
        );

        Mail::assertSent(
            AppointmentChangeMail::class,
            fn (AppointmentChangeMail $mail): bool => $mail->fromEmail === 'empresa@example.test'
                && $mail->fromName === $setup['company']->name,
        );
        Mail::assertSent(AppointmentChangeMail::class, 2);
    }

    public function test_created_email_job_notifies_client_and_staff(): void
    {
        Mail::fake();

        $setup = $this->createBookableSetup();
        $setup['company']->update(['email' => 'empresa@example.test']);
        $setup['client']->update(['email' => 'cliente@example.test']);
        $setup['admin']->update(['email' => 'admin@example.test']);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        (new SendAppointmentCreatedEmailJob(
            $appointment->getKey(),
            'https://agendaqui.test/agendamento/token',
        ))->handle(
            app(WhatsAppConfirmationMessageBuilder::class),
            app(AppointmentNotificationRecipientService::class),
        );

        Mail::assertSent(
            AppointmentChangeMail::class,
            fn (AppointmentChangeMail $mail): bool => $mail->fromEmail === 'empresa@example.test'
                && $mail->fromName === $setup['company']->name,
        );
        Mail::assertSent(AppointmentChangeMail::class, 2);
    }
}
