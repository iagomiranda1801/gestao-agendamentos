<?php

namespace App\Filament\App\Concerns;

use App\Models\Product;

trait FillsProductVariantForm
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillProductVariants(array $data): array
    {
        /** @var Product $record */
        $record = $this->getRecord();

        $data['variants'] = $record->variants
            ->map(fn ($variant): array => [
                'id' => $variant->id,
                'name' => $variant->name,
                'price' => $variant->price,
                'is_default' => $variant->is_default,
                'is_active' => $variant->is_active,
            ])
            ->all();

        return $data;
    }
}
