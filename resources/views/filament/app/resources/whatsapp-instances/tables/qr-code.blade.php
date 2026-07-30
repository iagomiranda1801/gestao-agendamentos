@php($qrCode = $getState())

@if (filled($qrCode) && str_starts_with((string) $qrCode, 'data:image'))
    <img
        src="{{ $qrCode }}"
        alt="QR code da instância WhatsApp"
        style="width: 72px; height: 72px; object-fit: contain; border-radius: 6px; background: white; padding: 4px;"
    >
@elseif (in_array($record?->status, ['open', 'connected'], true))
    <span class="text-sm text-success-600">Conectado</span>
@else
    <span class="text-sm text-gray-500">Ainda não gerado</span>
@endif
