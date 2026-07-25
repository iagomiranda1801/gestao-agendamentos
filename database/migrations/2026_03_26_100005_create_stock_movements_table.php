<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_document_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction');
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 6);
            $table->decimal('total_cost', 16, 6);
            $table->decimal('quantity_before', 14, 4);
            $table->decimal('quantity_after', 14, 4);
            $table->decimal('average_cost_before', 14, 6);
            $table->decimal('average_cost_after', 14, 6);
            $table->dateTime('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('original_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'product_id', 'occurred_at'], 'stock_mov_company_prod_date_idx');
            $table->index('stock_document_id');
            $table->index('original_movement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
