<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payable_installment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->string('status')->default('confirmed');
            $table->decimal('settled_principal_amount', 16, 2);
            $table->decimal('interest_amount', 16, 2)->default(0);
            $table->decimal('penalty_amount', 16, 2)->default(0);
            $table->decimal('fee_amount', 16, 2)->default(0);
            $table->decimal('discount_amount', 16, 2)->default(0);
            $table->decimal('cash_outflow_amount', 16, 2);
            $table->dateTime('paid_at');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('payable_id');
            $table->index('payable_installment_id');
            $table->index('financial_account_id');
            $table->index(['company_id', 'status']);
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payable_payments');
    }
};
