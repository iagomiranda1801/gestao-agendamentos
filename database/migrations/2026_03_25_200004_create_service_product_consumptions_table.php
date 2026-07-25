<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_product_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('service_id');
            $table->index('product_id');
            $table->unique(['service_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_product_consumptions');
    }
};
