@if (filament()->getCurrentPanel()?->getId() === 'app')
    <div class="fi-app-login-signup">
        <p class="fi-app-login-signup__text">
            Ainda não tem uma conta?
        </p>
        <a href="{{ route('signup.company') }}" class="fi-app-login-signup__link">
            Criar conta — 7 dias grátis
        </a>
    </div>
@endif
