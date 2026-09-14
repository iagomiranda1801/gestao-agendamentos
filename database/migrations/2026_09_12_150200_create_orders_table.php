<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('public_code', 16);
            $table->string('idempotency_key', 64)->nullable();
            $table->string('status');
            $table->string('fulfillment');
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_phone_normalized')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('delivery_complement')->nullable();
            $table->string('delivery_neighborhood')->nullable();
            $table->string('delivery_city')->nullable();
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'public_code']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
