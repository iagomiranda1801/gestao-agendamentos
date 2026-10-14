<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalIntegration;
use App\Services\Integrations\ExternalCustomerService;
use App\Support\Cpf;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class IntegrationCustomerController extends Controller
{
    public function __invoke(Request $request, ExternalCustomerService $service): JsonResponse
    {
        /** @var ExternalIntegration $integration */
        $integration = $request->attributes->get('external_integration');
        $input = $request->all();
        $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null);
        $input['phone'] = is_string($input['phone'] ?? null) ? PhoneNormalizer::normalize($input['phone']) : ($input['phone'] ?? null);
        $input['cpf'] = is_string($input['cpf'] ?? null)
            ? (Cpf::normalize($input['cpf']) ?? (trim($input['cpf']) === '' ? null : $input['cpf']))
            : ($input['cpf'] ?? null);
        $input['email'] = is_string($input['email'] ?? null) ? mb_strtolower(trim($input['email'])) : ($input['email'] ?? null);

        try {
            $data = Validator::make($input, [
                'company_id' => ['prohibited'],
                'tenant_id' => ['prohibited'],
                'name' => ['required', 'string', 'min:2', 'max:255'],
                'phone' => ['required', 'string', 'regex:/^[0-9]{10,13}$/D'],
                'email' => ['nullable', 'email:rfc', 'max:255'],
                'cpf' => ['nullable', 'string', function (string $attribute, mixed $value, $fail): void {
                    if ($value !== null && ! Cpf::isValid((string) $value)) {
                        $fail('Informe um CPF válido.');
                    }
                }],
                'source' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/D'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'metadata' => ['nullable', 'array:access_type,referred_by,club,admission_status'],
                'metadata.access_type' => ['nullable', 'string', 'max:80'],
                'metadata.referred_by' => ['nullable', 'string', 'max:255'],
                'metadata.club' => ['nullable', 'string', 'max:120'],
                'metadata.admission_status' => ['nullable', 'in:pending,approved,rejected'],
            ])->validate();

            [$client, $created] = $service->register($integration, $data);

            return response()->json([
                'success' => true,
                'message' => $created ? 'Cliente cadastrado com sucesso.' : 'Cliente já cadastrado nesta empresa.',
                'data' => ['id' => $client->getKey(), 'name' => $client->name, 'created' => $created],
            ], $created ? 201 : 200);
        } catch (ValidationException $exception) {
            return response()->json(['success' => false, 'message' => 'Dados inválidos.', 'errors' => $exception->errors()], 422);
        } catch (ConflictHttpException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => 'Erro interno.'], 500);
        }
    }
}
