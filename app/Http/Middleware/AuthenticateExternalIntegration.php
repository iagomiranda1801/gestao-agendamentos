<?php

namespace App\Http\Middleware;

use App\Models\ExternalIntegration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateExternalIntegration
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && ! $request->isSecure()) {
            return $this->error(403, 'HTTPS obrigatório.');
        }

        $key = $request->bearerToken();

        if (! is_string($key) || ! preg_match('/^aqi_[a-f0-9]{64}$/D', $key)) {
            return $this->error(401, 'Credencial ausente ou inválida.');
        }

        $integration = ExternalIntegration::query()
            ->where('key_hash', hash('sha256', $key))
            ->whereNull('revoked_at')
            ->first();

        if ($integration === null) {
            return $this->error(401, 'Credencial ausente ou inválida.');
        }

        if (! $integration->is_active || ! $integration->company?->is_active) {
            return $this->error(403, 'Integração sem permissão.');
        }

        if (! $integration->allows('customers:create')) {
            return $this->error(403, 'Integração sem permissão.');
        }

        $request->attributes->set('external_integration', $integration);
        $integration->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    private function error(int $status, string $message): Response
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
