<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->string('status');
            $table->string('billing_interval');
            $table->unsignedInteger('amount_cents');
            $table->json('items');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('number');
            $table->index(['company_id', 'status']);
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoices');
    }
};
