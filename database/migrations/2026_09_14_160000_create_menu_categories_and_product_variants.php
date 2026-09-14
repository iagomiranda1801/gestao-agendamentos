<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 80);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
            $table->index(['company_id', 'is_active', 'sort_order']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('menu_category_id')
                ->nullable()
                ->after('online_order_category')
                ->constrained('menu_categories')
                ->nullOnDelete();
        });

        $this->backfillMenuCategories();

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active', 'sort_order']);
            $table->index(['company_id', 'product_id']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->nullOnDelete();
            $table->string('variant_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn('variant_name');
        });

        Schema::dropIfExists('product_variants');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('menu_category_id');
        });

        Schema::dropIfExists('menu_categories');
    }

    protected function backfillMenuCategories(): void
    {
        $products = DB::table('products')
            ->whereNotNull('online_order_category')
            ->where('online_order_category', '!=', '')
            ->whereNull('menu_category_id')
            ->orderBy('company_id')
            ->orderBy('id')
            ->get(['id', 'company_id', 'online_order_category']);

        $cache = [];
        $sortByCompany = [];

        foreach ($products as $product) {
            $name = trim((string) $product->online_order_category);

            if ($name === '') {
                continue;
            }

            $slug = Str::slug($name);

            if ($slug === '') {
                $slug = 'categoria-'.substr(sha1(mb_strtolower($name)), 0, 8);
            }

            $key = $product->company_id.'|'.$slug;

            if (! isset($cache[$key])) {
                $sortByCompany[$product->company_id] = ($sortByCompany[$product->company_id] ?? 0) + 10;

                $id = DB::table('menu_categories')->insertGetId([
                    'company_id' => $product->company_id,
                    'name' => mb_substr($name, 0, 80),
                    'slug' => mb_substr($slug, 0, 80),
                    'sort_order' => $sortByCompany[$product->company_id],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $cache[$key] = $id;
            }

            DB::table('products')
                ->where('id', $product->id)
                ->update(['menu_category_id' => $cache[$key]]);
        }
    }
};
