@php
    $brand = $this->brand();
    $content = $this->loginContent();
@endphp

<div class="tattoo-auth">
    <div class="tattoo-auth__backdrop" aria-hidden="true">
        <span class="tattoo-auth__blob tattoo-auth__blob--one"></span>
        <span class="tattoo-auth__blob tattoo-auth__blob--two"></span>
    </div>

    <header class="tattoo-auth__topbar">
        @if (filled($brand['logo']))
            <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" class="tattoo-auth__logo" onerror="this.style.display='none'; this.nextElementSibling.hidden=false">
            <span class="tattoo-auth__wordmark" hidden>{{ $brand['name'] }}</span>
        @else
            <span class="tattoo-auth__wordmark">{{ $brand['name'] }}</span>
        @endif
        <span class="tattoo-auth__tagline">{{ $brand['tagline'] }}</span>
    </header>

    <main class="tattoo-auth__stage">
        <section class="tattoo-auth__card" aria-labelledby="tattoo-auth-title">
            <h1 id="tattoo-auth-title" class="tattoo-auth__card-title">{{ $content['form_title'] ?? 'Entrar' }}</h1>

            @if (filled($content['form_subtitle'] ?? null))
                <p class="tattoo-auth__card-subtitle">{{ $content['form_subtitle'] }}</p>
            @endif

            <div class="tattoo-auth__form">
                {{ $this->content }}
            </div>

            <div class="tattoo-auth__signup">
                <span>{{ $content['signup_text'] ?? 'Ainda não tem conta?' }}</span>
                <a href="{{ route('signup.company') }}">{{ $content['signup_link'] ?? 'Criar conta' }}</a>
            </div>
        </section>

        <section class="tattoo-auth__story" aria-label="{{ $brand['name'] }}">
            @if ($content['image'])
                <img src="{{ $content['image'] }}" alt="" aria-hidden="true" class="tattoo-auth__story-image">
            @endif

            @if (filled($content['eyebrow'] ?? null))
                <span class="tattoo-auth__eyebrow">{{ $content['eyebrow'] }}</span>
            @endif

            <h2 class="tattoo-auth__headline">
                {{ $content['headline'] ?? '' }}
                @if (filled($content['headline_accent'] ?? null))
                    <em>{{ $content['headline_accent'] }}</em>
                @endif
            </h2>

            @if (filled($content['subtitle'] ?? null))
                <p class="tattoo-auth__subtitle">{{ $content['subtitle'] }}</p>
            @endif

            @if ($content['highlights'] !== [])
                <ol class="tattoo-auth__highlights">
                    @foreach ($content['highlights'] as $highlight)
                        <li class="tattoo-auth__highlight">
                            <span class="tattoo-auth__highlight-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
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
