@php
    $segment = \App\Support\Segment::current();
@endphp

@if ($segment)
    @php
        $color = \App\Support\Segment::themeColor($segment);
        $prefix = $segment === 'tattoo' ? 'tattoo' : $segment;
    @endphp
    <style>
        :root {
            --segment-primary: {{ $color }};
            --{{ $prefix }}-primary: {{ $color }};
            --{{ $prefix }}-primary-strong: color-mix(in srgb, {{ $color }} 72%, #000);
            --{{ $prefix }}-primary-soft: color-mix(in srgb, {{ $color }} 18%, {{ $segment === 'tattoo' ? '#14110d' : '#fff' }});
            --dashboard-action-border: color-mix(in srgb, {{ $color }} 28%, {{ $segment === 'tattoo' ? '#1c1814' : '#fff' }});
            --dashboard-action-bg: color-mix(in srgb, {{ $color }} 16%, {{ $segment === 'tattoo' ? '#161310' : '#fff' }});
            --dashboard-action-border-hover: color-mix(in srgb, {{ $color }} 45%, {{ $segment === 'tattoo' ? '#1c1814' : '#fff' }});
            --dashboard-action-bg-hover: color-mix(in srgb, {{ $color }} 22%, {{ $segment === 'tattoo' ? '#161310' : '#fff' }});
            --dashboard-action-icon: {{ $color }};
            --dashboard-tone-primary-border: color-mix(in srgb, {{ $color }} 28%, {{ $segment === 'tattoo' ? '#1c1814' : '#fff' }});
            --dashboard-tone-primary-bg: color-mix(in srgb, {{ $color }} 16%, {{ $segment === 'tattoo' ? '#161310' : '#fff' }});
            --dashboard-tone-primary-text: {{ $color }};
        }
    </style>
@endif
