<?php

namespace Tests\Feature\Admin;

use App\Filament\Admin\Resources\SegmentSettings\Pages\EditSegmentSetting;
use App\Filament\Admin\Resources\SegmentSettings\Pages\ListSegmentSettings;
use App\Models\SegmentSetting;
use App\Support\Segment;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class SegmentSettingAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Filament::setCurrentPanel('admin');
    }

    public function test_admin_can_open_products_page(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->get(ListSegmentSettings::getUrl())
            ->assertOk()
            ->assertSee('Salão')
            ->assertSee('Tatuagem');
    }

    public function test_admin_can_change_tattoo_brand_and_login_shows_it(): void
    {
        $admin = $this->createSuperAdmin();
        $setting = SegmentSetting::query()->where('segment', 'tattoo')->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(EditSegmentSetting::class, ['record' => $setting->getKey()])
            ->fillForm([
                'name' => 'Ink House',
                'tagline' => 'Estúdio premium',
                'primary_color' => '#d4af37',
                'login' => [
                    'headline' => 'Marca escrita no admin',
                    'form_title' => 'Entrar no Ink House',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        Segment::flush();

        $this->assertSame('Ink House', Segment::get('tattoo', 'name'));
        $this->assertSame('#d4af37', Segment::themeColor('tattoo'));

        auth()->logout();

        $this->get('http://estudio.localhost/painel/login')
            ->assertOk()
            ->assertSee('Ink House')
            ->assertSee('Marca escrita no admin')
            ->assertSee('Entrar no Ink House');
    }
}
