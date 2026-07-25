<?php

namespace Tests\Feature\PublicBooking;

use App\Models\AppointmentPublicAccessToken;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\PublicBooking\PublicAppointmentTokenService;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class PublicAppointmentTokenTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    protected function createOnlineAppointment(): array
    {
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

        return array_merge($setup, [
            'appointment' => $result->appointment,
            'plainToken' => $result->plainToken,
        ]);
    }

    public function test_issue_creates_active_token(): void
    {
        $context = $this->createOnlineAppointment();
        $tokenService = app(PublicAppointmentTokenService::class);

        $plainToken = $tokenService->issue($context['appointment']);
        $resolved = $tokenService->resolve($plainToken);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->isActive());
        $this->assertSame($context['appointment']->getKey(), $resolved->appointment_id);
        $this->assertSame(
            $tokenService->hashToken($plainToken),
            $resolved->token_hash,
        );
    }

    public function test_resolve_returns_token_for_valid_plain_token(): void
    {
        $context = $this->createOnlineAppointment();
        $tokenService = app(PublicAppointmentTokenService::class);

        $resolved = $tokenService->resolve($context['plainToken']);

        $this->assertNotNull($resolved);
        $this->assertSame($context['appointment']->getKey(), $resolved->appointment->getKey());
        $this->assertNotNull($resolved->last_used_at);
    }

    public function test_resolve_returns_null_for_unknown_token(): void
    {
        $this->assertNull(
            app(PublicAppointmentTokenService::class)->resolve('token-desconhecido-'.str_repeat('x', 32)),
        );
    }

    public function test_revoke_invalidates_token(): void
    {
        $context = $this->createOnlineAppointment();
        $tokenService = app(PublicAppointmentTokenService::class);

        $tokenService->revoke($context['appointment']);

        $this->assertNull($tokenService->resolve($context['plainToken']));
        $this->assertSame(
            0,
            AppointmentPublicAccessToken::query()
                ->where('appointment_id', $context['appointment']->getKey())
                ->active()
                ->count(),
        );
    }

    public function test_expired_token_cannot_be_resolved(): void
    {
        $context = $this->createOnlineAppointment();
        $tokenService = app(PublicAppointmentTokenService::class);

        AppointmentPublicAccessToken::query()
            ->where('appointment_id', $context['appointment']->getKey())
            ->update(['expires_at' => now()->subMinute()]);

        $this->assertNull($tokenService->resolve($context['plainToken']));
    }

    public function test_refresh_expiration_updates_expires_at(): void
    {
        $context = $this->createOnlineAppointment();
        $tokenService = app(PublicAppointmentTokenService::class);
        $token = AppointmentPublicAccessToken::query()
            ->where('appointment_id', $context['appointment']->getKey())
            ->firstOrFail();

        $originalExpiration = $token->expires_at;
        $context['appointment']->update([
            'end_at' => $context['appointment']->end_at->addDays(5),
        ]);

        $tokenService->refreshExpiration($token->refresh());

        $this->assertTrue($token->refresh()->expires_at->gt($originalExpiration));
    }

    public function test_hash_token_is_deterministic(): void
    {
        $tokenService = app(PublicAppointmentTokenService::class);
        $plain = 'token-fixo-para-teste';

        $this->assertSame(
            hash('sha256', $plain),
            $tokenService->hashToken($plain),
        );
    }
}
