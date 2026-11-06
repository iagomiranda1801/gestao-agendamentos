<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_asaas_settings', function (Blueprint $table): void {
            $table->text('fiscal_sandbox_api_key')->nullable();
            $table->text('base_sandbox_api_key')->nullable();
        });

        Schema::create('sale_fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('type', 8);
            $table->string('environment', 16);
            $table->string('status', 24);
            $table->decimal('amount', 14, 2);
            $table->uuid('reference')->unique();
            $table->string('external_order_id')->nullable();
            $table->string('external_invoice_id')->nullable();
            $table->string('number')->nullable();
            $table->text('error')->nullable();
            $table->text('pdf_url')->nullable();
            $table->text('xml_url')->nullable();
            $table->timestamps();
            $table->unique(['sale_id', 'type', 'environment']);
            $table->index(['company_id', 'type', 'external_order_id'], 'sale_fiscal_provider_order');
            $table->index(['company_id', 'type', 'external_invoice_id'], 'sale_fiscal_provider_invoice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_fiscal_documents');
        Schema::table('company_asaas_settings', function (Blueprint $table): void {
            $table->dropColumn(['fiscal_sandbox_api_key', 'base_sandbox_api_key']);
        });
    }
};
