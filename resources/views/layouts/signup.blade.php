<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Cadastro — Agendaqui</title>

        @fonts

        @vite(['resources/css/signup.css'])

        @livewireStyles
    </head>
    <body class="signup-body">
        <div class="signup-shell">
            <header class="signup-header">
                <div class="signup-brand">
                    <span class="signup-brand__name">Agendaqui</span>
                    <span class="signup-brand__tagline">Gestão de agendamentos para o seu negócio</span>
                </div>
            </header>

            <main class="signup-main">
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
</html>
