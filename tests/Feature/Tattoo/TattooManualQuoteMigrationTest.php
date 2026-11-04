<?php

namespace Tests\Feature\Tattoo;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TattooManualQuoteMigrationTest extends TestCase
{
    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_04_120000_add_tattoo_manual_quote_detection.php');
    }

    public function test_rerunning_migration_preserves_existing_columns_and_detection_data(): void
    {
        $company = $this->createCompany();
        $company->schedulingSetting()->updateOrCreate([], [
            'tattoo_artist_notify_phone' => '5511999998888',
            'tattoo_fixed_deposit_amount' => '100.00',
        ]);
        $detectionId = DB::table('tattoo_manual_quote_detections')->insertGetId([
            'company_id' => $company->id, 'phone_normalized' => '5511888887777',
            'message_text' => 'Orçamento informado', 'total' => '450.00',
        ]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('5511999998888', $company->schedulingSetting->tattoo_artist_notify_phone);
        $this->assertSame('100.00', $company->schedulingSetting->tattoo_fixed_deposit_amount);
        $this->assertDatabaseHas('tattoo_manual_quote_detections', ['id' => $detectionId, 'message_text' => 'Orçamento informado']);
    }

    public function test_partial_migration_adds_only_missing_column(): void
    {
        Schema::table('company_scheduling_settings', fn (Blueprint $table) => $table->dropColumn('tattoo_fixed_deposit_amount'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('company_scheduling_settings', 'tattoo_artist_notify_phone'));
        $this->assertTrue(Schema::hasColumn('company_scheduling_settings', 'tattoo_fixed_deposit_amount'));
        $this->assertTrue(Schema::hasTable('tattoo_manual_quote_detections'));
    }

    public function test_columns_already_present_do_not_prevent_creation_of_missing_table(): void
    {
        Schema::drop('tattoo_manual_quote_detections');

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('tattoo_manual_quote_detections'));
        $this->assertTrue(Schema::hasColumns('tattoo_manual_quote_detections', ['company_id', 'provider_message_id', 'total', 'deposit']));
    }
}
