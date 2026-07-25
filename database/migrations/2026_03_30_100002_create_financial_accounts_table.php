<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('bank_name')->nullable();
            $table->string('branch')->nullable();
            $table->string('account_number')->nullable();
            $table->string('pix_key')->nullable();
            $table->text('description')->nullable();
            $table->boolean('allow_negative_balance')->default(false);
            $table->boolean('is_default_receipt_account')->default(false);
            $table->boolean('is_default_expense_account')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'is_active']);
            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
