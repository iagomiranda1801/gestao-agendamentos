<div class="space-y-2">
    <label for="{{ $targetId }}" class="text-sm font-medium">{{ $targetLabel }}</label>
    <x-filament::input.wrapper><x-filament::input.select id="{{ $targetId }}" wire:model.blur="{{ $modelPath }}">
        <option value="">Escolha um bloco</option>
        @foreach ($labels as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
    </x-filament::input.select></x-filament::input.wrapper>
</div>
