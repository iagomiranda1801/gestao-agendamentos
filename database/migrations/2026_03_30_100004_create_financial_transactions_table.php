<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->string('type');
            $table->decimal('amount', 16, 2);
            $table->dateTime('occurred_at');
            $table->string('description');
            $table->string('reference_key')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('cash_session_id')->nullable();
            $table->foreignId('original_transaction_id')->nullable()->constrained('financial_transactions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('company_id');
            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'financial_account_id', 'occurred_at'], 'fin_tx_co_acct_occ_idx');
            $table->index(['source_type', 'source_id']);
            $table->index('original_transaction_id');
            $table->unique(['company_id', 'reference_key'], 'fin_tx_co_ref_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
