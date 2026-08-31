<?php

namespace App\Models;

use App\Enums\WhatsAppAutomationSendStatus;
use App\Enums\WhatsAppAutomationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'client_id',
    'appointment_id',
    'attendance_id',
    'type',
    'phone',
    'message_snapshot',
    'status',
    'skip_reason',
    'sent_at',
])]
class WhatsAppAutomationSend extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_automation_sends';

    protected $guarded = [
        'company_id',
        'whatsapp_automation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WhatsAppAutomationType::class,
            'status' => WhatsAppAutomationSendStatus::class,
            'sent_at' => 'datetime',
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
     * @return BelongsTo<WhatsAppAutomation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAutomation::class, 'whatsapp_automation_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
