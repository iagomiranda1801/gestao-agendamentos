<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->decimal('custom_price', 12, 2)->nullable();
            $table->unsignedSmallInteger('custom_duration_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('company_id');
            $table->index('professional_id');
            $table->index('service_id');
            $table->unique(['company_id', 'professional_id', 'service_id'], 'prof_svc_company_prof_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_service');
    }
};
