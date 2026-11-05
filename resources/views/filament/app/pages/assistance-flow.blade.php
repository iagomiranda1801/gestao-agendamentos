<x-filament-panels::page>
    @php
        $flow = $this->flow();
        $nodes = $definition['nodes'];
        $current = $nodes[$selected] ?? $nodes[0];
        $path = 'definition.nodes.'.$selected;
        $labels = array_column($nodes, 'label', 'id');
        $types = \App\Services\ServiceOrders\Bot\AssistanceFlowDefinition::TYPES;
    @endphp
    <style>
        .assistance-flow-layout{display:grid;grid-template-columns:minmax(220px,1fr) minmax(0,2fr);gap:1.5rem;align-items:start}
        .assistance-flow-node{display:block;width:100%;text-align:left;border:1px solid #d1d5db;border-radius:.75rem;padding:.8rem;background:#fff;color:#111827}
        .assistance-flow-node[aria-pressed=true]{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb;background:#eff6ff}
        .assistance-flow-node small{display:block;color:#4b5563;margin-top:.25rem}
        .assistance-flow-link{margin:.3rem 0 .6rem .8rem;color:#6b7280;font-size:.75rem;overflow-wrap:anywhere}
        .assistance-flow-bubble{max-width:90%;padding:.8rem 1rem;border-radius:.8rem;background:#f3f4f6;color:#111827;white-space:pre-wrap;overflow-wrap:anywhere}
        .assistance-flow-bubble.client{margin-left:auto;background:#dbeafe}
        .dark .assistance-flow-node{background:#18181b;border-color:#52525b;color:#f4f4f5}
        .dark .assistance-flow-node[aria-pressed=true]{background:#172554;border-color:#60a5fa;box-shadow:0 0 0 1px #60a5fa}
        .dark .assistance-flow-node small,.dark .assistance-flow-link{color:#a1a1aa}
        .dark .assistance-flow-bubble{background:#27272a;color:#f4f4f5}
        .dark .assistance-flow-bubble.client{background:#1e3a8a}
        @media(max-width:760px){.assistance-flow-layout{grid-template-columns:minmax(0,1fr)}}
    </style>

    <div class="flex flex-wrap items-center gap-3">
        <x-filament::badge :color="$flow->is_enabled ? 'success' : 'gray'">{{ $flow->is_enabled ? 'Bot ativo' : 'Bot desativado' }}</x-filament::badge>
        <span class="text-sm text-gray-600 dark:text-gray-400">{{ $flow->published_version_id ? 'Versão publicada: '.$flow->versions()->whereKey($flow->published_version_id)->value('number') : 'Nenhuma versão publicada' }} · Editando rascunho</span>
    </div>
    @if ($errors->any())
        <div role="alert" class="rounded-lg bg-danger-50 p-4 text-sm text-danger-700 dark:bg-danger-950 dark:text-danger-300">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <div class="assistance-flow-layout">
        <x-filament::section heading="Caminhos do atendimento">
            <div class="mb-4 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                <strong>Identificar cliente</strong>
                <p class="text-gray-600 dark:text-gray-400">O telefone vem do WhatsApp. O nome é perguntado só para novos clientes.</p>
            </div>
            @foreach ($nodes as $index => $node)
                <div wire:key="flow-node-{{ $node['id'] }}">
                    <button type="button" class="assistance-flow-node" wire:click="selectNode({{ $index }})" aria-pressed="{{ $index === $selected ? 'true' : 'false' }}">
                        <span class="font-medium">{{ $node['label'] }}</span>
                        <small>{{ $types[$node['type']] }}{{ $definition['entry'] === $node['id'] ? ' · Início' : '' }}</small>
                    </button>
                    <div class="assistance-flow-link">
                        @foreach (app(\App\Services\ServiceOrders\Bot\AssistanceFlowDefinition::class)->edges($node) as $edge)
                            <span>→ {{ $labels[$edge] ?? 'Escolher próximo passo' }}</span>{{ $loop->last ? '' : ' · ' }}
                        @endforeach
                    </div>
                </div>
            @endforeach
            <div class="mt-4 space-y-2">
                <label for="flow-new-type" class="text-sm font-medium">Novo bloco</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="flow-new-type" wire:model="newType">
                        @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::button wire:click="addNode" color="gray" icon="heroicon-o-plus">Adicionar bloco</x-filament::button>
            </div>
        </x-filament::section>

        <div class="space-y-5">
            <x-filament::section :heading="'Editar: '.$current['label']">
                <div class="space-y-4" wire:key="flow-edit-{{ $current['id'] }}">
                    <div class="space-y-2">
                        <label for="flow-node-label" class="text-sm font-medium">Nome do bloco</label>
                        <x-filament::input.wrapper><x-filament::input id="flow-node-label" wire:model.blur="{{ $path }}.label" maxlength="100" /></x-filament::input.wrapper>
                    </div>
                    @if (in_array($current['type'], ['message', 'question', 'menu', 'end']))
                        <div class="space-y-2">
                            <label for="flow-node-text" class="text-sm font-medium">Mensagem para o cliente</label>
                            <textarea id="flow-node-text" wire:model="{{ $path }}.text" maxlength="2000" rows="5" class="w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 dark:border-gray-700 dark:bg-gray-900 dark:text-white"></textarea>
                            <p class="text-xs text-gray-500">Personalize com {{ '{nome}' }}, {{ '{empresa}' }}, {{ '{aparelho}' }} e {{ '{problema}' }}.</p>
                        </div>
                    @endif
                    @if ($current['type'] === 'question')
                        <div class="space-y-2">
                            <label for="flow-answer-field" class="text-sm font-medium">Salvar a resposta em</label>
                            <x-filament::input.wrapper><x-filament::input.select id="flow-answer-field" wire:model="{{ $path }}.field">
                                @foreach (\App\Services\ServiceOrders\Bot\AssistanceFlowDefinition::FIELDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </x-filament::input.select></x-filament::input.wrapper>
                        </div>
                    @endif
                    @if ($current['type'] === 'action')
                        <div class="space-y-2">
                            <label for="flow-node-action" class="text-sm font-medium">Ação protegida do sistema</label>
                            <x-filament::input.wrapper><x-filament::input.select id="flow-node-action" wire:model="{{ $path }}.action">
                                @foreach (\App\Services\ServiceOrders\Bot\AssistanceFlowDefinition::ACTIONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </x-filament::input.select></x-filament::input.wrapper>
                            <p class="text-sm text-gray-500">As consultas sempre respeitam o cliente e a empresa da conversa.</p>
                        </div>
                    @endif
                    @if ($current['type'] === 'condition')
                        <p class="text-sm">Condição: cliente tem OS em andamento.</p>
                        @include('filament.app.pages.partials.assistance-flow-target', ['modelPath' => $path.'.yes', 'targetLabel' => 'Se sim, seguir para', 'targetId' => 'flow-condition-yes'])
                        @include('filament.app.pages.partials.assistance-flow-target', ['modelPath' => $path.'.no', 'targetLabel' => 'Se não, seguir para', 'targetId' => 'flow-condition-no'])
                    @endif
                    @if ($current['type'] === 'menu')
                        @if (($current['field'] ?? '') === 'accessory_category')<p class="text-sm text-gray-500">Este menu também identifica o tipo de acessório.</p>@endif
                        @foreach ($current['options'] as $optionIndex => $option)
                            <div class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700" wire:key="flow-option-{{ $current['id'] }}-{{ $optionIndex }}">
                                <label for="flow-option-{{ $optionIndex }}" class="text-sm font-medium">Opção {{ $optionIndex + 1 }}</label>
                                <x-filament::input.wrapper><x-filament::input id="flow-option-{{ $optionIndex }}" wire:model="{{ $path }}.options.{{ $optionIndex }}.label" maxlength="100" /></x-filament::input.wrapper>
                                @if (($current['field'] ?? '') === 'accessory_category')
                                    <label for="flow-option-value-{{ $optionIndex }}" class="text-sm">Tipo de acessório</label>
                                    <x-filament::input.wrapper><x-filament::input.select id="flow-option-value-{{ $optionIndex }}" wire:model="{{ $path }}.options.{{ $optionIndex }}.value"><option value="film">Película</option><option value="case">Capa</option></x-filament::input.select></x-filament::input.wrapper>
                                @endif
                                @include('filament.app.pages.partials.assistance-flow-target', ['modelPath' => $path.'.options.'.$optionIndex.'.next', 'targetLabel' => 'Seguir para', 'targetId' => 'flow-option-target-'.$optionIndex])
                                @if (count($current['options']) > 1)<x-filament::button size="sm" color="danger" wire:click="removeOption({{ $optionIndex }})">Remover opção</x-filament::button>@endif
                            </div>
                        @endforeach
                        @if (count($current['options']) < 6)<x-filament::button color="gray" wire:click="addOption">Adicionar opção</x-filament::button>@endif
                    @endif
                    @if (in_array($current['type'], ['message', 'question', 'action']))
                        @include('filament.app.pages.partials.assistance-flow-target', ['modelPath' => $path.'.next', 'targetLabel' => 'Próximo passo', 'targetId' => 'flow-next'])
                    @endif
                    @if ($current['id'] !== $definition['entry'])
                        <x-filament::button color="danger" outlined wire:click="removeNode" wire:confirm="Remover este bloco? Revise as ligações dos blocos que apontavam para ele antes de publicar.">Remover bloco</x-filament::button>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section heading="Início da conversa" collapsible collapsed>
                <div class="space-y-3">
                    <label for="flow-name-prompt" class="text-sm font-medium">Pergunta do nome para novos clientes</label>
                    <textarea id="flow-name-prompt" wire:model="definition.name_prompt" maxlength="1000" rows="2" class="w-full rounded-lg border-gray-300 bg-white text-sm text-gray-950 dark:bg-gray-900 dark:text-white"></textarea>
                    @include('filament.app.pages.partials.assistance-flow-target', ['modelPath' => 'definition.entry', 'targetLabel' => 'Depois da identificação, iniciar em', 'targetId' => 'flow-entry'])
                </div>
            </x-filament::section>

            <x-filament::section heading="Testar conversa">
                <div class="space-y-4">
                    <p class="text-sm text-gray-500">Teste o rascunho sem enviar WhatsApp ou criar registros. Reinicie o teste depois de editar os blocos.</p>
                    <div class="flex flex-wrap items-center gap-4">
                        <label class="text-sm"><input type="checkbox" wire:model="simulateKnownCustomer"> Cliente já cadastrado</label>
                        <label class="text-sm"><input type="checkbox" wire:model="simulateHasOrders"> Cliente tem OS em andamento</label>
                        <x-filament::button color="gray" wire:click="startSimulation">Iniciar teste</x-filament::button>
                    </div>
                    <div class="space-y-3" aria-live="polite">
                        @foreach ($transcript as $message)<div class="assistance-flow-bubble {{ $message['role'] === 'client' ? 'client' : '' }}"><span class="mb-1 block text-xs font-medium">{{ $message['role'] === 'client' ? 'Cliente de teste' : 'Bot' }}</span>{{ $message['text'] }}</div>@endforeach
                    </div>
                    @if ($transcript)
                        <form wire:submit="sendSimulation" class="flex flex-col gap-3 sm:flex-row">
                            <div class="min-w-0 flex-1"><x-filament::input.wrapper><x-filament::input aria-label="Mensagem de teste" wire:model="simulatorInput" placeholder="Digite uma resposta ou o número da opção" maxlength="1000" /></x-filament::input.wrapper></div>
                            <x-filament::button type="submit" wire:loading.attr="disabled">Enviar no teste</x-filament::button>
                        </form>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section heading="Versões publicadas" collapsible collapsed>
                <div class="space-y-3">
                    @forelse ($flow->versions()->latest('number')->get() as $version)
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm">Versão {{ $version->number }} · {{ \App\Support\CompanyDateTime::formatLocal(\Filament\Facades\Filament::getTenant(), $version->created_at) }}</span>
                            <x-filament::button size="sm" color="gray" wire:click="restoreVersion({{ $version->id }})" wire:confirm="Copiar esta versão para o rascunho? Alterações não salvas serão substituídas.">Restaurar como rascunho</x-filament::button>
                        </div>
                    @empty<p class="text-sm text-gray-500">Publique a primeira versão depois de testar o fluxo.</p>@endforelse
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
