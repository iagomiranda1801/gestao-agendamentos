<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::tabs label="Tipo de compatibilidade">
            @foreach (\App\Services\ServiceOrders\DeviceCompatibilityService::CATEGORIES as $key => $label)
                <x-filament::tabs.item
                    :active="$category === $key"
                    wire:click="selectCategory('{{ $key }}')"
                    wire:loading.attr="disabled"
                >
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <x-filament::section>
            <form wire:submit="search" class="space-y-3">
                <label for="compatibility-device" class="text-sm font-medium text-gray-950 dark:text-white">Pesquisar aparelho</label>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <div class="min-w-0 flex-1">
                        <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" :valid="! $errors->has('query')">
                            <x-filament::input id="compatibility-device" wire:model="query" placeholder="Ex.: Samsung A12, Moto G54, iPhone 11" maxlength="100" autocomplete="off" />
                        </x-filament::input.wrapper>
                    </div>
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="search,selectCategory">
                        <span wire:loading.remove wire:target="search">Pesquisar</span>
                        <span wire:loading wire:target="search">Pesquisando…</span>
                    </x-filament::button>
                </div>
                @error('query')<p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
            </form>
        </x-filament::section>

        <div aria-live="polite" aria-atomic="true" class="space-y-4">
            @if ($searchError)
                <p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $searchError }}</p>
            @elseif ($hasSearched)
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ \App\Services\ServiceOrders\DeviceCompatibilityService::CATEGORIES[$category] }} · {{ $searchedQuery }}</h2>
                @forelse ($results as $result)
                    <x-filament::section :heading="$result['model']" wire:key="compatibility-{{ $category }}-{{ $loop->index }}">
                        @if ($result['compatible_models'])
                            <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">Pode usar a película dos modelos:</p>
                            <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($result['compatible_models'] as $model)
                                    <li class="rounded-lg bg-gray-50 px-3 py-2 text-sm font-medium break-words text-gray-950 dark:bg-white/5 dark:text-white">{{ $model }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-sm text-gray-600 dark:text-gray-400">Nenhuma compatibilidade informada para este modelo.</p>
                        @endif
                    </x-filament::section>
                @empty
                    <x-filament::section>
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $category === 'film' ? 'Nenhum aparelho encontrado. Confira o nome e inclua a marca ou a variante, como 4G ou 5G.' : 'Compatibilidade ainda não cadastrada para este aparelho.' }}</p>
                    </x-filament::section>
                @endforelse
                @if ($category === 'film' && count($results))
                    <p class="text-sm text-gray-500 dark:text-gray-400">Confira o recorte e o encaixe antes de aplicar.</p>
                @endif
            @else
                <p class="text-sm text-gray-600 dark:text-gray-400">Selecione o tipo e pesquise o aparelho para ver os modelos compatíveis.</p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
