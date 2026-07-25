<?php

namespace Tests\Feature\Financial;

use App\Enums\AttendanceHistoryType;
use App\Enums\CommissionType;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\AttendanceHistory;
use App\Models\AttendanceMaterial;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class AttendanceStructureTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_attendance_tables_exist_after_migration(): void
    {
        $this->assertTrue(
            Schema::hasTable('attendances')
            && Schema::hasTable('attendance_materials')
            && Schema::hasTable('attendance_histories'),
        );
    }

    public function test_attendance_can_be_created_with_relationships_and_casts(): void
    {
        $setup = $this->createBookableSetup();
        $appointment = Appointment::factory()
            ->forCompany($setup['company'])
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
            ]);

        $attendance = Attendance::factory()->forAppointment($appointment)->create([
            'client_name_snapshot' => $setup['client']->name,
            'professional_name_snapshot' => $setup['professional']->name,
            'gross_amount' => '150.00',
            'discount_amount' => '10.00',
            'final_amount' => '140.00',
            'commission_type_snapshot' => CommissionType::Percentage,
            'commission_value_snapshot' => '15.0000',
            'commission_amount' => '21.00',
            'materials_reserve_percentage_snapshot' => '10.0000',
            'materials_reserve_amount' => '14.00',
            'business_reserve_percentage_snapshot' => '10.0000',
            'business_reserve_amount' => '14.00',
            'owner_allocation_percentage_snapshot' => '65.0000',
            'owner_allocation_amount' => '91.00',
            'actual_material_cost' => '5.00',
            'payment_fee_amount' => '0.00',
            'operational_result' => '86.00',
            'completed_by' => $setup['admin']->getKey(),
        ]);

        $attendance->refresh();

        $this->assertSame(CommissionType::Percentage, $attendance->commission_type_snapshot);
        $this->assertSame('140.00', (string) $attendance->final_amount);
        $this->assertSame('15.0000', (string) $attendance->commission_value_snapshot);
        $this->assertTrue($attendance->company->is($setup['company']));
        $this->assertTrue($attendance->appointment->is($appointment));
        $this->assertTrue($appointment->fresh()->attendance->is($attendance));
        $this->assertTrue($setup['client']->fresh()->attendances->contains($attendance));
    }

    public function test_appointment_id_must_be_unique_per_attendance(): void
    {
        $setup = $this->createBookableSetup();
        $appointment = Appointment::factory()
            ->forCompany($setup['company'])
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
            ]);

        Attendance::factory()->forAppointment($appointment)->create();

        $this->expectException(QueryException::class);

        Attendance::factory()->forAppointment($appointment)->create();
    }

    public function test_attendance_material_and_history_models_persist(): void
    {
        $setup = $this->createBookableSetup();
        $appointment = Appointment::factory()
            ->forCompany($setup['company'])
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
            ]);

        $attendance = Attendance::factory()->forAppointment($appointment)->create([
            'client_name_snapshot' => $setup['client']->name,
            'professional_name_snapshot' => $setup['professional']->name,
        ]);

        $product = Product::factory()->forCompany($setup['company'])->create();

        $material = AttendanceMaterial::factory()->forAttendance($attendance)->create([
            'product_id' => $product->getKey(),
            'product_name_snapshot' => $product->name,
            'planned_quantity' => '2.0000',
            'quantity' => '2.0000',
            'unit_cost_snapshot' => '3.500000',
            'total_cost' => '7.000000',
            'tracks_stock_snapshot' => true,
        ]);

        $history = AttendanceHistory::factory()->forAttendance($attendance)->create([
            'user_id' => $setup['admin']->getKey(),
            'type' => AttendanceHistoryType::Completed,
            'description' => 'Atendimento concluído.',
            'metadata' => ['final_amount' => '140.00'],
        ]);

        $attendance->load(['materials', 'histories']);

        $this->assertCount(1, $attendance->materials);
        $this->assertCount(1, $attendance->histories);
        $this->assertSame('2.0000', (string) $material->quantity);
        $this->assertSame('7.000000', (string) $material->total_cost);
        $this->assertSame(AttendanceHistoryType::Completed, $history->type);
        $this->assertSame(['final_amount' => '140.00'], $history->metadata);
        $this->assertNull($history->updated_at);
    }

    public function test_company_id_is_guarded_on_attendance_mass_assignment(): void
    {
        $setup = $this->createBookableSetup();
        $otherCompany = $this->createSchedulingCompany();

        $attendance = new Attendance;
        $attendance->fill([
            'company_id' => $otherCompany->getKey(),
            'appointment_id' => 1,
            'client_id' => $setup['client']->getKey(),
            'professional_id' => $setup['professional']->getKey(),
            'service_id' => $setup['service']->getKey(),
            'service_name_snapshot' => 'Serviço',
            'client_name_snapshot' => 'Cliente',
            'professional_name_snapshot' => 'Profissional',
            'gross_amount' => '100.00',
            'discount_amount' => '0.00',
            'final_amount' => '100.00',
            'commission_type_snapshot' => CommissionType::None->value,
            'commission_value_snapshot' => '0.0000',
            'commission_amount' => '0.00',
            'materials_reserve_percentage_snapshot' => '0.0000',
            'materials_reserve_amount' => '0.00',
            'business_reserve_percentage_snapshot' => '0.0000',
            'business_reserve_amount' => '0.00',
            'owner_allocation_percentage_snapshot' => '100.0000',
            'owner_allocation_amount' => '100.00',
            'completed_at' => now(),
        ]);

        $this->assertNull($attendance->company_id);
    }
}
