<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0C0B0A">
        <title>Crie seu estúdio — TattoFlow</title>
        <link rel="icon" href="{{ asset('images/tattoflow/08_Favicon.ico') }}">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=cormorant-garamond:500,600|outfit:300,400,500" rel="stylesheet">
        @vite('resources/css/landing.css')
        @livewireStyles
    </head>
    <body>
        <header class="site-header signup-page-header">
            <a class="wordmark" href="/" aria-label="TattoFlow — início"><img src="{{ asset('images/tattoflow/01_Logo_Principal_Fundo_Preto.png') }}" alt="TattoFlow" width="1460" height="708"></a>
            <a class="header-login" href="/painel/login">Já tenho uma conta ↗</a>
        </header>
        <main>{{ $slot }}</main>
        @livewireScripts
    </body>
</html>
