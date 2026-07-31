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
    public function __invoke(Request $request, EvolutionWebhookService $webhooks): JsonResponse
    {
        if (! $this->tokenIsValid($request)) {
            return response()->json(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $event = $webhooks->handle($request->all());

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
