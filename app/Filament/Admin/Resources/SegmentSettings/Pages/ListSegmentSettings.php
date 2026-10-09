<?php

namespace App\Filament\Admin\Resources\SegmentSettings\Pages;

use App\Filament\Admin\Resources\SegmentSettings\SegmentSettingResource;
use App\Models\SegmentSetting;
use Filament\Resources\Pages\ListRecords;

class ListSegmentSettings extends ListRecords
{
    protected static string $resource = SegmentSettingResource::class;

    public function mount(): void
    {
        SegmentSetting::syncMissing();

        parent::mount();
    }
}
