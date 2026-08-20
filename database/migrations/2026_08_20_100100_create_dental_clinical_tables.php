<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dental_clinic_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('professional_record_scope')->default('all');
            $table->boolean('minor_guardian_required')->default(false);
            $table->boolean('clinical_entry_required_to_complete')->default(false);
            $table->timestamps();
        });

        Schema::create('dental_patient_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('record_number');
            $table->string('social_name')->nullable();
            $table->string('sex')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('street')->nullable();
            $table->string('street_number')->nullable();
            $table->string('address_complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'client_id'], 'dental_patient_company_client_unique');
            $table->unique(['company_id', 'record_number'], 'dental_patient_company_record_unique');
        });

        Schema::create('patient_guardians', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('document')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('relationship')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_legal_guardian')->default(true);
            $table->boolean('is_financial_guardian')->default(false);
            $table->timestamps();
            $table->index(['company_id', 'client_id'], 'patient_guardian_company_client_idx');
        });

        Schema::create('patient_insurances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('plan')->nullable();
            $table->string('card_number')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('holder_name')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'client_id'], 'patient_insurance_company_client_idx');
        });

        Schema::create('dental_anamneses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft');
            $table->json('questionnaire_snapshot');
            $table->json('answers');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('professionals')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'client_id', 'version'], 'dental_anamnesis_version_unique');
            $table->index(['company_id', 'client_id', 'status'], 'dental_anamnesis_patient_status_idx');
        });

        Schema::create('patient_clinical_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('severity')->default('attention');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'client_id', 'is_active'], 'clinical_alert_patient_active_idx');
        });

        Schema::create('dental_clinical_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('professional_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft');
            $table->dateTime('occurred_at');
            $table->text('chief_complaint')->nullable();
            $table->text('clinical_assessment')->nullable();
            $table->text('procedure_performed')->nullable();
            $table->json('teeth')->nullable();
            $table->text('materials_medications')->nullable();
            $table->text('anesthetic')->nullable();
            $table->text('complications')->nullable();
            $table->text('guidance')->nullable();
            $table->text('next_steps')->nullable();
            $table->date('recommended_return_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'client_id', 'occurred_at'], 'clinical_entry_patient_date_idx');
            $table->index(['company_id', 'professional_id', 'occurred_at'], 'clinical_entry_prof_date_idx');
        });

        Schema::create('dental_clinical_entry_addenda', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinical_entry_id')->constrained('dental_clinical_entries')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->text('content');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['company_id', 'clinical_entry_id'], 'clinical_addendum_entry_idx');
        });

        Schema::create('dental_treatment_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('professional_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->date('plan_date');
            $table->date('valid_until')->nullable();
            $table->text('clinical_notes')->nullable();
            $table->text('commercial_notes')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'client_id', 'status'], 'treatment_plan_patient_status_idx');
        });

        Schema::create('dental_treatment_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_plan_id')->constrained('dental_treatment_plans')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('clinical_entry_id')->nullable()->constrained('dental_clinical_entries')->nullOnDelete();
            $table->string('description');
            $table->string('tooth')->nullable();
            $table->json('surfaces')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('priority')->default('normal');
            $table->string('status')->default('proposed');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'treatment_plan_id'], 'treatment_plan_item_plan_idx');
        });

        Schema::create('dental_odontograms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('professional_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'client_id', 'version'], 'odontogram_patient_version_unique');
        });

        Schema::create('dental_odontogram_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('odontogram_id')->constrained('dental_odontograms')->cascadeOnDelete();
            $table->foreignId('treatment_plan_item_id')->nullable()->constrained('dental_treatment_plan_items')->nullOnDelete();
            $table->foreignId('clinical_entry_id')->nullable()->constrained('dental_clinical_entries')->nullOnDelete();
            $table->string('tooth');
            $table->json('surfaces')->nullable();
            $table->string('condition');
            $table->string('stage')->default('existing');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'odontogram_id', 'tooth'], 'odontogram_entry_tooth_idx');
        });

        Schema::create('clinical_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('attachable_type')->nullable();
            $table->unsignedBigInteger('attachable_id')->nullable();
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('document_date')->nullable();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['company_id', 'client_id'], 'clinical_attachment_patient_idx');
            $table->index(['attachable_type', 'attachable_id'], 'clinical_attachment_attachable_idx');
        });

        Schema::create('clinical_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['company_id', 'client_id', 'occurred_at'], 'clinical_audit_patient_date_idx');
            $table->index(['entity_type', 'entity_id'], 'clinical_audit_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_audit_events');
        Schema::dropIfExists('clinical_attachments');
        Schema::dropIfExists('dental_odontogram_entries');
        Schema::dropIfExists('dental_odontograms');
        Schema::dropIfExists('dental_treatment_plan_items');
        Schema::dropIfExists('dental_treatment_plans');
        Schema::dropIfExists('dental_clinical_entry_addenda');
        Schema::dropIfExists('dental_clinical_entries');
        Schema::dropIfExists('patient_clinical_alerts');
        Schema::dropIfExists('dental_anamneses');
        Schema::dropIfExists('patient_insurances');
        Schema::dropIfExists('patient_guardians');
        Schema::dropIfExists('dental_patient_profiles');
        Schema::dropIfExists('dental_clinic_settings');
    }
};
