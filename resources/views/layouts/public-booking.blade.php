<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @stack('head')

        <link rel="icon" type="image/png" href="{{ \App\Support\Branding::faviconUrl() }}">

        @fonts

        @vite(['resources/css/public-booking.css'])

        @livewireStyles

        <style>
            :root {
                --booking-primary: {{ $primaryColor ?? '#2563eb' }};
                --booking-primary-hover: color-mix(in srgb, var(--booking-primary) 88%, black);
                --booking-primary-soft: color-mix(in srgb, var(--booking-primary) 12%, white);
                --booking-primary-ring: color-mix(in srgb, var(--booking-primary) 28%, transparent);
            }
        </style>
    </head>
    <body class="booking-body">
        <div class="booking-shell">
            <header class="booking-header">
                @if (isset($company))
                    <div class="booking-brand">
                        @if ($company->logoUrl())
                            <img
                                src="{{ e($company->logoUrl()) }}"
                                alt="{{ e($company->name) }}"
                                class="booking-brand__logo"
                            >
                        @else
                            <x-branding.logo class="booking-brand__logo booking-brand__logo--platform" />
                        @endif
                        <div class="booking-brand__text">
                            <span class="booking-brand__name">{{ e($company->name) }}</span>
                            <span class="booking-brand__tagline">Agendamento online</span>
                        </div>
                    </div>
                @endif
            </header>

            <main class="booking-main">
                {{ $slot }}
            </main>

            <footer class="booking-footer">
                <p class="booking-footer__text">
                    &copy; {{ date('Y') }} {{ e($company->name ?? config('app.name')) }}
                </p>
            </footer>
        </div>

        @livewireScripts
    </body>
</html>
