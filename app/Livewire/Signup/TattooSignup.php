<?php

namespace App\Livewire\Signup;

use App\Filament\App\Pages\Dashboard;
use App\Services\Company\CompanySubscriptionService;
use App\Services\Company\TattooSignupService;
use App\Support\Segment;
use App\Support\TattooLaunchPlan;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tattoo-signup')]
class TattooSignup extends Component
{
    public string $companyName = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public function mount(): void
    {
        abort_unless(Segment::current() === 'tattoo', 404);
    }

    public function submit(TattooSignupService $signup): void
    {
        abort_unless(Segment::current() === 'tattoo', 404);

        if (Auth::check()) {
            $this->addError('signup', 'Você já está conectado. Acesse seu estúdio ou saia para criar outra conta.');

            return;
        }

        $key = 'tattoflow-signup:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('signup', 'Muitas tentativas. Aguarde alguns minutos e tente novamente.');

            return;
        }
        RateLimiter::hit($key, 300);

        $this->companyName = trim($this->companyName);
        $this->name = trim($this->name);
        $this->email = mb_strtolower(trim($this->email));
        $this->phone = preg_replace('/\D/', '', $this->phone) ?? '';

        $this->validate([
            'companyName' => ['required', 'string', 'min:2', 'max:120'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'regex:/^(?:55)?[1-9][0-9]{9,10}$/'],
            'password' => ['required', 'string', 'max:128', Password::min(8)],
        ], [
            'phone.regex' => 'Informe um WhatsApp válido com DDD.',
            'email.unique' => 'Este e-mail já tem uma conta. Entre no seu estúdio.',
        ], [
            'companyName' => 'nome do estúdio', 'name' => 'seu nome',
            'phone' => 'WhatsApp', 'password' => 'senha', 'email' => 'e-mail',
        ]);

        $result = $signup->register([
            'company_name' => $this->companyName,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'password' => $this->password,
        ]);

        $this->reset('password');
        Auth::login($result['user']);
        session()->regenerate();

        $panelId = Segment::panelId('tattoo');
        Filament::setCurrentPanel($panelId);
        Filament::setTenant($result['company'], isQuiet: true);

        $this->redirect(Dashboard::getUrl(['tenant' => $result['company']], panel: $panelId));
    }

    public function render()
    {
        return view('livewire.signup.tattoo-signup', [
            'price' => app(CompanySubscriptionService::class)->formatReais(TattooLaunchPlan::priceCents()),
        ]);
    }
}
