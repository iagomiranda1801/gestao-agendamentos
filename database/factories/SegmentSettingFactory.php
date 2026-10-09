<?php

namespace Database\Factories;

use App\Models\SegmentSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SegmentSetting>
 */
class SegmentSettingFactory extends Factory
{
    protected $model = SegmentSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'segment' => 'salon',
            'name' => 'Agendaqui Beleza',
            'tagline' => 'Gestão para salões, estética e bem-estar',
            'primary_color' => '#b4426e',
            'logo_path' => null,
            'favicon_path' => null,
            'login_image_path' => null,
            'logo_height' => '2.75rem',
            'login' => [
                'form_title' => 'Que bom te ver de novo',
                'headline' => 'Sua agenda cheia,',
            ],
        ];
    }
}
