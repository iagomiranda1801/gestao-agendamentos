<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_asaas_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('environment')->default('sandbox');
            $table->text('api_key')->nullable();
            $table->text('webhook_token');
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->unique('company_id');
        });

        Schema::create('asaas_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('asaas_id');
            $table->timestamps();

            $table->unique(['company_id', 'client_id']);
        });

        Schema::create('asaas_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('receivable_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tattoo_quote_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asaas_customer_id');
            $table->string('asaas_payment_id')->nullable();
            $table->string('billing_type');
            $table->decimal('value', 12, 2);
            $table->decimal('net_value', 12, 2)->nullable();
            $table->string('status')->default('pending');
            $table->text('invoice_url')->nullable();
            $table->text('bank_slip_url')->nullable();
            $table->text('pix_payload')->nullable();
            $table->date('due_date');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique('asaas_payment_id');
            $table->index(['company_id', 'receivable_id']);
            $table->index(['company_id', 'tattoo_quote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asaas_charges');
        Schema::dropIfExists('asaas_customers');
        Schema::dropIfExists('company_asaas_settings');
    }
};
