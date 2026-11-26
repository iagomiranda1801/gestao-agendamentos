<?php

namespace App\Services\Nfe;

use App\Models\Payable;
use App\Models\StockDocument;
use App\Models\Supplier;

final class NfeImportResult
{
    public function __construct(
        public readonly StockDocument $document,
        public readonly ?Supplier $supplier,
        public readonly ?Payable $payable,
        public readonly int $createdProducts,
        public readonly int $mappingsSaved,
        public readonly int $ignoredItems,
    ) {}
}
