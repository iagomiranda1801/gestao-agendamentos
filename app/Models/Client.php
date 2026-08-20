<?php

namespace App\Models;

use App\Support\PhoneNormalizer;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name',
    'phone',
    'phone_normalized',
    'email',
    'document',
    'birth_date',
    'notes',
    'is_active',
    'whatsapp_marketing_opt_in',
    'source',
    'source_imported_at',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [
        'company_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Client $client): void {
            $client->phone_normalized = PhoneNormalizer::normalize($client->phone) ?? '';
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'is_active' => 'boolean',
            'whatsapp_marketing_opt_in' => 'boolean',
            'source_imported_at' => 'datetime',
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
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function dentalProfile(): HasOne
    {
        return $this->hasOne(DentalPatientProfile::class);
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(PatientGuardian::class);
    }

    public function insurances(): HasMany
    {
        return $this->hasMany(PatientInsurance::class);
    }

    public function dentalAnamneses(): HasMany
    {
        return $this->hasMany(DentalAnamnesis::class);
    }

    public function clinicalAlerts(): HasMany
    {
        return $this->hasMany(PatientClinicalAlert::class);
    }

    public function activeClinicalAlerts(): HasMany
    {
        return $this->hasMany(PatientClinicalAlert::class)->where('is_active', true);
    }

    public function clinicalEntries(): HasMany
    {
        return $this->hasMany(DentalClinicalEntry::class);
    }

    public function treatmentPlans(): HasMany
    {
        return $this->hasMany(DentalTreatmentPlan::class);
    }

    public function odontograms(): HasMany
    {
        return $this->hasMany(DentalOdontogram::class);
    }

    public function clinicalAttachments(): HasMany
    {
        return $this->hasMany(ClinicalAttachment::class);
    }

    public function clinicalAuditEvents(): HasMany
    {
        return $this->hasMany(ClinicalAuditEvent::class)->orderByDesc('occurred_at');
    }

    /**
     * @return HasMany<WhatsAppCampaignRecipient, $this>
     */
    public function whatsappCampaignRecipients(): HasMany
    {
        return $this->hasMany(WhatsAppCampaignRecipient::class);
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopeWhatsappMarketingOptedIn(Builder $query): Builder
    {
        return $query->where('whatsapp_marketing_opt_in', true);
    }
}
