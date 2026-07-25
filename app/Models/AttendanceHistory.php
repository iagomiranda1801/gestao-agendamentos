<?php

namespace App\Models;

use App\Enums\AttendanceHistoryType;
use Database\Factories\AttendanceHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'attendance_id',
    'user_id',
    'type',
    'description',
    'metadata',
])]
class AttendanceHistory extends Model
{
    /** @use HasFactory<AttendanceHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttendanceHistoryType::class,
            'metadata' => 'array',
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
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
