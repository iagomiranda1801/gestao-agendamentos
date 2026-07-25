<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recurring_expense_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin');
            $table->string('status')->default('draft');
            $table->string('description');
            $table->string('document_number')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('reference_key')->nullable();
            $table->date('issue_date');
            $table->date('competence_date');
            $table->decimal('total_amount', 16, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'competence_date']);
            $table->index('supplier_id');
            $table->index('expense_category_id');
            $table->unique(['company_id', 'reference_key'], 'payables_company_ref_key_unique');
            $table->unique('stock_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payables');
    }
};
