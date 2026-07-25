<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone');
            $table->string('phone_normalized');
            $table->string('email')->nullable();
            $table->string('document')->nullable();
            $table->date('birth_date')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'phone_normalized']);
            $table->index(['company_id', 'email']);
            $table->index(['company_id', 'document']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
