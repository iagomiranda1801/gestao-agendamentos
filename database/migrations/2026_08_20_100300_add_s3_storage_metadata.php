<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('logo_disk')->default('public')->after('logo_path');
        });

        Schema::table('clinical_attachments', function (Blueprint $table): void {
            $table->timestamp('storage_migrated_at')->nullable()->after('size_bytes');
            $table->string('storage_checksum', 64)->nullable()->after('storage_migrated_at');
            $table->text('storage_migration_error')->nullable()->after('storage_checksum');
        });
    }

    public function down(): void
    {
        Schema::table('clinical_attachments', function (Blueprint $table): void {
            $table->dropColumn(['storage_migrated_at', 'storage_checksum', 'storage_migration_error']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('logo_disk');
        });
    }
};
