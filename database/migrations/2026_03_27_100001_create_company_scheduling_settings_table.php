<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_scheduling_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('slot_interval_minutes')->default(15);
            $table->time('calendar_start_time')->default('07:00:00');
            $table->time('calendar_end_time')->default('22:00:00');
            $table->unsignedTinyInteger('week_starts_on')->default(1);
            $table->string('default_calendar_view')->default('timeGridWeek');
            $table->boolean('allow_employee_self_view')->default(true);
            $table->timestamps();

            $table->unique('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_scheduling_settings');
    }
};
