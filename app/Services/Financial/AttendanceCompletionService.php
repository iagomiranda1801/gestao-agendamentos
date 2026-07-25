<?php

namespace App\Services\Financial;

use App\DataTransferObjects\Financial\AttendanceCompletionData;
use App\DataTransferObjects\Financial\AttendanceMaterialInput;
use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentStatus;
use App\Enums\AttendanceHistoryType;
use App\Enums\ProductType;
use App\Enums\StockDocumentType;
use App\Models\Appointment;
use App\Models\AppointmentHistory;
use App\Models\Attendance;
use App\Models\AttendanceHistory;
use App\Models\AttendanceMaterial;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Services\Scheduling\AppointmentService;
use App\Services\Stock\StockDocumentPostingService;
use App\Services\Stock\StockDocumentService;
use App\Support\DecimalMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceCompletionService
{
    public function __construct(
        protected AttendanceFinancialCalculator $calculator,
        protected CommissionResolver $commissionResolver,
        protected CompanyFinancialSettingService $financialSettingService,
        protected ReceivableService $receivableService,
        protected StockDocumentService $stockDocumentService,
        protected StockDocumentPostingService $stockDocumentPostingService,
        protected AttendancePolicy $attendancePolicy,
    ) {}

    public function complete(
        Company $company,
        User $user,
        Appointment $appointment,
        AttendanceCompletionData $data,
    ): Attendance {
        app(AppointmentService::class)->ensureBelongsToCompany($company, $appointment);

        if (! $this->attendancePolicy->complete($user, $appointment)) {
            throw new AuthorizationException('Você não tem permissão para concluir este agendamento.');
        }

        $appointment->load(['client', 'professional', 'service']);

        $grossAmount = $this->resolveGrossAmount($appointment, $data);
        $discountAmount = $this->normalizeNonNegativeMoney($data->discountAmount, 'discount_amount');
        $finalAmount = $this->calculator->calculateFinalAmount($grossAmount, $discountAmount);

        $validatedMaterials = $this->validateMaterials($company, $data->materials);
        $this->validatePayments($company, $data->payments, $finalAmount);
        $this->validateStockAvailability($company, $validatedMaterials);

        $settings = $this->financialSettingService->getOrCreate($company);
        $commissionResult = $this->commissionResolver->resolve(
            $company,
            $appointment->professional,
            $appointment->service,
            $finalAmount,
        );
        $financialResult = $this->calculator->calculateDistribution(
            $commissionResult->type,
            $commissionResult->configuredValue,
            $finalAmount,
            (string) $settings->materials_reserve_percentage,
            (string) $settings->business_reserve_percentage,
        );

        $completedAt = $data->completedAt !== null
            ? Carbon::parse($data->completedAt)
            : now();

        return DB::transaction(function () use (
            $company,
            $user,
            $appointment,
            $data,
            $grossAmount,
            $discountAmount,
            $finalAmount,
            $financialResult,
            $validatedMaterials,
            $completedAt,
        ): Attendance {
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAppointment->attendance()->exists()) {
                throw ValidationException::withMessages([
                    'appointment_id' => 'Este agendamento já possui um atendimento concluído.',
                ]);
            }

            if (! in_array($lockedAppointment->status, [
                AppointmentStatus::Confirmed,
                AppointmentStatus::InProgress,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Somente agendamentos confirmados ou em atendimento podem ser concluídos.',
                ]);
            }

            $this->validateStockAvailability($company, $validatedMaterials);

            $attendance = new Attendance([
                'service_name_snapshot' => $lockedAppointment->service_name_snapshot,
                'client_name_snapshot' => $lockedAppointment->client_name_snapshot ?? $lockedAppointment->client->name,
                'professional_name_snapshot' => $lockedAppointment->professional->name,
                'gross_amount' => $grossAmount,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'commission_type_snapshot' => $financialResult->commissionType,
                'commission_value_snapshot' => $financialResult->commissionValueSnapshot,
                'commission_amount' => $financialResult->commissionAmount,
                'materials_reserve_percentage_snapshot' => $financialResult->materialsReservePercentageSnapshot,
                'materials_reserve_amount' => $financialResult->materialsReserveAmount,
                'business_reserve_percentage_snapshot' => $financialResult->businessReservePercentageSnapshot,
                'business_reserve_amount' => $financialResult->businessReserveAmount,
                'owner_allocation_percentage_snapshot' => $financialResult->ownerAllocationPercentageSnapshot,
                'owner_allocation_amount' => $financialResult->ownerAllocationAmount,
                'actual_material_cost' => '0.00',
                'payment_fee_amount' => '0.00',
                'operational_result' => $financialResult->operationalResult,
                'notes' => $data->notes,
                'internal_notes' => $data->internalNotes,
                'completed_at' => $completedAt,
            ]);
            $attendance->company()->associate($company);
            $attendance->appointment()->associate($lockedAppointment);
            $attendance->client()->associate($lockedAppointment->client);
            $attendance->professional()->associate($lockedAppointment->professional);
            $attendance->service()->associate($lockedAppointment->service);
            $attendance->completedBy()->associate($user);
            $attendance->save();

            $stockDocument = $this->createAndPostStockDocument(
                $company,
                $user,
                $attendance,
                $validatedMaterials,
                $completedAt,
            );

            $actualMaterialCost = $this->createAttendanceMaterials(
                $company,
                $attendance,
                $validatedMaterials,
                $stockDocument,
            );

            $attendance->forceFill([
                'actual_material_cost' => $actualMaterialCost,
                'operational_result' => $this->calculator->calculateOperationalResult(
                    $finalAmount,
                    $actualMaterialCost,
                    $financialResult->commissionAmount,
                    '0.00',
                ),
            ])->save();

            $receivable = $this->receivableService->createForAttendance($company, $attendance, $user);

            foreach ($data->payments as $paymentData) {
                $this->receivableService->registerPayment(
                    $company,
                    $receivable,
                    $paymentData,
                    $user,
                );
            }

            $attendance->refresh()->load('payments');
            $paymentFeeAmount = $this->calculator->sumConfirmedPaymentFees($attendance->payments);

            $attendance->forceFill([
                'payment_fee_amount' => $paymentFeeAmount,
                'operational_result' => $this->calculator->calculateOperationalResult(
                    $finalAmount,
                    $actualMaterialCost,
                    $financialResult->commissionAmount,
                    $paymentFeeAmount,
                ),
            ])->save();

            $oldStatus = $lockedAppointment->status;
            $lockedAppointment->status = AppointmentStatus::Completed;
            $lockedAppointment->save();

            $this->recordAppointmentHistory($company, $user, $lockedAppointment, $oldStatus);
            $this->recordAttendanceHistory($company, $user, $attendance, $finalAmount);

            return $attendance->refresh()->load([
                'materials',
                'receivable',
                'payments',
                'stockDocument',
            ]);
        });
    }

    protected function resolveGrossAmount(Appointment $appointment, AttendanceCompletionData $data): string
    {
        if ($data->grossAmount !== null) {
            return $this->normalizeNonNegativeMoney($data->grossAmount, 'gross_amount');
        }

        return DecimalMoney::round((string) $appointment->price_snapshot);
    }

    /**
     * @param  list<AttendanceMaterialInput>  $materials
     * @return list<array{
     *     input: AttendanceMaterialInput,
     *     product: Product
     * }>
     */
    protected function validateMaterials(Company $company, array $materials): array
    {
        $validated = [];
        $productIds = [];

        foreach ($materials as $index => $input) {
            if (bccomp($input->quantity, '0', 4) <= 0) {
                continue;
            }

            if (in_array($input->productId, $productIds, true)) {
                throw ValidationException::withMessages([
                    "materials.{$index}.product_id" => 'O mesmo produto não pode ser informado mais de uma vez.',
                ]);
            }

            $product = Product::query()
                ->whereKey($input->productId)
                ->where('company_id', $company->getKey())
                ->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    "materials.{$index}.product_id" => 'Produto inválido para esta empresa.',
                ]);
            }

            if (! $product->is_active) {
                throw ValidationException::withMessages([
                    "materials.{$index}.product_id" => 'O produto selecionado está inativo.',
                ]);
            }

            if ($product->type === ProductType::Asset) {
                throw ValidationException::withMessages([
                    "materials.{$index}.product_id" => 'Produtos do tipo investimento/ativo não podem ser consumidos no atendimento.',
                ]);
            }

            if (bccomp($input->quantity, '0', 4) < 0) {
                throw ValidationException::withMessages([
                    "materials.{$index}.quantity" => 'A quantidade não pode ser negativa.',
                ]);
            }

            if (bccomp($input->plannedQuantity, '0', 4) < 0) {
                throw ValidationException::withMessages([
                    "materials.{$index}.planned_quantity" => 'A quantidade planejada não pode ser negativa.',
                ]);
            }

            $productIds[] = $input->productId;
            $validated[] = [
                'input' => $input,
                'product' => $product,
            ];
        }

        return $validated;
    }

    /**
     * @param  list<PaymentData>  $payments
     */
    protected function validatePayments(Company $company, array $payments, string $finalAmount): void
    {
        $settings = $this->financialSettingService->getOrCreate($company);

        if ($payments === []) {
            if (! $settings->allow_unpaid_completion) {
                throw ValidationException::withMessages([
                    'payments' => 'É necessário registrar ao menos um pagamento para concluir o atendimento.',
                ]);
            }

            return;
        }

        $totalNet = '0.00';

        foreach ($payments as $index => $payment) {
            $totalNet = bcadd(
                $totalNet,
                $this->calculator->calculateNetAmount($payment->amount, $payment->feeAmount),
                2,
            );
        }

        if (bccomp($totalNet, $finalAmount, 2) > 0) {
            throw ValidationException::withMessages([
                'payments' => 'A soma dos pagamentos não pode ser maior que o valor final do atendimento.',
            ]);
        }

        if (bccomp($totalNet, $finalAmount, 2) < 0 && ! $settings->allow_partial_payments) {
            throw ValidationException::withMessages([
                'payments' => 'Pagamentos parciais não são permitidos para esta empresa.',
            ]);
        }
    }

    /**
     * @param  list<array{input: AttendanceMaterialInput, product: Product}>  $materials
     */
    protected function validateStockAvailability(Company $company, array $materials): void
    {
        foreach ($materials as $material) {
            $product = $material['product'];
            $quantity = $material['input']->quantity;

            if (! $product->tracks_stock) {
                continue;
            }

            $onHand = $product->getCurrentStockQuantity();

            if (bccomp($onHand, $quantity, 4) < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "Saldo insuficiente para o produto {$product->name}.",
                ]);
            }
        }
    }

    /**
     * @param  list<array{input: AttendanceMaterialInput, product: Product}>  $materials
     */
    protected function createAndPostStockDocument(
        Company $company,
        User $user,
        Attendance $attendance,
        array $materials,
        Carbon $completedAt,
    ): ?StockDocument {
        $stockItems = [];

        foreach ($materials as $material) {
            $product = $material['product'];

            if (! $product->tracks_stock) {
                continue;
            }

            $stockItems[] = [
                'product_id' => $product->getKey(),
                'quantity' => $material['input']->quantity,
                'notes' => $material['input']->notes,
            ];
        }

        if ($stockItems === []) {
            return null;
        }

        $document = $this->stockDocumentService->createDraft(
            $company,
            StockDocumentType::ServiceConsumption,
            [
                'attendance_id' => $attendance->getKey(),
                'reference_key' => "attendance:{$attendance->getKey()}:service-consumption",
                'occurred_at' => $completedAt,
                'notes' => "Consumo do atendimento #{$attendance->getKey()}",
            ],
            $stockItems,
            $user,
        );

        return $this->stockDocumentPostingService->post($company, $document, $user);
    }

    /**
     * @param  list<array{input: AttendanceMaterialInput, product: Product}>  $materials
     */
    protected function createAttendanceMaterials(
        Company $company,
        Attendance $attendance,
        array $materials,
        ?StockDocument $stockDocument,
    ): string {
        $totalMaterialCost = '0.00';

        foreach ($materials as $material) {
            $input = $material['input'];
            $product = $material['product'];

            $movement = null;
            $documentItem = null;

            if ($product->tracks_stock && $stockDocument !== null) {
                $documentItem = StockDocumentItem::query()
                    ->where('stock_document_id', $stockDocument->getKey())
                    ->where('product_id', $product->getKey())
                    ->first();

                $movement = StockMovement::query()
                    ->where('stock_document_id', $stockDocument->getKey())
                    ->where('product_id', $product->getKey())
                    ->first();
            }

            $unitCost = $movement !== null
                ? (string) $movement->unit_cost
                : $product->getCurrentUnitCost();

            $lineTotal = DecimalMoney::round(bcmul($input->quantity, $unitCost, 6));

            $attendanceMaterial = new AttendanceMaterial([
                'product_name_snapshot' => $product->name,
                'planned_quantity' => $input->plannedQuantity,
                'quantity' => $input->quantity,
                'unit_cost_snapshot' => $unitCost,
                'total_cost' => $lineTotal,
                'tracks_stock_snapshot' => $product->tracks_stock,
                'notes' => $input->notes,
            ]);
            $attendanceMaterial->company()->associate($company);
            $attendanceMaterial->attendance()->associate($attendance);
            $attendanceMaterial->product()->associate($product);

            if ($stockDocument !== null) {
                $attendanceMaterial->stockDocument()->associate($stockDocument);
            }

            if ($documentItem !== null) {
                $attendanceMaterial->stockDocumentItem()->associate($documentItem);
            }

            if ($movement !== null) {
                $attendanceMaterial->stockMovement()->associate($movement);
            }

            $attendanceMaterial->save();

            $totalMaterialCost = bcadd($totalMaterialCost, $lineTotal, 2);
        }

        return DecimalMoney::round($totalMaterialCost);
    }

    protected function recordAppointmentHistory(
        Company $company,
        User $user,
        Appointment $appointment,
        AppointmentStatus $oldStatus,
    ): void {
        $history = new AppointmentHistory([
            'type' => AppointmentHistoryType::Completed,
            'old_status' => $oldStatus,
            'new_status' => AppointmentStatus::Completed,
            'old_start_at' => $appointment->start_at,
            'new_start_at' => $appointment->start_at,
            'old_end_at' => $appointment->end_at,
            'new_end_at' => $appointment->end_at,
        ]);
        $history->company()->associate($company);
        $history->appointment()->associate($appointment);
        $history->user()->associate($user);
        $history->save();
    }

    protected function recordAttendanceHistory(
        Company $company,
        User $user,
        Attendance $attendance,
        string $finalAmount,
    ): void {
        $history = new AttendanceHistory([
            'type' => AttendanceHistoryType::Completed,
            'description' => 'Atendimento concluído.',
            'metadata' => [
                'final_amount' => $finalAmount,
            ],
        ]);
        $history->company()->associate($company);
        $history->attendance()->associate($attendance);
        $history->user()->associate($user);
        $history->save();
    }

    protected function normalizeNonNegativeMoney(string $amount, string $field): string
    {
        if (bccomp($amount, '0', 2) < 0) {
            throw ValidationException::withMessages([
                $field => 'O valor não pode ser negativo.',
            ]);
        }

        return DecimalMoney::round($amount);
    }
}
