<?php

namespace App\Services\Nfe;

use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProductMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sugere, para cada item da NF-e, qual produto do estoque ele é.
 * Ordem: mapeamento salvo do fornecedor → código de barras → nome parecido.
 *
 * O mapeamento vem primeiro porque guarda a decisão explícita do usuário,
 * inclusive o fator de conversão (ex.: 1 CX = 12 UN).
 */
class NfeProductMatcher
{
    public const SOURCE_MAPPING = 'mapping';

    public const SOURCE_BARCODE = 'barcode';

    public const SOURCE_NAME = 'name';

    public const SOURCE_NONE = 'none';

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<int, array{source: string, product_id: ?int, factor: string, score: ?int}>
     */
    public function matchAll(Company $company, ?Supplier $supplier, array $items): array
    {
        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->tracksStock()
            ->get(['id', 'name', 'barcode']);

        $byBarcode = [];

        foreach ($products as $product) {
            $barcode = $this->barcodeKey($product->barcode);

            if ($barcode !== null) {
                $byBarcode[$barcode] ??= $product;
            }
        }

        $mappings = $supplier === null
            ? collect()
            : SupplierProductMapping::query()
                ->where('company_id', $company->getKey())
                ->where('supplier_id', $supplier->getKey())
                ->whereIn('supplier_code', array_map(fn (array $item): string => (string) $item['code'], $items))
                ->get()
                ->keyBy('supplier_code');

        $normalizedNames = $products->mapWithKeys(
            fn (Product $product): array => [$product->getKey() => $this->normalizeName((string) $product->name)],
        );

        $results = [];

        foreach ($items as $item) {
            $results[(int) $item['index']] = $this->matchItem($item, $products, $byBarcode, $mappings, $normalizedNames);
        }

        return $results;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<string, Product>  $byBarcode
     * @param  Collection<string, SupplierProductMapping>  $mappings
     * @param  Collection<int, string>  $normalizedNames
     * @param  array<string, mixed>  $item
     * @return array{source: string, product_id: ?int, factor: string, score: ?int}
     */
    protected function matchItem(array $item, Collection $products, array $byBarcode, Collection $mappings, Collection $normalizedNames): array
    {
        $mapping = $mappings->get((string) $item['code']);

        if ($mapping !== null && $products->contains('id', $mapping->product_id)) {
            return $this->result(self::SOURCE_MAPPING, (int) $mapping->product_id, $this->factor((string) $mapping->conversion_factor));
        }

        $commercial = $this->barcodeKey($item['ean'] ?? null);
        $tax = $this->barcodeKey($item['ean_tax'] ?? null);

        if ($commercial !== null && isset($byBarcode[$commercial])) {
            return $this->result(self::SOURCE_BARCODE, (int) $byBarcode[$commercial]->getKey());
        }

        if ($tax !== null && isset($byBarcode[$tax])) {
            // O código do produto é o da unidade tributável: se a nota vende em
            // caixa e a unidade tributável tem N itens, o fator já vem da nota.
            return $this->result(
                self::SOURCE_BARCODE,
                (int) $byBarcode[$tax]->getKey(),
                $this->taxFactor($item),
            );
        }

        $normalized = $this->normalizeName((string) $item['name']);
        $best = null;
        $bestScore = 0;

        foreach ($products as $product) {
            $score = $this->similarity($normalized, (string) $normalizedNames->get($product->getKey()));

            if ($score > $bestScore) {
                $best = $product;
                $bestScore = $score;
            }
        }

        if ($best !== null && $bestScore >= 80) {
            return $this->result(self::SOURCE_NAME, (int) $best->getKey(), '1', $bestScore);
        }

        return $this->result(self::SOURCE_NONE, null);
    }

    /**
     * @return array{source: string, product_id: ?int, factor: string, score: ?int}
     */
    protected function result(string $source, ?int $productId, string $factor = '1', ?int $score = null): array
    {
        return [
            'source' => $source,
            'product_id' => $productId,
            'factor' => $factor,
            'score' => $score,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function taxFactor(array $item): string
    {
        $commercial = (string) ($item['quantity'] ?? '0');
        $tax = $item['quantity_tax'] ?? null;

        if ($tax === null || bccomp($commercial, '0', 4) <= 0 || bccomp((string) $tax, '0', 4) <= 0) {
            return '1';
        }

        if (($item['unit'] ?? null) === ($item['unit_tax'] ?? null)) {
            return '1';
        }

        return $this->factor(bcdiv((string) $tax, $commercial, 4));
    }

    protected function factor(string $value): string
    {
        $value = bcadd($value, '0', 4);

        if (bccomp($value, '0', 4) <= 0) {
            return '1';
        }

        return rtrim(rtrim($value, '0'), '.') ?: '1';
    }

    /** Normaliza GTIN/EAN: só dígitos, sem zeros à esquerda; "SEM GTIN" vira null. */
    public function barcodeKey(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === null || $digits === '' || trim($digits, '0') === '') {
            return null;
        }

        return ltrim($digits, '0');
    }

    public function normalizeName(string $name): string
    {
        $name = Str::lower(Str::ascii($name));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';

        return trim($name);
    }

    public function similarity(string $a, string $b): int
    {
        if ($a === '' || $b === '') {
            return 0;
        }

        if ($a === $b) {
            return 100;
        }

        similar_text($a, $b, $percent);

        $tokensA = array_unique(explode(' ', $a));
        $tokensB = array_unique(explode(' ', $b));
        $union = count(array_unique([...$tokensA, ...$tokensB]));
        $jaccard = $union === 0 ? 0 : (count(array_intersect($tokensA, $tokensB)) / $union) * 100;

        // similar_text sozinho aprova "cerveja pilsen" ~ "cerveja skol"; exigimos
        // alta semelhança de texto OU maioria das palavras iguais.
        $textScore = $percent >= 90 ? $percent : 0;
        $tokenScore = $jaccard >= 67 ? $jaccard : 0;

        return (int) round(max($textScore, $tokenScore));
    }
}
