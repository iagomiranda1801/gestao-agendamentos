<?php

namespace App\Filament\App\Resources\ScheduleBlocks\Pages;

use App\Filament\App\Resources\ScheduleBlocks\ScheduleBlockResource;
use App\Models\Company;
use App\Models\ScheduleBlock;
use App\Services\Scheduling\ScheduleBlockService;
use App\Support\CompanyDateTime;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditScheduleBlock extends EditRecord
{
    protected static string $resource = ScheduleBlockResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        /** @var ScheduleBlock $record */
        $record = $this->getRecord();

        $localStart = CompanyDateTime::utcToLocal($company, $record->start_at);
        $localEnd = CompanyDateTime::utcToLocal($company, $record->end_at);

        $data['start_date'] = $localStart->toDateString();
        $data['start_time'] = $localStart->format('H:i');
        $data['end_date'] = $localEnd->toDateString();
        $data['end_time'] = $localEnd->format('H:i');

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($data['is_all_day'] ?? false) {
            $data['start_time'] = '00:01';
            $data['end_time'] = '23:59';
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        /** @var ScheduleBlock $record */
        return app(ScheduleBlockService::class)->update($company, $record, $data);
    }
}
