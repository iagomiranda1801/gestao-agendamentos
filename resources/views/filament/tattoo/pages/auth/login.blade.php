@php
    $brand = $this->brand();
    $content = $this->loginContent();
@endphp

<div class="tattoo-auth">
    <aside class="tattoo-auth__story" aria-label="{{ $brand['name'] }}">
        <div class="tattoo-auth__story-veil" aria-hidden="true"></div>

        @if ($content['image'])
            <img src="{{ $content['image'] }}" alt="" aria-hidden="true" class="tattoo-auth__story-image">
        @endif

        <div class="tattoo-auth__story-copy">
            <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" class="tattoo-auth__logo">

            @if (filled($content['eyebrow'] ?? null))
                <span class="tattoo-auth__eyebrow">{{ $content['eyebrow'] }}</span>
            @endif

            <h1 class="tattoo-auth__headline">
                {{ $content['headline'] ?? '' }}
                @if (filled($content['headline_accent'] ?? null))
                    <em>{{ $content['headline_accent'] }}</em>
                @endif
            </h1>

            @if (filled($content['subtitle'] ?? null))
                <p class="tattoo-auth__subtitle">{{ $content['subtitle'] }}</p>
            @endif

            @if ($content['highlights'] !== [])
                <ul class="tattoo-auth__highlights">
                    @foreach ($content['highlights'] as $highlight)
                        <li>
                            <strong>{{ $highlight['title'] ?? '' }}</strong>
                            @if (filled($highlight['description'] ?? null))
                                <span>{{ $highlight['description'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </aside>

    <section class="tattoo-auth__panel" aria-labelledby="tattoo-auth-title">
        <p class="tattoo-auth__panel-tagline">{{ $brand['tagline'] }}</p>
        <h2 id="tattoo-auth-title" class="tattoo-auth__panel-title">{{ $content['form_title'] ?? 'Entrar' }}</h2>

        @if (filled($content['form_subtitle'] ?? null))
            <p class="tattoo-auth__panel-subtitle">{{ $content['form_subtitle'] }}</p>
        @endif

        <div class="tattoo-auth__form">
            {{ $this->content }}
        </div>

        <div class="tattoo-auth__signup">
            <span>{{ $content['signup_text'] ?? 'Ainda não tem conta?' }}</span>
            <a href="{{ route('signup.company') }}">{{ $content['signup_link'] ?? 'Criar conta' }}</a>
        </div>
    </section>

    <x-filament-actions::modals />
</div>
