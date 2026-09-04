<?php

namespace App\Filament\App\Resources\ScheduleBlocks\Pages;

use App\Filament\App\Resources\ScheduleBlocks\ScheduleBlockResource;
use App\Models\Company;
use App\Services\Scheduling\ScheduleBlockService;
use Filament\Facades\Filament;
use App\Filament\App\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateScheduleBlock extends CreateRecord
{
    protected static string $resource = ScheduleBlockResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        if ($data['is_all_day'] ?? false) {
            $data['start_time'] = '00:01';
            $data['end_time'] = '23:59';
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(ScheduleBlockService::class)->create($company, auth()->user(), $data);
    }
}
