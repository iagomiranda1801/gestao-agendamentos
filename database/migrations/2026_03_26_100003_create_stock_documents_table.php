<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('status')->default('draft');
            $table->string('document_number')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('reference_key')->nullable();
            $table->dateTime('occurred_at');
            $table->text('notes')->nullable();
            $table->decimal('total_amount', 16, 6)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('stock_documents')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'occurred_at']);
            $table->index('supplier_id');
            $table->index('reversal_of_id');
            $table->unique(['company_id', 'reference_key'], 'stock_docs_company_ref_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_documents');
    }
};
