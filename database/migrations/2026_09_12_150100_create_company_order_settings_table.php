<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_order_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->boolean('online_ordering_enabled')->default(false);
            $table->boolean('pickup_enabled')->default(true);
            $table->boolean('delivery_enabled')->default(true);
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            $table->unsignedInteger('min_order_cents')->default(0);
            $table->text('delivery_radius_note')->nullable();
            $table->boolean('orders_whatsapp_notify')->default(true);
            $table->string('page_title')->nullable();
            $table->text('page_description')->nullable();
            $table->text('confirmation_message')->nullable();
            $table->string('primary_color', 32)->nullable();
            $table->timestamps();

            $table->unique('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_order_settings');
    }
};
