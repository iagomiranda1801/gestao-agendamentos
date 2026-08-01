<?php

namespace App\Http\Controllers;

use App\Services\WhatsApp\EvolutionWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request, EvolutionWebhookService $webhooks, ?string $instance = null): JsonResponse
    {
        if (! $this->tokenIsValid($request)) {
            return response()->json(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $request->all();
            $payloadInstance = data_get($payload, 'instance');

            if (filled($instance) && filled($payloadInstance) && (string) $instance !== (string) $payloadInstance) {
                return response()->json([
                    'message' => 'A instância do webhook não corresponde à instância informada pela Evolution.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (filled($instance) && blank($payloadInstance)) {
                data_set($payload, 'instance', $instance);
            }

            $event = $webhooks->handle($payload);

            return response()->json([
                'ok' => true,
                'event_id' => $event->getKey(),
                'processed' => $event->processed_at !== null,
            ]);
        } catch (Throwable $exception) {
            Log::error('Evolution webhook failed.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['ok' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected function tokenIsValid(Request $request): bool
    {
        $expected = (string) config('services.evolution.webhook_token');

        if ($expected === '') {
            return true;
        }

        $provided = (string) (
            $request->bearerToken()
            ?: $request->header('X-Evolution-Webhook-Token')
            ?: $request->query('token')
        );

        return hash_equals($expected, $provided);
    }
}
