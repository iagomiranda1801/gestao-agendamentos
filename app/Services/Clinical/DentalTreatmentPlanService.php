<?php

namespace App\Services\Clinical;

use App\Enums\CompanyPermission;
use App\Models\Client;
use App\Models\Company;
use App\Models\DentalTreatmentPlan;
use App\Models\DentalTreatmentPlanItem;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DentalTreatmentPlanService
{
    public function __construct(protected ClinicalAuthorizationService $authorization, protected ClinicalAuditService $audit) {}

    /** @param array<string, mixed> $data @param list<array<string, mixed>> $items */
    public function create(Company $company, Client $client, Professional $professional, User $user, array $data, array $items = []): DentalTreatmentPlan
    {
        $this->authorization->authorize($user, $company, CompanyPermission::ManageTreatmentPlans, $client);
        $this->authorization->assertProfessional($company, $professional);

        return DB::transaction(function () use ($company, $client, $professional, $user, $data, $items): DentalTreatmentPlan {
            unset($data['company_id'], $data['client_id'], $data['created_by'], $data['approved_by'], $data['approved_at']);
            $plan = new DentalTreatmentPlan($data + ['plan_date' => today(), 'status' => 'draft']);
            $plan->company_id = $company->getKey();
            $plan->client_id = $client->getKey();
            $plan->professional_id = $professional->getKey();
            $plan->created_by = $user->getKey();
            $plan->save();
            $this->replaceItems($company, $plan, $items);
            $this->recalculate($plan);
            $this->audit->record($company, $client, $user, 'treatment_plan.created', $plan);

            return $plan->refresh()->load('items');
        });
    }

    /** @param list<array<string, mixed>> $items */
    public function replaceItems(Company $company, DentalTreatmentPlan $plan, array $items): void
    {
        abort_unless((int) $plan->company_id === (int) $company->getKey(), 404);
        abort_if($plan->approved_at !== null, 422, 'Planos aprovados não podem ter seus valores substituídos.');
        $plan->items()->delete();

        foreach ($items as $position => $data) {
            $quantity = max(1, (int) ($data['quantity'] ?? 1));
            $unitPrice = max(0, (float) ($data['unit_price'] ?? 0));
            $discount = max(0, (float) ($data['discount_amount'] ?? 0));
            $total = max(0, ($quantity * $unitPrice) - $discount);
            unset($data['company_id'], $data['treatment_plan_id'], $data['total_amount']);
            $item = new DentalTreatmentPlanItem($data + [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'sort_order' => $position,
            ]);
            $item->company_id = $company->getKey();
            $item->treatment_plan_id = $plan->getKey();
            $item->save();
        }
    }

    public function approve(Company $company, DentalTreatmentPlan $plan, User $user): DentalTreatmentPlan
    {
        $client = Client::query()->where('company_id', $company->getKey())->findOrFail($plan->client_id);
        $this->authorization->authorize($user, $company, CompanyPermission::ManageTreatmentPlans, $client);
        abort_unless((int) $plan->company_id === (int) $company->getKey(), 404);

        if ($plan->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => 'Adicione ao menos um item ao plano.']);
        }

        return DB::transaction(function () use ($company, $client, $plan, $user): DentalTreatmentPlan {
            $this->recalculate($plan);
            if ($plan->is_primary) {
                DentalTreatmentPlan::query()
                    ->where('company_id', $company->getKey())
                    ->where('client_id', $client->getKey())
                    ->whereKeyNot($plan->getKey())
                    ->update(['is_primary' => false]);
            }
            $plan->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->getKey()]);
            $this->audit->record($company, $client, $user, 'treatment_plan.approved', $plan, ['total_amount' => $plan->total_amount]);

            return $plan->refresh()->load('items');
        });
    }

    /** @param array<string, mixed> $data @param list<array<string, mixed>> $items */
    public function update(Company $company, DentalTreatmentPlan $plan, User $user, array $data, array $items): DentalTreatmentPlan
    {
        abort_unless((int) $plan->company_id === (int) $company->getKey(), 404);
        $client = Client::query()->where('company_id', $company->getKey())->findOrFail($plan->client_id);
        $this->authorization->authorize($user, $company, CompanyPermission::ManageTreatmentPlans, $client);
        abort_if($plan->approved_at !== null, 422, 'Planos aprovados preservam o snapshot financeiro e não podem ser alterados.');

        return DB::transaction(function () use ($company, $client, $plan, $user, $data, $items): DentalTreatmentPlan {
            unset($data['company_id'], $data['client_id'], $data['created_by'], $data['approved_by'], $data['approved_at'], $data['subtotal'], $data['total_amount']);
            $plan->fill($data)->save();
            $this->replaceItems($company, $plan, $items);
            $this->recalculate($plan);
            $this->audit->record($company, $client, $user, 'treatment_plan.updated', $plan);

            return $plan->refresh()->load('items');
        });
    }

    public function markItemPerformed(Company $company, DentalTreatmentPlanItem $item, User $user, ?int $attendanceId = null, ?int $clinicalEntryId = null): DentalTreatmentPlanItem
    {
        $plan = $item->plan()->firstOrFail();
        $client = Client::query()->where('company_id', $company->getKey())->findOrFail($plan->client_id);
        $this->authorization->authorize($user, $company, CompanyPermission::ManageTreatmentPlans, $client);
        abort_unless((int) $item->company_id === (int) $company->getKey(), 404);
        $item->update(['status' => 'performed', 'attendance_id' => $attendanceId, 'clinical_entry_id' => $clinicalEntryId]);
        $this->audit->record($company, $client, $user, 'treatment_plan.item_performed', $item, ['plan_id' => $plan->getKey()]);

        return $item->refresh();
    }

    protected function recalculate(DentalTreatmentPlan $plan): void
    {
        $subtotal = (float) $plan->items()->sum('total_amount');
        $discount = min($subtotal, max(0, (float) $plan->discount_amount));
        $plan->forceFill(['subtotal' => $subtotal, 'discount_amount' => $discount, 'total_amount' => $subtotal - $discount])->save();
    }
}
