<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->decimal('original_amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('outstanding_amount', 12, 2);
            $table->string('status')->default('open');
            $table->date('due_date')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('attendance_id');
            $table->index('company_id');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'client_id']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');
    }
};
