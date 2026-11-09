<div x-data="{ unavailable: false }">
    <img
        src="{{ $company->logoUrl() }}"
        alt="Logo de {{ $company->name }}"
        style="max-width: 240px; max-height: 96px; object-fit: contain"
        x-show="! unavailable"
        x-on:error="unavailable = true"
        x-on:load="unavailable = false"
    >
    <p x-cloak x-show="unavailable" role="status">A logo atual não está disponível. Você pode enviar uma nova imagem ou clicar em Remover logo atual.</p>
</div>
