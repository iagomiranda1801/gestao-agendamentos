<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_financial_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('default_commission_type')->default('percentage');
            $table->decimal('default_commission_value', 8, 4)->default(0);
            $table->decimal('materials_reserve_percentage', 8, 4)->default(0);
            $table->decimal('business_reserve_percentage', 8, 4)->default(0);
            $table->boolean('allow_partial_payments')->default(true);
            $table->boolean('allow_unpaid_completion')->default(true);
            $table->unsignedSmallInteger('default_payment_due_days')->default(0);
            $table->timestamps();

            $table->unique('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_financial_settings');
    }
};
