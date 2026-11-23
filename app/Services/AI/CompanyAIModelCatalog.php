<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CompanyAIModelCatalog
{
    /** @return array<string, string> */
    public function models(int $companyId, string $provider, string $key, bool $refresh = false): array
    {
        if (! in_array($provider, ['gemini', 'openai', 'openrouter'], true) || blank($key)) {
            throw new RuntimeException('Selecione o provedor e informe a chave de API para carregar os modelos.');
        }

        $fingerprint = hash_hmac('sha256', $key, (string) config('app.key'));
        $cacheKey = "company-ai-models:{$companyId}:{$provider}:{$fingerprint}";
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(10), fn (): array => $this->fetch($provider, $key));
    }

    /** @return array<string, string> */
    private function fetch(string $provider, string $key): array
    {
        $url = match ($provider) {
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'openai' => 'https://api.openai.com/v1/models',
            'openrouter' => 'https://openrouter.ai/api/v1/models',
        };
        $models = [];
        $pageToken = null;
        $seenTokens = [];

        try {
            do {
                $request = Http::acceptJson()->connectTimeout(5)->timeout(10);
                $request = $provider === 'gemini'
                    ? $request->withHeaders(['x-goog-api-key' => $key])
                    : $request->withToken($key);
                $query = $provider === 'gemini'
                    ? array_filter(['pageSize' => 1000, 'pageToken' => $pageToken]) : [];
                $response = $request->get($url, $query)->throw();
                $rows = $response->json($provider === 'gemini' ? 'models' : 'data');
                if (! is_array($rows) || ! array_is_list($rows)) {
                    throw new RuntimeException('O provedor retornou uma lista de modelos inválida. Tente atualizar ou informe o modelo manualmente.');
                }

                foreach ($rows as $row) {
                    if (! is_array($row) || ! $this->supportsText($provider, $row)) {
                        continue;
                    }
                    $id = $provider === 'gemini' ? preg_replace('/^models\//', '', (string) ($row['name'] ?? '')) : ($row['id'] ?? '');
                    if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:\/-]{0,119}$/', $id)) {
                        continue;
                    }
                    $name = $row['displayName'] ?? $row['name'] ?? $id;
                    $models[$id] = is_string($name) && $name !== $id ? "{$name} ({$id})" : $id;
                }

                $pageToken = $provider === 'gemini' ? $response->json('nextPageToken') : null;
                if ($pageToken !== null && (! is_string($pageToken) || isset($seenTokens[$pageToken]) || count($seenTokens) >= 20)) {
                    throw new RuntimeException('Não foi possível concluir a lista de modelos. Tente atualizar ou informe o modelo manualmente.');
                }
                if (filled($pageToken)) {
                    $seenTokens[$pageToken] = true;
                }
            } while (filled($pageToken));
        } catch (RequestException $exception) {
            // Never expose a provider response or a request containing the company's key.
            throw new RuntimeException(match ($exception->response->status()) {
                401, 403 => 'O provedor recusou a chave de API. Confira a chave e as permissões da conta.',
                402, 429 => 'O provedor limitou a consulta. Confira a cota ou tente novamente mais tarde.',
                default => 'O provedor está indisponível. Tente atualizar ou informe o modelo manualmente.',
            });
        } catch (ConnectionException) {
            throw new RuntimeException('Não foi possível conectar ao provedor. Tente atualizar ou informe o modelo manualmente.');
        }

        natcasesort($models);

        return $models;
    }

    private function supportsText(string $provider, array $model): bool
    {
        return match ($provider) {
            'gemini' => in_array('generateContent', (array) ($model['supportedGenerationMethods'] ?? []), true),
            'openrouter' => in_array('text', (array) data_get($model, 'architecture.output_modalities', []), true),
            'openai' => (bool) preg_match('/^(gpt-|chatgpt-|o\d)/', (string) ($model['id'] ?? ''))
                && ! preg_match('/image|audio|realtime|transcribe|tts|embedding|instruct/i', (string) ($model['id'] ?? '')),
        };
    }
}
