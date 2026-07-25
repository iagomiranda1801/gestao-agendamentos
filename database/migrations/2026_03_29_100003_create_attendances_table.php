<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('professional_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('service_name_snapshot');
            $table->string('client_name_snapshot');
            $table->string('professional_name_snapshot');
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('final_amount', 12, 2);
            $table->string('commission_type_snapshot');
            $table->decimal('commission_value_snapshot', 8, 4);
            $table->decimal('commission_amount', 12, 2);
            $table->decimal('materials_reserve_percentage_snapshot', 8, 4);
            $table->decimal('materials_reserve_amount', 12, 2);
            $table->decimal('business_reserve_percentage_snapshot', 8, 4);
            $table->decimal('business_reserve_amount', 12, 2);
            $table->decimal('owner_allocation_percentage_snapshot', 8, 4);
            $table->decimal('owner_allocation_amount', 12, 2);
            $table->decimal('actual_material_cost', 12, 2)->default(0);
            $table->decimal('payment_fee_amount', 12, 2)->default(0);
            $table->decimal('operational_result', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at');
            $table->timestamps();

            $table->unique('appointment_id');
            $table->index('company_id');
            $table->index(['company_id', 'completed_at']);
            $table->index(['company_id', 'client_id', 'completed_at'], 'att_co_client_completed_idx');
            $table->index(['company_id', 'professional_id', 'completed_at'], 'att_co_prof_completed_idx');
            $table->index(['company_id', 'service_id', 'completed_at'], 'att_co_service_completed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
