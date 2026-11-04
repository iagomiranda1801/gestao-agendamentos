<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only the public provider catalogue is shared. No company/customer data is stored here.
        Schema::create('device_compatibility_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20);
            $table->string('query', 100);
            $table->string('query_key', 64);
            $table->string('source', 50);
            $table->json('results');
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->unique(['category', 'source', 'query_key'], 'device_compatibility_lookup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_compatibility_lookups');
    }
};
