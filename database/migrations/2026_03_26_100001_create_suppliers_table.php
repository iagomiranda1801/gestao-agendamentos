<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('document')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_name')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'document']);
            $table->unique(['company_id', 'document'], 'suppliers_company_document_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
