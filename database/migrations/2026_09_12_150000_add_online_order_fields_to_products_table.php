<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('available_for_online_order')->default(false)->after('is_sellable');
            $table->string('online_order_category')->nullable()->after('available_for_online_order');
            $table->unsignedSmallInteger('prep_time_minutes')->nullable()->after('online_order_category');

            $table->index(['company_id', 'available_for_online_order']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'available_for_online_order']);
            $table->dropColumn([
                'available_for_online_order',
                'online_order_category',
                'prep_time_minutes',
            ]);
        });
    }
};
