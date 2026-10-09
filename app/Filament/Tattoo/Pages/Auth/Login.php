<?php

namespace App\Filament\Tattoo\Pages\Auth;

use App\Filament\App\Pages\Auth\Login as AppLogin;
use App\Filament\Concerns\InteractsWithSegmentBrand;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;

class Login extends AppLogin
{
    use InteractsWithSegmentBrand;

    protected string $view = 'filament.tattoo.pages.auth.login';

    protected function segmentKey(): string
    {
        return 'tattoo';
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->extraAttributes(['class' => 'tattoo-auth-input']);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->extraAttributes(['class' => 'tattoo-auth-input']);
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()
            ->extraAttributes(['class' => 'tattoo-auth-submit']);
    }
}
