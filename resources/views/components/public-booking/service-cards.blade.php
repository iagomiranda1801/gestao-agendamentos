@props([
    'services',
    'selectedId' => null,
    'showPrice' => true,
    'showDuration' => true,
])

<div {{ $attributes->class(['booking-service-grid']) }}>
    @foreach ($services as $service)
        @php
            $isSelected = (int) $selectedId === (int) $service->id;
        @endphp

        <button
            type="button"
            wire:key="service-{{ $service->id }}"
            @class([
                'booking-service-card',
                'booking-service-card--selected' => $isSelected,
            ])
            wire:click="selectService({{ $service->id }})"
            wire:loading.class="booking-service-card--loading"
            wire:target="selectService({{ $service->id }})"
            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
        >
            @if ($isSelected)
                <span
                    class="booking-service-card__check"
                    wire:loading.remove
                    wire:target="selectService({{ $service->id }})"
                    aria-hidden="true"
                >✓</span>
            @endif

            <span
                class="booking-service-card__spinner"
                wire:loading
                wire:target="selectService({{ $service->id }})"
                aria-hidden="true"
            ></span>

            <h3 class="booking-service-card__name">{{ e($service->name) }}</h3>

            @if (filled($service->description))
                <p class="booking-service-card__description">{{ e($service->description) }}</p>
            @endif

            @if ($showPrice || $showDuration)
                <div class="booking-service-card__meta">
                    @if ($showPrice)
                        <span class="booking-service-card__price">
                            R$ {{ number_format((float) $service->price, 2, ',', '.') }}
                        </span>
                    @endif

                    @if ($showDuration)
                        <span>{{ e((string) $service->duration_minutes) }} min</span>
                    @endif
                </div>
            @endif
        </button>
    @endforeach
</div>
