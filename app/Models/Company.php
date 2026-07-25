<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'slug',
    'document',
    'phone',
    'email',
    'logo_path',
    'timezone',
    'is_active',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Company $company): void {
            if (filled($company->slug)) {
                return;
            }

            $company->slug = static::generateUniqueSlug($company->name);
        });
    }

    public static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (static::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps()
            ->using(CompanyUser::class);
    }

    public function hasActiveAdmin(): bool
    {
        return $this->users()
            ->wherePivot('role', CompanyRole::CompanyAdmin->value)
            ->wherePivot('is_active', true)
            ->exists();
    }

    public function activeAdminsCount(): int
    {
        return $this->users()
            ->wherePivot('role', CompanyRole::CompanyAdmin->value)
            ->wherePivot('is_active', true)
            ->count();
    }
}
