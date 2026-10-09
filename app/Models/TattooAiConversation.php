<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TattooAiConversation extends Model
{
    public const STATUS_LABELS = [
        'collecting_information' => 'Coletando informações',
        'waiting_professional_quote' => 'Aguardando orçamento',
        'waiting_payment_receipt' => 'Aguardando comprovante',
        'receipt_received' => 'Comprovante em análise',
        'payment_confirmed' => 'Sinal confirmado',
        'ready_to_schedule' => 'Pronto para agendar',
        'converted_to_appointment' => 'Agendado',
        'human_takeover' => 'Atendimento humano',
        'offering_slots' => 'Escolhendo horário',
        'awaiting_confirmation' => 'Aguardando confirmação',
    ];

    protected $guarded = ['id'];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? str_replace('_', ' ', $status ?? '');
    }

    protected function casts(): array
    {
        return ['human_takeover' => 'boolean', 'collected_data' => 'array', 'last_interaction_at' => 'datetime',
            'context_start_message_id' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TattooRequest::class, 'tattoo_request_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(CompanyWhatsAppInstance::class, 'company_whatsapp_instance_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TattooAiMessage::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(TattooPaymentReceipt::class);
    }
}
