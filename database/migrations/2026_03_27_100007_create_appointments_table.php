<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('professional_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->string('origin')->default('internal');
            $table->string('reference_key')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('service_name_snapshot');
            $table->decimal('price_snapshot', 12, 2);
            $table->unsignedSmallInteger('duration_minutes_snapshot');
            $table->unsignedSmallInteger('buffer_before_minutes_snapshot')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes_snapshot')->default(0);
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('no_show_at')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'start_at']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'professional_id', 'start_at'], 'appt_co_prof_start_idx');
            $table->index(['company_id', 'client_id', 'start_at'], 'appt_co_client_start_idx');
            $table->index(['company_id', 'service_id', 'start_at'], 'appt_co_service_start_idx');
            $table->unique(['company_id', 'reference_key'], 'appt_co_ref_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
