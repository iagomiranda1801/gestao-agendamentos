<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Cadastro — {{ \App\Support\Branding::name() }}</title>

        <link rel="icon" type="image/png" href="{{ \App\Support\Branding::faviconUrl() }}">

        @fonts

        @vite(['resources/css/signup.css'])

        @livewireStyles
    </head>
    <body class="signup-body">
        <div class="signup-shell">
            <header class="signup-header">
                <div class="signup-brand">
                    <x-branding.logo class="signup-brand__logo" />
                    <span class="signup-brand__tagline">{{ \App\Support\Branding::tagline() }}</span>
                </div>
            </header>

            <main class="signup-main">
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
</html>
