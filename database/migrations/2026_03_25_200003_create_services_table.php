<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('color')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_bookable')->default(true);
            $table->boolean('is_online_booking_enabled')->default(true);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'is_bookable']);
            $table->index(['company_id', 'sort_order']);
            $table->unique(['company_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
