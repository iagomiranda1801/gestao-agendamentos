<div class="agendaqui-auth-root">
    <div class="agendaqui-login-shell">
        <section class="agendaqui-showcase" aria-label="{{ \App\Support\Branding::name() }}">
            <img
                src="{{ \App\Support\Branding::loginShowcaseImageUrl() }}"
                alt=""
                aria-hidden="true"
                class="agendaqui-showcase-background"
            >

            <div class="agendaqui-showcase-overlay" aria-hidden="true"></div>

            <div class="agendaqui-showcase-brand">
                <img
                    src="{{ asset('images/agendaqui-login-logo.png') }}"
                    alt="{{ \App\Support\Branding::name() }}"
                    class="agendaqui-showcase-brand__logo"
                >
            </div>

            <div class="agendaqui-showcase-content">
                <div class="agendaqui-marketing-copy">
                    <h1 class="agendaqui-showcase-headline">
                        Gestão inteligente<br>
                        <span class="agendaqui-showcase-headline-accent">para o seu negócio</span>
                    </h1>

                    <p class="agendaqui-showcase-subtitle">
                        Agendamentos, clientes, estoque e financeiro em uma única plataforma.
                    </p>

                    <div class="agendaqui-features">
                        <x-agendaqui.auth-feature
                            title="Agenda inteligente"
                            description="Organize horários, profissionais e atendimentos com facilidade."
                        >
                            <x-slot:icon>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M4.5 9.75h15M4.5 19.5h15a1.5 1.5 0 0 0 1.5-1.5V6.75A1.5 1.5 0 0 0 19.5 5.25h-15A1.5 1.5 0 0 0 3 6.75v11.25A1.5 1.5 0 0 0 4.5 19.5Z" />
                                </svg>
                            </x-slot:icon>
                        </x-agendaqui.auth-feature>

                        <x-agendaqui.auth-feature
                            title="Gestão financeira"
                            description="Controle receitas, despesas e resultados em tempo real."
                        >
                            <x-slot:icon>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </x-slot:icon>
                        </x-agendaqui.auth-feature>

                        <x-agendaqui.auth-feature
                            title="Controle de estoque"
                            description="Acompanhe entradas, saídas e materiais utilizados."
                        >
                            <x-slot:icon>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                                </svg>
                            </x-slot:icon>
                        </x-agendaqui.auth-feature>

                        <x-agendaqui.auth-feature
                            title="Atendimento organizado"
                            description="Centralize clientes, histórico e informações importantes."
                        >
                            <x-slot:icon>
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                                </svg>
                            </x-slot:icon>
                        </x-agendaqui.auth-feature>
                    </div>
                </div>
            </div>
        </section>

        <section class="agendaqui-login-panel">
            <div class="agendaqui-login-panel__glow" aria-hidden="true"></div>

            <header class="agendaqui-auth-mobile-header">
                <x-branding.logo class="agendaqui-auth-mobile-header__logo" />
                <p class="agendaqui-auth-mobile-header__title">Gestão inteligente para o seu negócio</p>
            </header>

            <div class="agendaqui-auth-card">
                <header class="agendaqui-auth-card__header">
                    <x-branding.logo class="agendaqui-auth-card__logo" />
                    <h2 class="agendaqui-auth-card__title">Bem-vindo de volta</h2>
                    <p class="agendaqui-auth-card__subtitle">
                        Acesse sua conta e continue gerenciando seu negócio.
                    </p>
                </header>

                <div class="agendaqui-auth-card__form">
                    {{ $this->content }}
                </div>

                <footer class="agendaqui-auth-card__security">
                    <div class="agendaqui-auth-card__security-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                        </svg>
                    </div>
                    <div>
                        <strong class="agendaqui-auth-card__security-title">Plataforma segura</strong>
                        <p class="agendaqui-auth-card__security-text">
                            Seus dados são protegidos com segurança e boas práticas de acesso.
                        </p>
                    </div>
                </footer>
            </div>
        </section>
    </div>

    <x-filament-actions::modals />
</div>
