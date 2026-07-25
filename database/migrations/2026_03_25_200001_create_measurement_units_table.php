<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('symbol');
            $table->string('code')->unique();
            $table->string('category');
            $table->unsignedTinyInteger('decimal_places')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_units');
    }
};
