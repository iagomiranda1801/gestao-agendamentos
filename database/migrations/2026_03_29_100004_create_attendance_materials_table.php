<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_product_consumption_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name_snapshot');
            $table->decimal('planned_quantity', 14, 4);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost_snapshot', 14, 6);
            $table->decimal('total_cost', 14, 6);
            $table->boolean('tracks_stock_snapshot')->default(true);
            $table->foreignId('stock_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_document_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('attendance_id');
            $table->index('product_id');
            $table->index('stock_movement_id');
            $table->unique(['attendance_id', 'product_id'], 'att_materials_att_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_materials');
    }
};
