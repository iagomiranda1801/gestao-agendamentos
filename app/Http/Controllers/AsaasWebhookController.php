<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Asaas\AsaasWebhookService;
use App\Services\Asaas\SaleFiscalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AsaasWebhookController extends Controller
{
    public function __invoke(Request $request, Company $company, AsaasWebhookService $webhooks): JsonResponse
    {
        return $this->receive($request, $company, $webhooks->handle(...));
    }

    public function fiscalSandbox(Request $request, Company $company, SaleFiscalService $fiscal): JsonResponse
    {
        return $this->receive($request, $company, $fiscal->handleWebhook(...));
    }

    private function receive(Request $request, Company $company, callable $handle): JsonResponse
    {
        $setting = $company->asaasSetting;
        $provided = (string) $request->header('asaas-access-token');
        $expected = (string) ($setting->webhook_token ?? '');

        if ($setting === null || $expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $handle($company, $request->all());
        } catch (Throwable $exception) {
            Log::error('Asaas webhook failed.', [
                'company_id' => $company->getKey(),
                'error_type' => $exception::class,
            ]);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }
}
