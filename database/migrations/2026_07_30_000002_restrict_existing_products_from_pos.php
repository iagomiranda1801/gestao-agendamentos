<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'is_sellable')) {
            return;
        }

        DB::table('products')
            ->whereNotIn('id', function ($query): void {
                $query
                    ->select('product_id')
                    ->from('sale_items')
                    ->whereNotNull('product_id');
            })
            ->update(['is_sellable' => false]);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `products` MODIFY `is_sellable` TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'is_sellable')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `products` MODIFY `is_sellable` TINYINT(1) NOT NULL DEFAULT 1');
        }
    }
};
