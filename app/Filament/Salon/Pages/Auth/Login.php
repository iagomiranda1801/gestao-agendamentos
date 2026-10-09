<?php

namespace App\Filament\Salon\Pages\Auth;

use App\Filament\App\Pages\Auth\Login as AppLogin;
use App\Support\Segment;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;

class Login extends AppLogin
{
    protected string $view = 'filament.salon.pages.auth.login';

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->extraAttributes(['class' => 'salon-auth-input']);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->extraAttributes(['class' => 'salon-auth-input']);
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()
            ->extraAttributes(['class' => 'salon-auth-submit']);
    }

    /**
     * @return array<string, mixed>
     */
    public function brand(): array
    {
        return [
            'name' => (string) Segment::get('salon', 'name'),
            'tagline' => (string) Segment::get('salon', 'tagline'),
            'logo' => asset((string) Segment::get('salon', 'logo')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loginContent(): array
    {
        $content = (array) Segment::get('salon', 'login', []);
        $content['image'] = filled($content['image'] ?? null) ? asset((string) $content['image']) : null;
        $content['highlights'] = array_values((array) ($content['highlights'] ?? []));

        return $content;
    }
}
