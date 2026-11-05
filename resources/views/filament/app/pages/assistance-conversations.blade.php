<x-filament-panels::page>
    @php($conversation = $this->selectedConversation())
    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Conversas">
            <div class="space-y-3">
                @php($conversations = $this->conversations())
                @forelse ($conversations as $item)
                    <button type="button" wire:click="selectConversation({{ $item->id }})" class="flex w-full flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 text-left dark:border-gray-700" aria-pressed="{{ $selectedId === $item->id ? 'true' : 'false' }}">
                        <span><strong class="block text-sm">{{ $item->client?->name ?: 'Contato novo' }}</strong><span class="text-sm text-gray-500">{{ $item->phone_normalized }}</span></span>
                        <x-filament::badge :color="$item->human_takeover ? 'warning' : 'success'">{{ $item->human_takeover ? 'Equipe' : 'Bot' }}</x-filament::badge>
                    </button>
                @empty<p class="text-sm text-gray-500">Os atendimentos aparecerão aqui quando o bot estiver ativo e receber mensagens.</p>@endforelse
                {{ $conversations->links() }}
            </div>
        </x-filament::section>
        @if ($conversation)
            <div class="space-y-5" wire:key="assistance-conversation-{{ $conversation->id }}">
                <x-filament::section :heading="$conversation->client?->name ?: $conversation->phone_normalized">
                    <div class="space-y-4">
                        <p class="text-sm text-gray-500">{{ $conversation->human_takeover ? 'O bot está pausado até a equipe devolver o atendimento.' : 'O bot continua a partir da etapa atual.' }}</p>
                        <x-filament::button wire:click="setHuman({{ $conversation->human_takeover ? 'false' : 'true' }})" wire:confirm="{{ $conversation->human_takeover ? 'Devolver esta conversa ao bot para responder às próximas mensagens?' : 'Assumir esta conversa e pausar as respostas do bot?' }}">{{ $conversation->human_takeover ? 'Devolver ao bot' : 'Assumir conversa' }}</x-filament::button>
                        <p class="text-sm text-gray-500">Para responder manualmente, use a conversa da loja no WhatsApp conectado.</p>
                        <div class="space-y-3">
                            @foreach ($conversation->messages()->latest('id')->limit(60)->get()->reverse() as $message)
                                <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5"><strong>Cliente</strong><p class="mt-1 whitespace-pre-wrap break-words">{{ $message->incoming }}</p></div>
                                @if ($message->reply)<div class="rounded-lg bg-primary-50 p-3 text-sm dark:bg-primary-950"><strong>Bot · {{ ['queued' => 'Na fila', 'sending' => 'Enviando', 'sent' => 'Aceito pelo provedor', 'uncertain' => 'Envio não confirmado', 'cancelled' => 'Envio cancelado'][$message->status] ?? $message->status }}</strong><p class="mt-1 whitespace-pre-wrap break-words">{{ $message->reply }}</p></div>@endif
                            @endforeach
                        </div>
                    </div>
                </x-filament::section>
                <x-filament::section heading="Solicitações desta conversa">
                    <div class="space-y-4">
                        @forelse (\App\Models\AssistanceBotRequest::where('company_id', $conversation->company_id)->where('assistance_bot_conversation_id', $conversation->id)->latest('id')->get() as $request)
                            <div class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                <strong class="text-sm">{{ $request->protocol }} · {{ $request->kind === 'repair' ? 'Conserto' : 'Acessório' }}</strong>
                                <p class="text-sm">{{ $request->data['device'] ?? '' }}</p>
                                @if ($request->kind === 'repair')<p class="text-sm">{{ $request->data['problem'] ?? '' }}</p>@else<p class="text-sm">{{ ($request->data['accessory_category'] ?? '') === 'film' ? 'Película' : 'Capa' }}</p>@endif
                                @if ($request->kind === 'repair' && ! $request->service_order_id)
                                    <x-filament::button color="gray" wire:click="receiveDevice({{ $request->id }})" wire:confirm="Confirma que o aparelho já foi recebido pela loja? Será criada a OS com os dados desta solicitação.">Receber aparelho e abrir OS</x-filament::button>
                                @elseif ($request->service_order_id)
                                    <x-filament::button color="gray" tag="a" :href="\App\Filament\App\Resources\ServiceOrders\ServiceOrderResource::getUrl('edit', ['record' => $request->service_order_id])">Abrir OS</x-filament::button>
                                @elseif ($request->status === 'pending')
                                    <x-filament::button color="gray" wire:click="completeInterest({{ $request->id }})">Marcar interesse como atendido</x-filament::button>
                                @else<p class="text-sm text-gray-500">Atendido pela equipe.</p>@endif
                            </div>
                        @empty<p class="text-sm text-gray-500">Nenhuma solicitação registrada nesta conversa.</p>@endforelse
                    </div>
                </x-filament::section>
            </div>
        @else
            <p class="text-sm text-gray-500">Selecione uma conversa para ver o histórico e as solicitações.</p>
        @endif
    </div>
</x-filament-panels::page>
