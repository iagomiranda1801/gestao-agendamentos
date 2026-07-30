<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'type')) {
            return;
        }

        if (Schema::hasColumn('products', 'is_sellable')) {
            DB::table('products')
                ->where('is_sellable', true)
                ->update(['type' => 'sale']);

            DB::table('products')
                ->where('type', 'sale')
                ->update(['is_sellable' => true]);

            DB::table('products')
                ->where('type', '!=', 'sale')
                ->update(['is_sellable' => false]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'type')) {
            return;
        }

        DB::table('products')
            ->where('type', 'sale')
            ->update(['type' => 'consumable']);
    }
};
