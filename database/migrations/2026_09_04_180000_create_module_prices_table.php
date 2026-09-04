<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('module');
            $table->string('interval');
            $table->unsignedInteger('price_cents');
            $table->timestamps();

            $table->unique(['module', 'interval']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_prices');
    }
};
