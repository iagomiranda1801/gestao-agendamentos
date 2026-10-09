<?php

namespace App\Filament\Admin\Resources\SegmentSettings\Pages;

use App\Filament\Admin\Resources\SegmentSettings\SegmentSettingResource;
use App\Support\Segment;
use Filament\Resources\Pages\EditRecord;

class EditSegmentSetting extends EditRecord
{
    protected static string $resource = SegmentSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $segment = (string) $this->getRecord()->segment;

        $data['name'] = filled($data['name'] ?? null) ? $data['name'] : Segment::get($segment, 'name');
        $data['tagline'] = filled($data['tagline'] ?? null) ? $data['tagline'] : Segment::get($segment, 'tagline');
        $data['primary_color'] = filled($data['primary_color'] ?? null) ? $data['primary_color'] : Segment::themeColor($segment);
        $data['logo_height'] = filled($data['logo_height'] ?? null) ? $data['logo_height'] : Segment::get($segment, 'logo_height');
        $data['login'] = Segment::get($segment, 'login', []);

        return $data;
    }
}
