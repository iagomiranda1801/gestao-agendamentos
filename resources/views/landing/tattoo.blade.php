<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>TattooFlow — Gestão para estúdios de tatuagem</title>
        <meta name="description" content="Do primeiro oi no WhatsApp ao pedido pronto para orçar. Organize referências, orçamentos e agendamentos do seu estúdio com TattooFlow.">
        <meta name="theme-color" content="#0C0B0A">
        <meta property="og:type" content="website">
        <meta property="og:title" content="TattooFlow — Mais tempo para a sua arte.">
        <meta property="og:description" content="Atendimento no WhatsApp, pedidos organizados e orçamentos nas mãos da sua equipe.">
        <meta property="og:image" content="{{ asset('images/tattooflow/01_Logo_Principal_Fundo_Preto.png') }}">
        <meta property="og:image:width" content="1045">
        <meta property="og:image:height" content="395">
        <meta property="og:image:alt" content="TattooFlow — identidade dourada sobre fundo preto">
        <link rel="icon" href="{{ asset('images/tattooflow/09_Favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="256x256" href="{{ asset('images/tattooflow/07_Favicon_256.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/tattooflow/05_Icone_App_Fundo_Preto.png') }}">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=cormorant-garamond:500,600|outfit:300,400,500" rel="stylesheet">
        @vite(['resources/css/landing.css', 'resources/js/landing/main.jsx'])
        @livewireStyles
    </head>
    <body>
        <a class="launch-announcement" href="#cadastro"><span>LANÇAMENTO TATTOOFLOW</span> Seu estúdio completo por <strong>{{ app(\App\Services\Company\CompanySubscriptionService::class)->formatReais(\App\Support\TattooLaunchPlan::priceCents()) }}/mês</strong><span class="announcement-trial"> · 7 dias grátis ↗</span></a>
        <div id="landing">
            <h1>O cliente manda a ideia no WhatsApp. O pedido chega pronto para orçar.</h1>
            <a href="/painel/login">Entrar</a>
        </div>
        <livewire:signup.tattoo-signup />
        <footer class="site-footer">
            <a href="#inicio" aria-label="TattooFlow — voltar ao início">
                <img src="{{ asset('images/tattooflow/01_Logo_Principal_Fundo_Preto.png') }}" alt="TattooFlow" width="1045" height="395" loading="lazy">
            </a>
            <p>Gestão para estúdios de tatuagem.<br>Mais tempo para a sua arte.</p>
            <a class="text-link" href="/painel/login">Acessar meu estúdio ↗</a>
        </footer>
        @livewireScripts
    </body>
</html>
