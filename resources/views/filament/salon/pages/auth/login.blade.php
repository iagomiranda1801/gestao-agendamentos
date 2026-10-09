@php
    $brand = $this->brand();
    $content = $this->loginContent();
@endphp

<div class="salon-auth">
    <div class="salon-auth__backdrop" aria-hidden="true">
        <span class="salon-auth__blob salon-auth__blob--one"></span>
        <span class="salon-auth__blob salon-auth__blob--two"></span>
        <span class="salon-auth__blob salon-auth__blob--three"></span>
    </div>

    <header class="salon-auth__topbar">
        <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" class="salon-auth__logo">
        <span class="salon-auth__tagline">{{ $brand['tagline'] }}</span>
    </header>

    <main class="salon-auth__stage">
        <section class="salon-auth__card" aria-labelledby="salon-auth-title">
            <h1 id="salon-auth-title" class="salon-auth__card-title">{{ $content['form_title'] ?? 'Entrar' }}</h1>

            @if (filled($content['form_subtitle'] ?? null))
                <p class="salon-auth__card-subtitle">{{ $content['form_subtitle'] }}</p>
            @endif

            <div class="salon-auth__form">
                {{ $this->content }}
            </div>

            <div class="salon-auth__signup">
                <span>{{ $content['signup_text'] ?? 'Ainda não tem conta?' }}</span>
                <a href="{{ route('signup.company') }}">{{ $content['signup_link'] ?? 'Criar conta' }}</a>
            </div>
        </section>

        <section class="salon-auth__story" aria-label="{{ $brand['name'] }}">
            @if ($content['image'])
                <img src="{{ $content['image'] }}" alt="" aria-hidden="true" class="salon-auth__story-image">
            @endif

            @if (filled($content['eyebrow'] ?? null))
                <span class="salon-auth__eyebrow">{{ $content['eyebrow'] }}</span>
            @endif

            <h2 class="salon-auth__headline">
                {{ $content['headline'] ?? '' }}
                @if (filled($content['headline_accent'] ?? null))
                    <em>{{ $content['headline_accent'] }}</em>
                @endif
            </h2>

            @if (filled($content['subtitle'] ?? null))
                <p class="salon-auth__subtitle">{{ $content['subtitle'] }}</p>
            @endif

            @if ($content['highlights'] !== [])
                <ol class="salon-auth__highlights">
                    @foreach ($content['highlights'] as $highlight)
                        <li class="salon-auth__highlight">
                            <span class="salon-auth__highlight-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <div>
                                <strong>{{ $highlight['title'] ?? '' }}</strong>
                                @if (filled($highlight['description'] ?? null))
                                    <p>{{ $highlight['description'] }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </main>

    <x-filament-actions::modals />
</div>
