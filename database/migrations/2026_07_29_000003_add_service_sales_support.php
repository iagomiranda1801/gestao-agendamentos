<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('services', 'is_sellable')) {
            Schema::table('services', function (Blueprint $table): void {
                $table->boolean('is_sellable')->default(true)->after('is_bookable');
            });
        }

        Schema::table('services', function (Blueprint $table): void {
            if (! $this->indexExists('services', 'services_company_id_is_sellable_index')) {
                $table->index(['company_id', 'is_sellable']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if ($this->indexExists('services', 'services_company_id_is_sellable_index')) {
                $table->dropIndex('services_company_id_is_sellable_index');
            }

            if (Schema::hasColumn('services', 'is_sellable')) {
                $table->dropColumn('is_sellable');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn (object $row): bool => ($row->name ?? null) === $index);
        }

        return collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]))->isNotEmpty();
    }
};
