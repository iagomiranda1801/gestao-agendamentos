<?php

namespace Tests\Feature\PublicBooking;

use App\Enums\CompanyRole;
use App\Jobs\SendWhatsAppStaffBookingAlertJob;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class StaffBookingNotificationTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    public function test_staff_whatsapp_goes_to_company_without_duplicating_professional_channel(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
            'services.evolution.instance' => 'default',
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['key' => ['id' => 'ok']], 200),
        ]);

        Queue::fake();

        $setup = $this->createBookableSetup();
        $setup['company']->update(['phone' => '(11) 90000-1111']);
        $setup['professional']->update(['phone' => '(11) 92222-3333']);

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

        (new SendWhatsAppStaffBookingAlertJob(
            $result->appointment->getKey(),
            $result->manageUrl,
        ))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(CompanyWhatsAppInstanceService::class),
            app(AppointmentNotificationRecipientService::class),
        );

        Http::assertSentCount(1);

        Http::assertSent(fn ($request): bool => ($request['number'] ?? null) === '5511900001111'
            && str_contains((string) ($request['text'] ?? ''), 'Novo agendamento online'));

        Http::assertNotSent(fn ($request): bool => ($request['number'] ?? null) === '5511922223333');
    }

    public function test_staff_whatsapp_falls_back_to_sender_phone(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['ok' => true], 200),
        ]);

        Queue::fake();

        $setup = $this->createBookableSetup();
        $setup['company']->update(['phone' => null]);
        $setup['professional']->update(['phone' => null]);

        $this->enablePublicBooking($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'loja-1',
            'whatsapp_sender_phone' => '34999206651',
        ]);

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        (new SendWhatsAppStaffBookingAlertJob($result->appointment->getKey()))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(CompanyWhatsAppInstanceService::class),
            app(AppointmentNotificationRecipientService::class),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => ($request['number'] ?? null) === '5534999206651');
    }

    public function test_staff_whatsapp_leaves_shared_phone_to_professional_channel(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['ok' => true], 200),
        ]);

        Queue::fake();

        $setup = $this->createBookableSetup();
        $setup['company']->update(['phone' => '11999998888']);
        $setup['professional']->update(['phone' => '11999998888']);

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

        (new SendWhatsAppStaffBookingAlertJob($result->appointment->getKey()))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(CompanyWhatsAppInstanceService::class),
            app(AppointmentNotificationRecipientService::class),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => ($request['number'] ?? null) === '5511999998888');
    }

    public function test_staff_whatsapp_uses_professional_when_company_has_no_phone(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['ok' => true], 200),
        ]);

        Queue::fake();

        $setup = $this->createBookableSetup();
        $setup['company']->update(['phone' => null]);
        $setup['professional']->update(['phone' => '(11) 92222-3333']);

        $this->enablePublicBooking($setup['company'], [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'loja-1',
            'whatsapp_sender_phone' => '11988887777',
        ]);

        $setup['company']->schedulingSetting()->first()?->forceFill([
            'whatsapp_sender_phone' => null,
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

        (new SendWhatsAppStaffBookingAlertJob($result->appointment->getKey()))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
            app(CompanyWhatsAppInstanceService::class),
            app(AppointmentNotificationRecipientService::class),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => ($request['number'] ?? null) === '5511922223333');
    }

    public function test_panel_notification_reaches_admin_and_professional_user(): void
    {
        $setup = $this->createBookableSetup();
        $admin = $setup['admin'];
        $professionalUser = $this->createCompanyUser($setup['company'], [], CompanyRole::Employee);
        $setup['professional']->update(['user_id' => $professionalUser->getKey()]);

        $this->enablePublicBooking($setup['company']);

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $admin->getMorphClass(),
            'notifiable_id' => $admin->getKey(),
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $professionalUser->getMorphClass(),
            'notifiable_id' => $professionalUser->getKey(),
        ]);

        $this->assertTrue($admin->fresh()->notifications()->exists());
        $this->assertTrue($professionalUser->fresh()->notifications()->exists());
    }
}
