<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('financial_account_id')
                ->nullable()
                ->after('attendance_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['company_id', 'financial_account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['financial_account_id']);
            $table->dropIndex(['company_id', 'financial_account_id']);
            $table->dropColumn('financial_account_id');
        });
    }
};
