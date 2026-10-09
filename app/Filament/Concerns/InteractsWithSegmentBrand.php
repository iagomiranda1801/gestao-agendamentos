<?php

namespace App\Filament\Concerns;

use App\Support\Segment;

trait InteractsWithSegmentBrand
{
    abstract protected function segmentKey(): string;

    /**
     * @return array<string, mixed>
     */
    public function brand(): array
    {
        $segment = $this->segmentKey();

        return [
            'name' => (string) Segment::get($segment, 'name'),
            'tagline' => (string) Segment::get($segment, 'tagline'),
            'logo' => Segment::media($segment, 'logo') ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loginContent(): array
    {
        $content = (array) Segment::get($this->segmentKey(), 'login', []);
        $content['image'] = Segment::mediaUrl($content['image'] ?? null);
        $content['highlights'] = array_values((array) ($content['highlights'] ?? []));

        return $content;
    }
}
