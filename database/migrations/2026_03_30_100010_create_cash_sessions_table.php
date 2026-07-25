<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            $table->dateTime('opened_at');
            $table->decimal('expected_opening_amount', 16, 2);
            $table->decimal('counted_opening_amount', 16, 2);
            $table->decimal('opening_difference_amount', 16, 2);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->decimal('expected_closing_amount', 16, 2)->nullable();
            $table->decimal('counted_closing_amount', 16, 2)->nullable();
            $table->decimal('closing_difference_amount', 16, 2)->nullable();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
