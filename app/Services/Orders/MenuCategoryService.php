<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\MenuCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MenuCategoryService
{
    /**
     * @var list<array{name: string, sort_order: int}>
     */
    public const DEFAULTS = [
        ['name' => 'Lanches', 'sort_order' => 10],
        ['name' => 'Bebidas', 'sort_order' => 20],
        ['name' => 'Sobremesas', 'sort_order' => 30],
        ['name' => 'Combos', 'sort_order' => 40],
        ['name' => 'Outros', 'sort_order' => 50],
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): MenuCategory
    {
        return DB::transaction(function () use ($company, $data): MenuCategory {
            $payload = $this->preparePayload($company, $data);

            $this->assertUniqueSlug($company, $payload['slug']);

            $category = new MenuCategory($payload);
            $category->company()->associate($company);
            $category->save();

            return $category->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, MenuCategory $category, array $data): MenuCategory
    {
        return DB::transaction(function () use ($company, $category, $data): MenuCategory {
            $this->ensureBelongsToCompany($company, $category);

            $payload = $this->preparePayload($company, $data, $category);

            if (array_key_exists('slug', $payload)) {
                $this->assertUniqueSlug($company, $payload['slug'], $category);
            }

            $category->fill($payload);
            $category->save();

            if (array_key_exists('name', $payload)) {
                $category->products()->update([
                    'online_order_category' => $category->name,
                ]);
            }

            return $category->refresh();
        });
    }

    public function deactivate(Company $company, MenuCategory $category): MenuCategory
    {
        return DB::transaction(function () use ($company, $category): MenuCategory {
            $this->ensureBelongsToCompany($company, $category);

            $category->update(['is_active' => false]);

            return $category->refresh();
        });
    }

    public function ensureDefaults(Company $company): void
    {
        if (MenuCategory::query()->where('company_id', $company->getKey())->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $row) {
            $this->create($company, [
                'name' => $row['name'],
                'sort_order' => $row['sort_order'],
                'is_active' => true,
            ]);
        }
    }

    public function findOrCreate(Company $company, string $name): MenuCategory
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Informe o nome da categoria.',
            ]);
        }

        $slug = $this->slugFromName($name);

        $existing = MenuCategory::query()
            ->where('company_id', $company->getKey())
            ->where('slug', $slug)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->create($company, [
            'name' => $name,
            'is_active' => true,
        ]);
    }

    public function ensureBelongsToCompany(Company $company, MenuCategory $category): void
    {
        if ((int) $category->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    public function slugFromName(string $name): string
    {
        $slug = Str::slug($name);

        if ($slug !== '') {
            return mb_substr($slug, 0, 80);
        }

        return 'categoria-'.substr(sha1(mb_strtolower(trim($name))), 0, 8);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(Company $company, array $data, ?MenuCategory $ignore = null): array
    {
        unset($data['company_id'], $data['slug']);

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => 'Informe o nome da categoria.',
                ]);
            }

            $data['name'] = mb_substr($name, 0, 80);
            $data['slug'] = $this->slugFromName($data['name']);
        }

        if (! array_key_exists('sort_order', $data) && $ignore === null) {
            $max = (int) MenuCategory::query()
                ->where('company_id', $company->getKey())
                ->max('sort_order');

            $data['sort_order'] = $max + 10;
        }

        if (array_key_exists('sort_order', $data)) {
            $data['sort_order'] = max(0, (int) $data['sort_order']);
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        return $data;
    }

    protected function assertUniqueSlug(Company $company, string $slug, ?MenuCategory $ignore = null): void
    {
        $exists = MenuCategory::query()
            ->where('company_id', $company->getKey())
            ->where('slug', $slug)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Já existe uma categoria com este nome nesta empresa.',
            ]);
        }
    }
}
