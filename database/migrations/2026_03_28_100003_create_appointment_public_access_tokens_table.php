<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_public_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash');
            $table->index('appointment_id');
            $table->index('expires_at');
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_public_access_tokens');
    }
};
