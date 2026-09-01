<?php

use App\Enums\CompanyModule;
use App\Filament\App\Pages\CalendarPage;
use App\Filament\App\Pages\CashFlowPage;
use App\Filament\App\Pages\CashPage;
use App\Filament\App\Pages\ExpenseByCategoryReportPage;
use App\Filament\App\Pages\FinancialDashboard;
use App\Filament\App\Pages\FinancialOverviewPage;
use App\Filament\App\Pages\FinancialSettingsPage;
use App\Filament\App\Pages\IncomeExpenseReportPage;
use App\Filament\App\Pages\InventoryPosition;
use App\Filament\App\Pages\ManagerialDrePage;
use App\Filament\App\Pages\RegisterExpensePage;
use App\Filament\App\Pages\SchedulingSettingsPage;
use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Filament\App\Resources\Attendances\AttendanceResource;
use App\Filament\App\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Filament\App\Resources\FinancialAccounts\FinancialAccountResource;
use App\Filament\App\Resources\FinancialTransactions\FinancialTransactionResource;
use App\Filament\App\Resources\Payables\PayableResource;
use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\App\Resources\Purchases\PurchaseResource;
use App\Filament\App\Resources\Receivables\ReceivableResource;
use App\Filament\App\Resources\ScheduleBlocks\ScheduleBlockResource;
use App\Filament\App\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\App\Resources\StockMovements\StockMovementResource;
use App\Filament\App\Resources\Suppliers\SupplierResource;
use App\Filament\App\Resources\Transfers\TransferResource;

return [
    CompanyModule::Scheduling->value => [
        CalendarPage::class,
        SchedulingSettingsPage::class,
        AppointmentResource::class,
        ScheduleBlockResource::class,
        AttendanceResource::class,
    ],

    CompanyModule::Stock->value => [
        InventoryPosition::class,
        ProductResource::class,
        SupplierResource::class,
        PurchaseResource::class,
        StockAdjustmentResource::class,
        StockMovementResource::class,
    ],

    CompanyModule::Finance->value => [
        FinancialOverviewPage::class,
        CashFlowPage::class,
        RegisterExpensePage::class,
        FinancialDashboard::class,
        CashPage::class,
        ManagerialDrePage::class,
        ExpenseByCategoryReportPage::class,
        IncomeExpenseReportPage::class,
        FinancialSettingsPage::class,
        ReceivableResource::class,
        PayableResource::class,
        FinancialTransactionResource::class,
        TransferResource::class,
        FinancialAccountResource::class,
        ExpenseCategoryResource::class,
    ],
];
