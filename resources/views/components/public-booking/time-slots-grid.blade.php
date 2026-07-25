@props([
    'slots',
    'selectedTime' => null,
    'action' => 'selectTime',
])

<div {{ $attributes->class(['booking-slots-grid']) }}>
    @foreach ($slots as $slot)
        <button
            type="button"
            wire:key="slot-{{ $slot->format('H:i') }}"
            @class([
                'booking-slot-btn',
                'booking-slot-btn--selected' => $selectedTime === $slot->format('H:i'),
            ])
            wire:click="{{ $action }}('{{ $slot->format('H:i') }}')"
        >
            {{ $slot->format('H:i') }}
        </button>
    @endforeach
</div>
