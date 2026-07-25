<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4)->nullable();
            $table->decimal('unit_cost', 14, 6)->nullable();
            $table->decimal('expected_quantity', 14, 4)->nullable();
            $table->decimal('counted_quantity', 14, 4)->nullable();
            $table->decimal('quantity_delta', 14, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('stock_document_id');
            $table->index('product_id');
            $table->unique(['stock_document_id', 'product_id'], 'stock_doc_items_doc_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_document_items');
    }
};
