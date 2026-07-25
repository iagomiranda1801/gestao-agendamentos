<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expense_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('default_financial_account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();
            $table->string('description');
            $table->string('frequency');
            $table->decimal('amount', 16, 2);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->date('next_generation_date')->nullable();
            $table->unsignedSmallInteger('generate_days_in_advance')->default(10);
            $table->boolean('auto_generate')->default(true);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'next_generation_date'], 'rec_exp_tpl_co_next_gen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_expense_templates');
    }
};
