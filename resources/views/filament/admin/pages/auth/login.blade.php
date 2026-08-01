<div class="admin-auth-root">
    <section class="admin-auth-brand" aria-label="Agendaqui">
        <div class="admin-auth-brand__content">
            <img
                src="{{ asset('images/agendaqui-login-logo.png') }}"
                alt="Agendaqui"
                class="admin-auth-brand__logo"
            >
            <p class="admin-auth-brand__eyebrow">Painel administrativo</p>
            <h1 class="admin-auth-brand__title">Controle claro para decisões melhores.</h1>
            <p class="admin-auth-brand__text">Acompanhe empresas, usuários e a operação da plataforma em um só lugar.</p>
        </div>
    </section>

    <section class="admin-auth-form-panel">
        <div class="admin-auth-form-wrap">
            <div class="admin-auth-mobile-brand">
                <img src="{{ asset('images/agendaqui-login-logo.png') }}" alt="Agendaqui" class="admin-auth-mobile-brand__logo">
            </div>

            <header class="admin-auth-form-header">
                <p class="admin-auth-form-header__eyebrow">Bem-vindo de volta</p>
                <h2 class="admin-auth-form-header__title">Entrar no painel</h2>
                <p class="admin-auth-form-header__text">Use sua conta administrativa para continuar.</p>
            </header>

            <div class="admin-auth-form">
                {{ $this->content }}
            </div>

            <p class="admin-auth-form-footer">Agendaqui · IM Soluções Digitais</p>
        </div>
    </section>

    <x-filament-actions::modals />
</div>
