<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('channel')->default('online')->after('fulfillment');
            $table->string('payment_method')->nullable()->after('channel');
            $table->foreignId('opened_by_user_id')->nullable()->after('table_id')->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'table_id', 'status']);
        });

        DB::table('orders')->whereNotNull('table_id')->update(['table_id' => null]);

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreign('table_id')->references('id')->on('dining_tables')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_phone')->nullable(false)->change();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['table_id']);
            $table->dropConstrainedForeignId('opened_by_user_id');
            $table->dropIndex(['company_id', 'table_id', 'status']);
            $table->dropColumn(['channel', 'payment_method']);
        });
    }
};
