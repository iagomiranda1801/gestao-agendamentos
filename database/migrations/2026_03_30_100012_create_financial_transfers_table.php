<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_financial_account_id')->constrained('financial_accounts')->cascadeOnDelete();
            $table->foreignId('to_financial_account_id')->constrained('financial_accounts')->cascadeOnDelete();
            $table->decimal('amount', 16, 2);
            $table->decimal('fee_amount', 16, 2)->default(0);
            $table->dateTime('occurred_at');
            $table->string('description');
            $table->string('reference_key')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference_key'], 'fin_transfer_co_ref_key_unique');
            $table->index(['company_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transfers');
    }
};
