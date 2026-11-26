<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table): void {
            $table->string('nfe_access_key', 44)->nullable()->after('reference_key');
            $table->unique(['company_id', 'nfe_access_key'], 'stock_docs_company_nfe_key_unique');
        });

        Schema::create('supplier_product_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_code');
            $table->string('supplier_description')->nullable();
            $table->string('supplier_unit', 10)->nullable();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('conversion_factor', 14, 4)->default(1);
            $table->timestamps();

            $table->index('company_id');
            $table->index('product_id');
            $table->unique(['company_id', 'supplier_id', 'supplier_code'], 'supplier_product_mappings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_mappings');

        Schema::table('stock_documents', function (Blueprint $table): void {
            $table->dropUnique('stock_docs_company_nfe_key_unique');
            $table->dropColumn('nfe_access_key');
        });
    }
};
