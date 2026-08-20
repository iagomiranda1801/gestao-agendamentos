@php
    $byTooth = collect($entries ?? [])->filter(fn ($entry) => filled($entry['tooth'] ?? null))->keyBy('tooth');
    $rows = [
        ['18','17','16','15','14','13','12','11','21','22','23','24','25','26','27','28'],
        ['55','54','53','52','51','61','62','63','64','65'],
        ['85','84','83','82','81','71','72','73','74','75'],
        ['48','47','46','45','44','43','42','41','31','32','33','34','35','36','37','38'],
    ];
    $colors = ['existing' => '#64748b', 'planned' => '#f59e0b', 'completed' => '#16a34a'];
    $labels = [
        'healthy' => 'Hígido', 'missing' => 'Ausente', 'extracted' => 'Extraído', 'caries' => 'Cárie',
        'restoration' => 'Restauração', 'crown' => 'Coroa', 'implant' => 'Implante', 'root_canal' => 'Canal tratado',
        'endodontic_indication' => 'Indicação endodôntica', 'fracture' => 'Fratura', 'prosthesis' => 'Prótese',
        'sealant' => 'Selante', 'note' => 'Observação',
    ];
@endphp

<div class="dental-chart" aria-label="Representação visual do odontograma">
    <div class="dental-chart__legend">
        <span><i style="background:#64748b"></i> Existente</span>
        <span><i style="background:#f59e0b"></i> Planejado</span>
        <span><i style="background:#16a34a"></i> Concluído</span>
    </div>

    @foreach ($rows as $index => $row)
        <div class="dental-chart__row {{ in_array($index, [1, 2], true) ? 'dental-chart__row--deciduous' : '' }}">
            @foreach ($row as $tooth)
                @php
                    $entry = $byTooth->get($tooth);
                    $stage = $entry['stage'] ?? null;
                    $condition = $entry['condition'] ?? null;
                    $title = $entry ? ($labels[$condition] ?? $condition).' — '.($entry['notes'] ?? '') : 'Sem marcação';
                @endphp
                <div
                    class="dental-chart__tooth {{ $entry ? 'dental-chart__tooth--marked' : '' }}"
                    style="--tooth-color: {{ $colors[$stage] ?? '#cbd5e1' }}"
                    title="Dente {{ $tooth }}: {{ $title }}"
                >
                    <span class="dental-chart__crown" aria-hidden="true"></span>
                    <strong>{{ $tooth }}</strong>
                    @if ($condition)
                        <small>{{ $labels[$condition] ?? $condition }}</small>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach

    <p class="dental-chart__hint">As marcações cadastradas abaixo são refletidas no diagrama por dente e situação.</p>
</div>

<style>
    .dental-chart { overflow-x:auto; padding:1rem; border:1px solid rgb(226 232 240); border-radius:.75rem; background:rgb(248 250 252) }
    .dark .dental-chart { border-color:rgb(51 65 85); background:rgb(15 23 42) }
    .dental-chart__legend { display:flex; flex-wrap:wrap; gap:1rem; margin-bottom:1rem; font-size:.8rem }
    .dental-chart__legend span { display:flex; align-items:center; gap:.35rem }
    .dental-chart__legend i { width:.75rem; height:.75rem; border-radius:9999px }
    .dental-chart__row { display:flex; justify-content:center; min-width:760px; gap:.35rem; margin:.45rem 0 }
    .dental-chart__row--deciduous { min-width:520px }
    .dental-chart__tooth { width:44px; min-height:64px; display:flex; flex-direction:column; align-items:center; gap:.2rem; color:rgb(71 85 105); font-size:.75rem }
    .dental-chart__crown { width:30px; height:34px; border:2px solid var(--tooth-color); border-radius:45% 45% 35% 35%; background:white; box-shadow:inset 0 -5px 0 color-mix(in srgb, var(--tooth-color) 20%, transparent) }
    .dark .dental-chart__crown { background:rgb(30 41 59) }
    .dental-chart__tooth--marked strong { color:var(--tooth-color) }
    .dental-chart__tooth small { max-width:54px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:.6rem }
    .dental-chart__hint { margin-top:1rem; text-align:center; color:rgb(100 116 139); font-size:.75rem }
</style>
