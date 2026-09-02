<?php

namespace App\Services\PublicBooking;

use App\Enums\AppointmentOrigin;
use App\Models\Appointment;
use App\Models\AppointmentPublicAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class PublicAppointmentTokenService
{
    public function issue(Appointment $appointment): string
    {
        $plainToken = $this->generatePlainToken();
        $tokenHash = $this->hashToken($plainToken);

        $token = new AppointmentPublicAccessToken([
            'token_hash' => $tokenHash,
            'expires_at' => $this->calculateExpiration($appointment),
        ]);

        $token->company()->associate($appointment->company_id);
        $token->appointment()->associate($appointment);
        $token->save();

        return $plainToken;
    }

    public function resolveManageUrl(Appointment $appointment, ?string $existing = null): ?string
    {
        if (filled($existing)) {
            return $existing;
        }

        if ($appointment->origin !== AppointmentOrigin::Online) {
            return null;
        }

        return route('public.appointment.manage', ['token' => $this->issue($appointment)]);
    }

    public function resolve(string $plainToken): ?AppointmentPublicAccessToken
    {
        $tokenHash = $this->hashToken($plainToken);

        $token = AppointmentPublicAccessToken::query()
            ->where('token_hash', $tokenHash)
            ->with(['appointment.company', 'appointment.professional', 'appointment.service', 'company'])
            ->first();

        if ($token === null || ! $token->isActive()) {
            return null;
        }

        if (! hash_equals($token->token_hash, $tokenHash)) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return $token->refresh();
    }

    public function revoke(Appointment $appointment): void
    {
        AppointmentPublicAccessToken::query()
            ->where('appointment_id', $appointment->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function refreshExpiration(AppointmentPublicAccessToken $token): void
    {
        $appointment = $token->appointment;

        if ($appointment === null) {
            return;
        }

        $token->update([
            'expires_at' => $this->calculateExpiration($appointment),
        ]);
    }

    public function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    protected function generatePlainToken(): string
    {
        return Str::random(48);
    }

    protected function calculateExpiration(Appointment $appointment): CarbonImmutable
    {
        $endAt = CarbonImmutable::parse($appointment->end_at);
        $fromEnd = $endAt->addDays(30);
        $maxFromIssue = CarbonImmutable::now()->addDays(120);

        return $fromEnd->lt($maxFromIssue) ? $fromEnd : $maxFromIssue;
    }
}
