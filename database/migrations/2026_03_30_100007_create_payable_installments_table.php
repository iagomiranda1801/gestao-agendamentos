<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payable_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payable_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->date('due_date');
            $table->decimal('original_amount', 16, 2);
            $table->decimal('settled_principal_amount', 16, 2)->default(0);
            $table->decimal('outstanding_amount', 16, 2);
            $table->string('status')->default('open');
            $table->dateTime('settled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['payable_id', 'installment_number']);
            $table->index('company_id');
            $table->index(['company_id', 'due_date']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payable_installments');
    }
};
