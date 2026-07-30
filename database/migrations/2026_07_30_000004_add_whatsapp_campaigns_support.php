<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('whatsapp_marketing_opt_in')->default(false)->after('is_active');
        });

        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->text('whatsapp_instance_token')->nullable()->after('whatsapp_instance');
            $table->string('whatsapp_instance_status')->nullable()->after('whatsapp_instance_token');
            $table->longText('whatsapp_instance_qr_code')->nullable()->after('whatsapp_instance_status');
            $table->timestamp('whatsapp_instance_connected_at')->nullable()->after('whatsapp_instance_qr_code');
        });

        Schema::create('whatsapp_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('audience_type');
            $table->string('status')->default('draft');
            $table->text('message_template');
            $table->unsignedSmallInteger('send_interval_seconds')->default(20);
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('whatsapp_campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone');
            $table->string('name_snapshot')->nullable();
            $table->text('message_snapshot');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['whatsapp_campaign_id', 'status']);
            $table->unique(['whatsapp_campaign_id', 'phone'], 'wa_campaign_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_campaign_recipients');
        Schema::dropIfExists('whatsapp_campaigns');

        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'whatsapp_instance_token',
                'whatsapp_instance_status',
                'whatsapp_instance_qr_code',
                'whatsapp_instance_connected_at',
            ]);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_marketing_opt_in');
        });
    }
};
