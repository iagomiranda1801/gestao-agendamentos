<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->constrained()->cascadeOnDelete();
            $table->decimal('current_balance', 16, 2)->default(0);
            $table->dateTime('last_transaction_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'financial_account_id'], 'fin_acct_bal_co_acct_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_account_balances');
    }
};
