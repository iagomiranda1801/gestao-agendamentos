<?php

namespace App\Services\Financial;

use App\Enums\ExpenseCategoryType;
use App\Models\Company;
use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): ExpenseCategory
    {
        return DB::transaction(function () use ($company, $data): ExpenseCategory {
            $payload = $this->preparePayload($data);

            $this->assertUniqueName($company, $payload['name']);
            $this->assertUniqueCode($company, $payload['code'] ?? null);
            $this->applyTypeRules($payload);
            $this->assertValidParent($company, $payload['parent_id'] ?? null);

            $category = new ExpenseCategory($payload);
            $category->company()->associate($company);
            $category->save();

            return $category->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, ExpenseCategory $category, array $data): ExpenseCategory
    {
        return DB::transaction(function () use ($company, $category, $data): ExpenseCategory {
            $this->ensureBelongsToCompany($company, $category);

            $payload = $this->preparePayload($data);

            if (array_key_exists('name', $payload)) {
                $this->assertUniqueName($company, $payload['name'], $category);
            }

            if (array_key_exists('code', $payload)) {
                $this->assertUniqueCode($company, $payload['code'], $category);
            }

            if (array_key_exists('parent_id', $payload)) {
                $this->assertValidParent($company, $payload['parent_id'], $category);
            }

            if (array_key_exists('type', $payload)) {
                $this->applyTypeRules($payload);
            }

            $category->fill($payload);
            $category->save();

            return $category->refresh();
        });
    }

    public function deactivate(Company $company, ExpenseCategory $category): ExpenseCategory
    {
        return DB::transaction(function () use ($company, $category): ExpenseCategory {
            $this->ensureBelongsToCompany($company, $category);

            if ($category->is_system) {
                throw ValidationException::withMessages([
                    'is_system' => 'Categorias de sistema não podem ser desativadas.',
                ]);
            }

            $category->update(['is_active' => false]);

            return $category->refresh();
        });
    }

    public function ensureBelongsToCompany(Company $company, ExpenseCategory $category): void
    {
        if ((int) $category->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        unset($data['company_id'], $data['is_system']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function applyTypeRules(array &$payload): void
    {
        $type = $payload['type'] ?? null;

        if ($type === ExpenseCategoryType::StockPurchase->value
            || $type === ExpenseCategoryType::StockPurchase) {
            $payload['affects_managerial_result'] = false;
        }
    }

    protected function assertUniqueName(
        Company $company,
        string $name,
        ?ExpenseCategory $ignore = null,
    ): void {
        $exists = ExpenseCategory::query()
            ->where('company_id', $company->getKey())
            ->where('name', $name)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Já existe uma categoria com este nome nesta empresa.',
            ]);
        }
    }

    protected function assertUniqueCode(
        Company $company,
        ?string $code,
        ?ExpenseCategory $ignore = null,
    ): void {
        if (blank($code)) {
            return;
        }

        $exists = ExpenseCategory::query()
            ->where('company_id', $company->getKey())
            ->where('code', $code)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => 'Já existe uma categoria com este código nesta empresa.',
            ]);
        }
    }

    protected function assertValidParent(
        Company $company,
        mixed $parentId,
        ?ExpenseCategory $category = null,
    ): void {
        if (blank($parentId)) {
            return;
        }

        $parent = ExpenseCategory::query()
            ->where('company_id', $company->getKey())
            ->whereKey($parentId)
            ->first();

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => 'A categoria pai precisa pertencer à mesma empresa.',
            ]);
        }

        if ($category !== null && (int) $parent->getKey() === (int) $category->getKey()) {
            throw ValidationException::withMessages([
                'parent_id' => 'Uma categoria não pode ser pai de si mesma.',
            ]);
        }

        if ($category !== null && $this->createsHierarchyCycle($category, $parent)) {
            throw ValidationException::withMessages([
                'parent_id' => 'A hierarquia de categorias não pode conter ciclos.',
            ]);
        }
    }

    protected function createsHierarchyCycle(ExpenseCategory $category, ExpenseCategory $parent): bool
    {
        $current = $parent;

        while ($current !== null) {
            if ((int) $current->getKey() === (int) $category->getKey()) {
                return true;
            }

            $current = $current->parent;
        }

        return false;
    }
}
