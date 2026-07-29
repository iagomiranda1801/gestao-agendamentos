<?php

namespace App\Models;

use App\Enums\CommissionType;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'appointment_id',
    'client_id',
    'professional_id',
    'service_id',
    'service_name_snapshot',
    'client_name_snapshot',
    'professional_name_snapshot',
    'gross_amount',
    'discount_amount',
    'final_amount',
    'commission_type_snapshot',
    'commission_value_snapshot',
    'commission_amount',
    'materials_reserve_percentage_snapshot',
    'materials_reserve_amount',
    'business_reserve_percentage_snapshot',
    'business_reserve_amount',
    'owner_allocation_percentage_snapshot',
    'owner_allocation_amount',
    'actual_material_cost',
    'payment_fee_amount',
    'operational_result',
    'notes',
    'internal_notes',
    'completed_by',
    'completed_at',
])]
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    protected $guarded = ['company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'commission_type_snapshot' => CommissionType::class,
            'commission_value_snapshot' => 'decimal:4',
            'commission_amount' => 'decimal:2',
            'materials_reserve_percentage_snapshot' => 'decimal:4',
            'materials_reserve_amount' => 'decimal:2',
            'business_reserve_percentage_snapshot' => 'decimal:4',
            'business_reserve_amount' => 'decimal:2',
            'owner_allocation_percentage_snapshot' => 'decimal:4',
            'owner_allocation_amount' => 'decimal:2',
            'actual_material_cost' => 'decimal:2',
            'payment_fee_amount' => 'decimal:2',
            'operational_result' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return HasMany<AttendanceMaterial, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(AttendanceMaterial::class);
    }

    /**
     * @return HasMany<AttendanceHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(AttendanceHistory::class);
    }

    /**
     * @return HasOne<Receivable, $this>
     */
    public function receivable(): HasOne
    {
        return $this->hasOne(Receivable::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasOne<StockDocument, $this>
     */
    public function stockDocument(): HasOne
    {
        return $this->hasOne(StockDocument::class);
    }

    /**
     * @return HasOne<Payable, $this>
     */
    public function commissionPayable(): HasOne
    {
        return $this->hasOne(Payable::class);
    }
}
