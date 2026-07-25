@props([
    'steps' => [],
    'current' => '',
])

@if (count($steps) > 0)
    @php
        $stepKeys = collect($steps)->pluck('key')->all();
        $currentIndex = array_search($current, $stepKeys, true);
        $currentIndex = $currentIndex === false ? 0 : $currentIndex;
        $total = max(count($steps), 1);
        $progress = (($currentIndex + 1) / $total) * 100;
        $currentLabel = $steps[$currentIndex]['label'] ?? '';
    @endphp

    <div class="booking-progress" aria-label="Progresso do agendamento">
        <div class="booking-progress__meta">
            <span class="booking-progress__step">
                Etapa {{ $currentIndex + 1 }} de {{ $total }}
            </span>
            <span class="booking-progress__current">{{ e($currentLabel) }}</span>
        </div>

        <div class="booking-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $progress }}">
            <span class="booking-progress__fill" style="width: {{ $progress }}%"></span>
        </div>
    </div>
@endif
