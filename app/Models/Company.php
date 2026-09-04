<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Enums\SubscriptionStatus;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'business_profile',
    'slug',
    'document',
    'phone',
    'email',
    'logo_path',
    'logo_disk',
    'timezone',
    'is_active',
    'enabled_modules',
    'trial_ends_at',
    'subscription_status',
    'billing_interval',
    'current_period_end',
    'quoted_price_cents',
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
            'enabled_modules' => 'array',
            'business_profile' => CompanyProfile::class,
            'trial_ends_at' => 'datetime',
            'subscription_status' => SubscriptionStatus::class,
            'billing_interval' => BillingInterval::class,
            'current_period_end' => 'datetime',
            'quoted_price_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'is_active', 'permissions'])
            ->withTimestamps()
            ->using(CompanyUser::class);
    }

    public function isDentalClinic(): bool
    {
        return $this->business_profile === CompanyProfile::DentalClinic;
    }

    public function isCarWash(): bool
    {
        return $this->business_profile === CompanyProfile::CarWash;
    }

    public function hasActiveAdmin(): bool
    {
        return $this->users()
            ->wherePivot('role', CompanyRole::CompanyAdmin->value)
            ->wherePivot('is_active', true)
            ->exists();
    }

    public function logoUrl(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        foreach (['http://', 'https://', '/'] as $prefix) {
            if (str_starts_with((string) $this->logo_path, $prefix)) {
                return (string) $this->logo_path;
            }
        }

        if (str_starts_with((string) $this->logo_path, 'storage/')) {
            return asset((string) $this->logo_path);
        }

        return Storage::disk($this->logo_disk ?: 'public')->url((string) $this->logo_path);
    }

    public function activeAdminsCount(): int
    {
        return $this->users()
            ->wherePivot('role', CompanyRole::CompanyAdmin->value)
            ->wherePivot('is_active', true)
            ->count();
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * @return HasMany<Professional, $this>
     */
    public function professionals(): HasMany
    {
        return $this->hasMany(Professional::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return HasMany<Supplier, $this>
     */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    /**
     * @return HasMany<InventoryBalance, $this>
     */
    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    /**
     * @return HasMany<StockDocument, $this>
     */
    public function stockDocuments(): HasMany
    {
        return $this->hasMany(StockDocument::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * @return HasOne<CompanySchedulingSetting, $this>
     */
    public function schedulingSetting(): HasOne
    {
        return $this->hasOne(CompanySchedulingSetting::class);
    }

    public function dentalClinicSetting(): HasOne
    {
        return $this->hasOne(DentalClinicSetting::class);
    }

    /**
     * @return HasOne<CompanyFinancialSetting, $this>
     */
    public function financialSetting(): HasOne
    {
        return $this->hasOne(CompanyFinancialSetting::class);
    }

    /**
     * @return HasMany<CompanyBusinessHour, $this>
     */
    public function businessHours(): HasMany
    {
        return $this->hasMany(CompanyBusinessHour::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<ScheduleBlock, $this>
     */
    public function scheduleBlocks(): HasMany
    {
        return $this->hasMany(ScheduleBlock::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * @return HasMany<Receivable, $this>
     */
    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * @return HasMany<WhatsAppCampaign, $this>
     */
    public function whatsappCampaigns(): HasMany
    {
        return $this->hasMany(WhatsAppCampaign::class);
    }

    /**
     * @return HasMany<WhatsAppAutomation, $this>
     */
    public function whatsappAutomations(): HasMany
    {
        return $this->hasMany(WhatsAppAutomation::class);
    }

    /**
     * @return HasMany<CompanyWhatsAppInstance, $this>
     */
    public function whatsappInstances(): HasMany
    {
        return $this->hasMany(CompanyWhatsAppInstance::class);
    }

    /**
     * @return HasMany<WhatsAppContact, $this>
     */
    public function whatsappContacts(): HasMany
    {
        return $this->hasMany(WhatsAppContact::class);
    }

    /**
     * @return HasMany<ExpenseCategory, $this>
     */
    public function expenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    /**
     * @return HasMany<FinancialAccount, $this>
     */
    public function financialAccounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class);
    }

    /**
     * @return HasMany<FinancialAccountBalance, $this>
     */
    public function financialAccountBalances(): HasMany
    {
        return $this->hasMany(FinancialAccountBalance::class);
    }

    /**
     * @return HasMany<PlatformInvoice, $this>
     */
    public function platformInvoices(): HasMany
    {
        return $this->hasMany(PlatformInvoice::class);
    }

    /**
     * @return HasMany<Payable, $this>
     */
    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }

    /**
     * @return HasMany<PayableInstallment, $this>
     */
    public function payableInstallments(): HasMany
    {
        return $this->hasMany(PayableInstallment::class);
    }
}
