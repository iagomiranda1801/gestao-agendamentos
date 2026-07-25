<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->foreign('cash_session_id')
                ->references('id')
                ->on('cash_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropForeign(['cash_session_id']);
        });
    }
};
