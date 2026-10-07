<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tattoo_payment_receipts', function (Blueprint $table): void {
            $table->string('transaction_id', 191)->nullable();
            $table->index('transaction_id');
        });

        // Preenche o identificador normalizado dos comprovantes já analisados.
        DB::table('tattoo_payment_receipts')->whereNotNull('analysis')->orderBy('id')
            ->chunkById(200, function ($receipts): void {
                foreach ($receipts as $receipt) {
                    $analysis = json_decode((string) $receipt->analysis, true);
                    $id = is_array($analysis) && is_string($analysis['transaction_id'] ?? null)
                        ? mb_strtoupper((string) preg_replace('/\s+/u', '', $analysis['transaction_id'])) : '';
                    if ($id !== '') {
                        DB::table('tattoo_payment_receipts')->where('id', $receipt->id)
                            ->update(['transaction_id' => mb_substr($id, 0, 191)]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('tattoo_payment_receipts', function (Blueprint $table): void {
            $table->dropIndex(['transaction_id']);
            $table->dropColumn('transaction_id');
        });
    }
};
