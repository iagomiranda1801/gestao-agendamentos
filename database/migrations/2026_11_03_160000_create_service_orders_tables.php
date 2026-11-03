<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('device');
            $table->text('problem');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('imei')->nullable();
            $table->text('condition')->nullable();
            $table->text('accessories')->nullable();
            $table->text('notes')->nullable();
            $table->text('warranty')->nullable();
            $table->string('status')->default('analysis');
            $table->foreignId('sale_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('document_token', 64)->unique();
            $table->string('receipt_token', 64)->unique();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });
        Schema::create('service_order_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('items');
            $table->decimal('total', 14, 2);
            $table->string('deadline');
            $table->date('valid_until');
            $table->text('conditions')->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('published_at')->nullable();
            $table->string('decision')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_source')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['service_order_id', 'version']);
        });
        Schema::create('service_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('description');
            $table->timestamps();
        });
        Schema::create('service_order_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('service_order_quotes');
            $table->string('kind');
            $table->string('recipient');
            $table->text('body');
            $table->string('status')->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('provider_reference')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::create('service_order_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->restrictOnDelete();
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['service_order_photos', 'service_order_messages', 'service_order_events', 'service_order_quotes', 'service_orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
