<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type');
            $table->text('description')->nullable();
            $table->boolean('affects_managerial_result')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'is_active']);
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'code'], 'expense_categories_company_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
