<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_on_hand', 14, 4)->default(0);
            $table->decimal('average_unit_cost', 14, 6)->default(0);
            $table->dateTime('last_movement_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'product_id'], 'inv_bal_company_product_unique');
            $table->index(['company_id', 'quantity_on_hand'], 'inv_bal_company_qty_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
    }
};
