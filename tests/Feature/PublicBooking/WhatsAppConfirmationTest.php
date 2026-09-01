<?php

namespace Tests\Feature\PublicBooking;

use App\Events\OnlineAppointmentCreated;
use App\Jobs\NotifyStaffOfOnlineBookingJob;
use App\Jobs\SendAppointmentCreatedEmailJob;
use App\Jobs\SendWhatsAppAppointmentConfirmationJob;
use App\Jobs\SendWhatsAppStaffBookingAlertJob;
use App\Listeners\SendOnlineBookingWhatsAppNotification;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class WhatsAppConfirmationTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    public function test_booking_dispatches_whatsapp_job(): void
    {
        Queue::fake();

        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'demo',
            'whatsapp_sender_phone' => '11999998888',
        ]);

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        $this->assertTrue($result->whatsappQueued);

        Queue::assertPushed(SendWhatsAppAppointmentConfirmationJob::class, function (
            SendWhatsAppAppointmentConfirmationJob $job,
        ) use ($result): bool {
            return $job->appointmentId === $result->appointment->getKey()
                && $job->manageUrl === $result->manageUrl;
        });
    }

    public function test_job_sends_text_via_evolution_when_enabled(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
            'services.evolution.instance' => 'default',
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['key' => ['id' => 'ok']], 200),
        ]);

        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'loja-1',
            'whatsapp_sender_phone' => '11988887777',
            'whatsapp_confirmation_template' => 'Oi {nome}, codigo {codigo}',
        ]);

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
                ['clientName' => 'João', 'clientPhone' => '(11) 91234-5678'],
            ),
        );

        (new SendWhatsAppAppointmentConfirmationJob(
            $result->appointment->getKey(),
            $result->manageUrl,
        ))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(CompanyWhatsAppInstanceService::class),
        );

        Http::assertSent(function ($request) use ($result): bool {
            return $request->url() === 'https://evolution.test/message/sendText/loja-1'
                && $request->hasHeader('apikey', 'test-key')
                && ($request['number'] ?? null) === '5511912345678'
                && str_contains((string) ($request['text'] ?? ''), 'João')
                && str_contains((string) ($request['text'] ?? ''), $result->confirmationCode);
        });
    }

    public function test_job_falls_back_to_env_instance(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
            'services.evolution.instance' => 'default-global',
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['key' => ['id' => 'ok']], 200),
        ]);

        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        // Bypass service validation by forcing enabled without instance (legacy/misconfig).
        $setup['company']->schedulingSetting()->first()->forceFill([
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => null,
            'whatsapp_sender_phone' => '11999998888',
        ])->save();

        $setup['company']->unsetRelation('schedulingSetting');
        $setup['company']->load('schedulingSetting');

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        $this->assertTrue($result->whatsappQueued);

        (new SendWhatsAppAppointmentConfirmationJob(
            $result->appointment->getKey(),
            $result->manageUrl,
        ))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(CompanyWhatsAppInstanceService::class),
        );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://evolution.test/message/sendText/default-global');
    }

    public function test_job_is_noop_when_whatsapp_disabled(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
            'services.evolution.instance' => 'default',
        ]);

        Http::fake();

        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company'], [
            'whatsapp_notifications_enabled' => false,
        ]);

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        $this->assertFalse($result->whatsappQueued);

        (new SendWhatsAppAppointmentConfirmationJob(
            $result->appointment->getKey(),
            $result->manageUrl,
        ))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(CompanyWhatsAppInstanceService::class),
        );

        Http::assertNothingSent();
    }

    public function test_api_failure_does_not_throw(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
            'services.evolution.instance' => 'default',
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['error' => 'fail'], 500),
        ]);

        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'loja-1',
            'whatsapp_sender_phone' => '11988887777',
        ]);

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        (new SendWhatsAppAppointmentConfirmationJob(
            $result->appointment->getKey(),
            $result->manageUrl,
        ))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(CompanyWhatsAppInstanceService::class),
        );

        $this->assertTrue(true);
    }

    public function test_api_failure_rethrows_when_queue_is_not_sync(): void
    {
        config([
            'queue.default' => 'database',
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
            'services.evolution.instance' => 'default',
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['error' => 'fail'], 500),
        ]);

        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'loja-1',
            'whatsapp_sender_phone' => '11988887777',
        ]);

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        $this->expectException(RequestException::class);

        (new SendWhatsAppAppointmentConfirmationJob(
            $result->appointment->getKey(),
            $result->manageUrl,
        ))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(CompanyWhatsAppInstanceService::class),
        );
    }

    public function test_job_uses_default_company_instance_when_settings_instance_is_blank(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
            'services.evolution.instance' => null,
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['key' => ['id' => 'ok']], 200),
        ]);

        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'loja-1',
            'whatsapp_sender_phone' => '11988887777',
        ]);

        app(CompanyWhatsAppInstanceService::class)->create($setup['company'], [
            'name' => 'Principal',
            'instance_name' => 'conexao-padrao',
            'sender_phone' => '11988887777',
            'is_default' => true,
        ]);

        $setup['company']->schedulingSetting()->first()?->forceFill([
            'whatsapp_instance' => null,
        ])->save();
        $setup['company']->unsetRelation('schedulingSetting');

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        (new SendWhatsAppAppointmentConfirmationJob(
            $result->appointment->getKey(),
            $result->manageUrl,
        ))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(CompanyWhatsAppInstanceService::class),
        );

        Http::assertSent(fn ($request): bool => $request->url() === 'https://evolution.test/message/sendText/conexao-padrao');
    }

    public function test_listener_dispatches_job(): void
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

        (new SendOnlineBookingWhatsAppNotification)->handle(
            new OnlineAppointmentCreated($result->appointment, $result->manageUrl),
        );

        Queue::assertPushed(SendWhatsAppAppointmentConfirmationJob::class);
        Queue::assertPushed(SendWhatsAppStaffBookingAlertJob::class);
        Queue::assertPushed(SendAppointmentCreatedEmailJob::class);
        Queue::assertPushed(NotifyStaffOfOnlineBookingJob::class);
    }

    public function test_settings_require_instance_and_sender_phone_when_enabled(): void
    {
        config(['services.evolution.instance' => null]);

        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $this->expectException(ValidationException::class);

        app(CompanySchedulingSettingService::class)->update($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => null,
            'whatsapp_sender_phone' => null,
        ]);
    }

    public function test_settings_accept_whatsapp_config_when_complete(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $setting = app(CompanySchedulingSettingService::class)->update($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'estudio-ana',
            'whatsapp_sender_phone' => '(11) 98888-7777',
        ]);

        $this->assertTrue($setting->whatsapp_notifications_enabled);
        $this->assertSame('estudio-ana', $setting->whatsapp_instance);
        $this->assertSame('11988887777', $setting->whatsapp_sender_phone);
    }

    public function test_settings_accept_global_whatsapp_instance_when_enabled(): void
    {
        config(['services.evolution.instance' => 'default-global']);

        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $setting = app(CompanySchedulingSettingService::class)->update($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => null,
            'whatsapp_sender_phone' => '(11) 98888-7777',
        ]);

        $this->assertTrue($setting->whatsapp_notifications_enabled);
        $this->assertNull($setting->whatsapp_instance);
        $this->assertSame('11988887777', $setting->whatsapp_sender_phone);
    }
}
