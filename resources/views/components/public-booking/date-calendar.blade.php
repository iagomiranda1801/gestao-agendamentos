@props([
    'calendarMonth',
    'availableDateKeys' => [],
    'selectedDate' => null,
    'minDate',
    'maxDate',
])

@php
    $month = \Carbon\CarbonImmutable::parse($calendarMonth)->startOfMonth();
    $monthLabel = $month->locale('pt_BR')->translatedFormat('F Y');
    $weekdays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $availableKeys = collect($availableDateKeys);
    $min = \Carbon\CarbonImmutable::parse($minDate)->startOfDay();
    $max = \Carbon\CarbonImmutable::parse($maxDate)->endOfDay();
    $gridStart = $month->startOfWeek(\Carbon\CarbonInterface::SUNDAY);
    $gridEnd = $month->endOfMonth()->endOfWeek(\Carbon\CarbonInterface::SATURDAY);
    $days = [];
    $cursor = $gridStart;

    while ($cursor->lte($gridEnd)) {
        $days[] = $cursor;
        $cursor = $cursor->addDay();
    }

    $canGoPrevious = $month->copy()->subMonth()->startOfMonth()->gte($min->startOfMonth());
    $canGoNext = $month->copy()->addMonth()->startOfMonth()->lte($max->startOfMonth());
@endphp

<div {{ $attributes->class(['booking-calendar']) }}>
    <div class="booking-calendar__header">
        <button
            type="button"
            class="booking-calendar__nav"
            wire:click="previousCalendarMonth"
            @disabled(! $canGoPrevious)
            aria-label="Mês anterior"
        >
            ‹
        </button>

        <h2 class="booking-calendar__title">{{ ucfirst($monthLabel) }}</h2>

        <button
            type="button"
            class="booking-calendar__nav"
            wire:click="nextCalendarMonth"
            @disabled(! $canGoNext)
            aria-label="Próximo mês"
        >
            ›
        </button>
    </div>

    <div class="booking-calendar__weekdays">
        @foreach ($weekdays as $weekday)
            <span class="booking-calendar__weekday">{{ $weekday }}</span>
        @endforeach
    </div>

    <div class="booking-calendar__grid">
        @foreach ($days as $day)
            @php
                $dateKey = $day->format('Y-m-d');
                $isCurrentMonth = $day->month === $month->month;
                $isAvailable = $availableKeys->contains($dateKey);
                $isSelected = $selectedDate === $dateKey;
                $isDisabled = ! $isCurrentMonth || ! $isAvailable || $day->lt($min) || $day->gt($max);
            @endphp

            @if ($isDisabled)
                <span
                    @class([
                        'booking-calendar__day',
                        'booking-calendar__day--outside' => ! $isCurrentMonth,
                        'booking-calendar__day--disabled' => $isCurrentMonth,
                    ])
                    aria-hidden="true"
                >
                    {{ $day->day }}
                </span>
            @else
                <button
                    type="button"
                    wire:key="calendar-day-{{ $dateKey }}"
                    @class([
                        'booking-calendar__day',
                        'booking-calendar__day--available',
                        'booking-calendar__day--selected' => $isSelected,
                    ])
                    wire:click="selectCalendarDate('{{ $dateKey }}')"
                    aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                    aria-label="{{ $day->locale('pt_BR')->translatedFormat('d \d\e F \d\e Y') }}"
                >
                    {{ $day->day }}
                </button>
            @endif
        @endforeach
    </div>
</div>
