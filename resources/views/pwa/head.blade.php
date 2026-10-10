@php
    $pwa = \App\Support\Pwa::head($company ?? null, $surface ?? 'panel');
@endphp

<link rel="manifest" href="{{ $pwa['manifest'] }}">
<meta name="theme-color" content="{{ $pwa['themeColor'] }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $pwa['name'] }}">
<link rel="apple-touch-icon" href="{{ $pwa['appleTouchIcon'] }}">
<script>
    if ('serviceWorker' in navigator) {
        const host = location.hostname;
        const allowed = location.protocol === 'https:' || host === 'localhost' || host.endsWith('.localhost');

        if (allowed) {
            navigator.serviceWorker.register('/sw.js', { scope: @json($pwa['scope']) }).catch(() => {});
        }
    }
</script>
