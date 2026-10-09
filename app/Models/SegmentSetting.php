<?php

namespace App\Models;

use App\Support\Segment;
use Database\Factories\SegmentSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'segment',
    'name',
    'tagline',
    'primary_color',
    'logo_path',
    'favicon_path',
    'login_image_path',
    'logo_height',
    'login',
])]
class SegmentSetting extends Model
{
    /** @use HasFactory<SegmentSettingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'login' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Segment::flush());
        static::deleted(fn () => Segment::flush());
    }

    public function label(): string
    {
        return (string) config("segments.{$this->segment}.label", $this->segment);
    }

    /**
     * @return array<string, mixed>
     */
    public function overlay(): array
    {
        return [
            'name' => $this->name,
            'tagline' => $this->tagline,
            'primary_color' => $this->primary_color,
            'logo' => $this->logo_path,
            'favicon' => $this->favicon_path,
            'logo_height' => $this->logo_height,
            'login_image_path' => $this->login_image_path,
            'login' => $this->login ?? [],
        ];
    }

    public static function syncMissing(): void
    {
        foreach (array_keys((array) config('segments', [])) as $segment) {
            static::query()->firstOrCreate(['segment' => $segment]);
        }
    }
}
