<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addProductSalesColumns();
        $this->createSalesTable();
        $this->createSaleItemsTable();
        $this->addReceivableSalesColumns();
        $this->addPaymentSalesColumns();
        $this->addStockDocumentSalesColumns();
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sale_id');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sale_id');
            $table->unsignedBigInteger('attendance_id')->nullable(false)->change();
        });

        Schema::table('receivables', function (Blueprint $table): void {
            $table->dropUnique(['sale_id']);
            $table->dropIndex(['company_id', 'sale_id']);
            $table->dropConstrainedForeignId('sale_id');
            $table->unsignedBigInteger('attendance_id')->nullable(false)->change();
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });

        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_company_barcode_unique');
            $table->dropIndex(['company_id', 'is_sellable']);
            $table->dropColumn(['sale_price', 'barcode', 'is_sellable']);
        });
    }

    protected function addProductSalesColumns(): void
    {
        if (! Schema::hasColumn('products', 'sale_price')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->decimal('sale_price', 12, 2)->default(0)->after('reference_unit_cost');
            });
        }

        if (! Schema::hasColumn('products', 'barcode')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('barcode')->nullable()->after('sku');
            });
        }

        if (! Schema::hasColumn('products', 'is_sellable')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('is_sellable')->default(false)->after('tracks_stock');
            });
        }

        Schema::table('products', function (Blueprint $table): void {
            if (! $this->indexExists('products', 'products_company_id_is_sellable_index')) {
                $table->index(['company_id', 'is_sellable']);
            }

            if (! $this->indexExists('products', 'products_company_barcode_unique')) {
                $table->unique(['company_id', 'barcode'], 'products_company_barcode_unique');
            }
        });
    }

    protected function createSalesTable(): void
    {
        if (Schema::hasTable('sales')) {
            return;
        }

        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft');
            $table->string('origin')->default('pos');
            $table->string('reference_key')->nullable();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('final_amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('outstanding_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sold_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'origin']);
            $table->index(['company_id', 'sold_at']);
            $table->index(['company_id', 'client_id']);
            $table->unique(['company_id', 'reference_key'], 'sales_company_ref_key_unique');
        });
    }

    protected function createSaleItemsTable(): void
    {
        if (Schema::hasTable('sale_items')) {
            return;
        }

        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('item_type');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_snapshot');
            $table->decimal('quantity', 14, 4)->default(1);
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->decimal('unit_cost_snapshot', 14, 6)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->boolean('tracks_stock_snapshot')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('sale_id');
            $table->index('product_id');
            $table->index('service_id');
            $table->index('attendance_id');
            $table->index(['company_id', 'item_type']);
        });
    }

    protected function addReceivableSalesColumns(): void
    {
        if (! Schema::hasColumn('receivables', 'sale_id')) {
            Schema::table('receivables', function (Blueprint $table): void {
                $table->foreignId('sale_id')->nullable()->after('attendance_id')->constrained()->cascadeOnDelete();
            });
        }

        Schema::table('receivables', function (Blueprint $table): void {
            $table->unsignedBigInteger('attendance_id')->nullable()->change();
            $table->unsignedBigInteger('client_id')->nullable()->change();

            if (! $this->indexExists('receivables', 'receivables_sale_id_unique')) {
                $table->unique('sale_id');
            }

            if (! $this->indexExists('receivables', 'receivables_company_id_sale_id_index')) {
                $table->index(['company_id', 'sale_id']);
            }
        });
    }

    protected function addPaymentSalesColumns(): void
    {
        if (! Schema::hasColumn('payments', 'sale_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->foreignId('sale_id')->nullable()->after('attendance_id')->constrained()->cascadeOnDelete();
            });
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('attendance_id')->nullable()->change();
        });
    }

    protected function addStockDocumentSalesColumns(): void
    {
        if (Schema::hasColumn('stock_documents', 'sale_id')) {
            return;
        }

        Schema::table('stock_documents', function (Blueprint $table): void {
            $table->foreignId('sale_id')->nullable()->after('attendance_id')->constrained()->nullOnDelete();
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn (object $row): bool => ($row->name ?? null) === $index);
        }

        return collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]))->isNotEmpty();
    }
};
