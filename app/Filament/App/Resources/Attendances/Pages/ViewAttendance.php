<?php

namespace App\Filament\App\Resources\Attendances\Pages;

use App\Filament\App\Resources\Attendances\AttendanceResource;
use App\Filament\App\Resources\Attendances\Schemas\AttendanceForm;
use App\Filament\App\Resources\Concerns\InteractsWithPaymentRegistration;
use App\Models\Attendance;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewAttendance extends ViewRecord
{
    use InteractsWithPaymentRegistration;

    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::makeRegisterPaymentAction(
                fn (): ?\App\Models\Receivable => $this->getRecord()->loadMissing('receivable')->receivable,
                fn (): bool => auth()->user()?->can('registerPayment', $this->getRecord()) ?? false,
            ),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return AttendanceForm::configure($schema, readOnly: true);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Attendance $record */
        $record = $this->getRecord();

        $data['notes'] = $record->notes;
        $data['internal_notes'] = $record->internal_notes;

        return $data;
    }
}
