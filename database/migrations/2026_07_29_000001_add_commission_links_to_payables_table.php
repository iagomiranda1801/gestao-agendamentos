<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payables', function (Blueprint $table): void {
            $table->foreignId('attendance_id')
                ->nullable()
                ->after('recurring_expense_template_id')
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('professional_id')
                ->nullable()
                ->after('attendance_id')
                ->constrained()
                ->nullOnDelete();

            $table->unique('attendance_id');
            $table->index('professional_id');
        });
    }

    public function down(): void
    {
        Schema::table('payables', function (Blueprint $table): void {
            $table->dropUnique(['attendance_id']);
            $table->dropIndex(['professional_id']);
            $table->dropForeign(['attendance_id']);
            $table->dropForeign(['professional_id']);
            $table->dropColumn(['attendance_id', 'professional_id']);
        });
    }
};
