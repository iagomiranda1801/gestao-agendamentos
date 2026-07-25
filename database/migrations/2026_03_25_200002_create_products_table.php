<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('measurement_unit_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('type');
            $table->text('description')->nullable();
            $table->decimal('reference_unit_cost', 14, 6)->default(0);
            $table->decimal('minimum_stock', 14, 4)->default(0);
            $table->boolean('tracks_stock')->default(true);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'name']);
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
