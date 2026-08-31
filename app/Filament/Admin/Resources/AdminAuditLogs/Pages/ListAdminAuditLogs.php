<?php

namespace App\Filament\Admin\Resources\AdminAuditLogs\Pages;

use App\Filament\Admin\Resources\AdminAuditLogs\AdminAuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAdminAuditLogs extends ListRecords
{
    protected static string $resource = AdminAuditLogResource::class;
}
