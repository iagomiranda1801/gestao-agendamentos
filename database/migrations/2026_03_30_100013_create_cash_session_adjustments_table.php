<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_session_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 16, 2);
            $table->string('reason');
            $table->foreignId('financial_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'cash_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_session_adjustments');
    }
};
