<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('flow_kind')->default('normal')->after('origin');
            $table->text('fit_in_reason')->nullable()->after('internal_notes');
            $table->foreignId('fit_in_authorized_by')->nullable()->after('started_by')->constrained('users')->nullOnDelete();
            $table->dateTime('fit_in_authorized_at')->nullable()->after('started_at');
            $table->index('flow_kind');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fit_in_authorized_by');
            $table->dropIndex(['flow_kind']);
            $table->dropColumn(['flow_kind', 'fit_in_reason', 'fit_in_authorized_at']);
        });
    }
};
