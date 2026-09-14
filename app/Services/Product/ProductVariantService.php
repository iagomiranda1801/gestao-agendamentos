<?php

namespace App\Services\Product;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductVariantService
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function sync(Product $product, array $rows): void
    {
        DB::transaction(function () use ($product, $rows): void {
            $keepIds = [];
            $seenNames = [];
            $defaultAssigned = false;

            foreach (array_values($rows) as $index => $row) {
                $name = trim((string) ($row['name'] ?? ''));

                if ($name === '') {
                    throw ValidationException::withMessages([
                        'variants' => 'Informe o nome de cada tamanho.',
                    ]);
                }

                $key = mb_strtolower($name);

                if (isset($seenNames[$key])) {
                    throw ValidationException::withMessages([
                        'variants' => 'Tamanhos não podem ter o mesmo nome.',
                    ]);
                }

                $seenNames[$key] = true;

                $price = $row['price'] ?? null;

                if ($price === null || $price === '' || bccomp((string) $price, '0', 2) <= 0) {
                    throw ValidationException::withMessages([
                        'variants' => 'Informe um preço maior que zero para cada tamanho.',
                    ]);
                }

                $wantsDefault = (bool) ($row['is_default'] ?? false);
                $isDefault = $wantsDefault && ! $defaultAssigned;

                if ($isDefault) {
                    $defaultAssigned = true;
                }

                $payload = [
                    'company_id' => $product->company_id,
                    'name' => mb_substr($name, 0, 40),
                    'price' => $price,
                    'sort_order' => ($index + 1) * 10,
                    'is_default' => $isDefault,
                    'is_active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
                ];

                $id = isset($row['id']) ? (int) $row['id'] : 0;
                $variant = null;

                if ($id > 0) {
                    $variant = ProductVariant::query()
                        ->where('product_id', $product->getKey())
                        ->whereKey($id)
                        ->first();
                }

                if ($variant !== null) {
                    $variant->fill($payload);
                    $variant->save();
                } else {
                    $variant = new ProductVariant($payload);
                    $variant->product()->associate($product);
                    $variant->company()->associate($product->company);
                    $variant->save();
                }

                $keepIds[] = (int) $variant->getKey();
            }

            ProductVariant::query()
                ->where('product_id', $product->getKey())
                ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
                ->delete();

            if ($keepIds !== [] && ! $defaultAssigned) {
                ProductVariant::query()->whereKey($keepIds[0])->update(['is_default' => true]);
            }
        });
    }
}
