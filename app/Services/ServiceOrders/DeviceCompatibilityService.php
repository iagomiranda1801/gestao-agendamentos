<?php

namespace App\Services\ServiceOrders;

use App\Models\DeviceCompatibilityLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class DeviceCompatibilityService
{
    public const SOURCE = 'tabela_peliculas';

    public const CATEGORIES = ['film' => 'Películas', 'case' => 'Capas', 'screen' => 'Telas'];

    /** @return list<array{model: string, brand: string, compatible_models: list<string>}> */
    public function search(string $category, string $query): array
    {
        $query = Str::squish($query);
        validator(compact('category', 'query'), [
            'category' => 'required|in:film,case,screen',
            'query' => 'required|string|min:2|max:100',
        ], ['query.min' => 'Digite pelo menos 2 caracteres para pesquisar.'])->validate();

        // This provider only documents film compatibility, never cases or replacement screens.
        if ($category !== 'film') {
            return [];
        }

        $key = hash('sha256', $this->normalize($query));
        if ($cached = $this->cached($category, $key)) {
            return $cached->results;
        }

        return Cache::lock('device-compatibility:'.$key, 20)->block(2, function () use ($category, $query, $key): array {
            if ($cached = $this->cached($category, $key)) {
                return $cached->results;
            }

            $response = Http::acceptJson()->connectTimeout(5)->timeout(12)
                ->get(config('services.tabela_peliculas.url'), ['route' => 'api/sistemas', 'modelo' => $query])
                ->throw();
            $payload = $response->json();

            if (! is_array($payload) || ($payload['success'] ?? null) !== true
                || ! is_array($payload['data'] ?? null) || ! array_is_list($payload['data'])) {
                throw new RuntimeException('Invalid Tabela Películas response.');
            }

            $results = $this->normalizeResults($payload['data']);
            DeviceCompatibilityLookup::updateOrCreate(
                ['category' => $category, 'source' => self::SOURCE, 'query_key' => $key],
                ['query' => $query, 'results' => $results, 'fetched_at' => now()],
            );

            return $results;
        });
    }

    private function cached(string $category, string $key): ?DeviceCompatibilityLookup
    {
        $lookup = DeviceCompatibilityLookup::where('category', $category)
            ->where('source', self::SOURCE)->where('query_key', $key)->first();

        // Successful results stay local. Empty searches can discover newly added models after a day.
        return $lookup && ($lookup->results !== [] || $lookup->fetched_at->gt(now()->subDay())) ? $lookup : null;
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::squish(Str::ascii($value)));
    }

    private function normalizeResults(array $rows): array
    {
        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['modelo'] ?? null)
                || ! is_string($row['marca'] ?? null) || ! is_array($row['compativeis'] ?? null)
                || ! array_is_list($row['compativeis'])) {
                throw new RuntimeException('Invalid Tabela Películas model.');
            }

            $model = Str::squish($row['modelo']);
            if ($model === '' || mb_strlen($model) > 150) {
                throw new RuntimeException('Invalid Tabela Películas model name.');
            }

            $modelKey = $this->normalize($model);
            $compatible = $results[$modelKey]['compatible_models'] ?? [];
            foreach ($row['compativeis'] as $name) {
                if (! is_string($name)) {
                    throw new RuntimeException('Invalid Tabela Películas compatibility.');
                }
                $name = Str::squish($name);
                if (mb_strlen($name) >= 2 && mb_strlen($name) <= 150 && $this->normalize($name) !== $modelKey) {
                    $compatible[$this->normalize($name)] ??= $name;
                }
            }
            // Preserve explicit variants and direction; never infer transitive compatibility or brands.
            $results[$modelKey] = ['model' => $model, 'brand' => Str::squish($row['marca']), 'compatible_models' => $compatible];
        }

        return array_values(array_map(fn (array $result): array => [
            ...$result, 'compatible_models' => array_values($result['compatible_models']),
        ], $results));
    }
}
