<x-filament-panels::page>
    <x-filament::section heading="Nova credencial" description="A chave completa aparece uma vez. Guarde-a como segredo na função server-side da integração.">
        <form wire:submit="createIntegration" class="flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1">
                <label for="integration-name" class="block text-sm font-medium mb-1">Nome da integração</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="integration-name" type="text" wire:model="name" maxlength="120" required placeholder="GRAN.Wine Landing Page" />
                </x-filament::input.wrapper>
                @error('name') <span class="text-sm text-danger-600">{{ $message }}</span> @enderror
            </div>
            <x-filament::button type="submit">Criar credencial</x-filament::button>
        </form>
        @if ($oneTimeKey)
            <div class="mt-4 rounded-lg border border-warning-500 p-4" x-data="{ copied: false }">
                <p class="mb-2 font-semibold">Copie agora. Esta chave não poderá ser consultada novamente.</p>
                <div class="flex gap-2">
                    <input id="new-integration-key" class="w-full rounded border p-2 font-mono text-sm" value="{{ $oneTimeKey }}" readonly aria-label="Nova API Key" />
                    <x-filament::button type="button" color="gray" x-on:click="navigator.clipboard.writeText(document.getElementById('new-integration-key').value).then(() => copied = true)"><span x-text="copied ? 'Copiada' : 'Copiar'"></span></x-filament::button>
                </div>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Credenciais da empresa">
        <div class="space-y-4">
            @forelse ($this->integrations() as $integration)
                <div wire:key="integration-{{ $integration->id }}" class="rounded-lg border p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="font-semibold">{{ $integration->name }}</div>
                            <div class="text-sm text-gray-500">Prefixo: <code>{{ $integration->key_prefix }}…</code> · Escopo: criar clientes</div>
                            <div class="text-sm text-gray-500">Último uso: {{ $integration->last_used_at?->format('d/m/Y H:i') ?? 'Nunca' }} · Status: {{ $integration->revoked_at ? 'Revogada' : ($integration->is_active ? 'Ativa' : 'Desativada') }}</div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if (! $integration->revoked_at)
                                <x-filament::button type="button" color="gray" wire:click="toggle({{ $integration->id }})">{{ $integration->is_active ? 'Desativar' : 'Ativar' }}</x-filament::button>
                                <x-filament::button type="button" color="danger" wire:click="revoke({{ $integration->id }})" wire:confirm="Revogar esta chave? Ela deixará de funcionar imediatamente.">Revogar</x-filament::button>
                            @endif
                            <x-filament::button type="button" color="gray" wire:click="regenerate({{ $integration->id }})" wire:confirm="Gerar nova chave? A chave anterior deixará de funcionar imediatamente.">Gerar nova chave</x-filament::button>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">Nenhuma credencial criada.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
