@php
    use App\Filament\App\Resources\TattooRequests\TattooRequestResource;
    use App\Filament\App\Resources\Clients\ClientResource;
    use App\Filament\App\Resources\Appointments\AppointmentResource;
    use App\Support\CompanyDateTime;

    $conversation = $this->getRecord()->fresh(['client', 'company', 'professional', 'appointment', 'request.professional', 'request.quotes']);
    $isTattoo = $conversation->company->isTattooStudio();
    $collected = $conversation->collected_data ?: [];
    $request = $conversation->request;
    $quote = $request?->quotes->sortByDesc('version')->first();
    $messages = $this->getChatMessages();
    $timezone = CompanyDateTime::timezone($conversation->company);
    $externallyPaused = $this->isExternallyPaused();
    $statusLabels = [
        'collecting_information' => 'Coletando informações',
        'waiting_professional_quote' => 'Aguardando orçamento',
        'waiting_payment_receipt' => 'Aguardando comprovante',
        'receipt_received' => 'Comprovante em análise',
        'payment_confirmed' => 'Sinal confirmado',
        'ready_to_schedule' => 'Pronto para agendar',
        'converted_to_appointment' => 'Agendado',
        'human_takeover' => 'Atendimento humano',
        'offering_slots' => 'Escolhendo horário',
        'awaiting_confirmation' => 'Aguardando confirmação',
    ];
@endphp

<x-filament-panels::page>
    <div class="tattoo-chat-layout">
        <section class="tattoo-chat-card" aria-label="Conversa pelo WhatsApp">
            <header class="tattoo-chat-header">
                <div class="tattoo-chat-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($conversation->client?->name ?: 'C', 0, 1)) }}</div>
                <div class="tattoo-chat-heading">
                    <strong>{{ $conversation->client?->name ?: 'Cliente WhatsApp' }}</strong>
                    <span>{{ $conversation->phone_normalized }}</span>
                </div>
                <span class="tattoo-chat-mode {{ $conversation->human_takeover ? 'is-human' : ($externallyPaused ? 'is-paused' : 'is-ai') }}">
                    {{ $conversation->human_takeover ? 'Equipe atendendo' : ($externallyPaused ? 'IA pausada pelo WhatsApp' : 'IA atendendo') }}
                </span>
            </header>

            <div class="tattoo-chat-thread" wire:poll.10s x-data x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })" x-on:tattoo-chat-sent.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })">
                @if ($this->hasOlderMessages())
                    <div class="tattoo-chat-load-more">
                        <button type="button" wire:click="loadOlderMessages">Carregar mensagens anteriores</button>
                    </div>
                @endif

                @forelse ($messages as $message)
                    @php
                        $outgoing = $message->direction === 'out';
                        $manual = str_ends_with($message->status, '_manual');
                        $attachmentUrl = $this->messageAttachmentUrl($message);
                    @endphp
                    <div class="tattoo-chat-row {{ $outgoing ? 'is-outgoing' : 'is-incoming' }}" wire:key="tattoo-chat-message-{{ $message->id }}">
                        <article class="tattoo-chat-bubble {{ $outgoing ? 'is-outgoing' : 'is-incoming' }}">
                            <div class="tattoo-chat-sender">{{ $outgoing ? ($manual ? 'Equipe' : 'IA') : 'Cliente' }}</div>
                            @if (filled($message->body))
                                <div class="tattoo-chat-text">{{ $message->body }}</div>
                            @endif
                            @if ($message->media_mime)
                                <div class="tattoo-chat-attachment">
                                    @if ($attachmentUrl)
                                        <a href="{{ $attachmentUrl }}" target="_blank" rel="noopener noreferrer">
                                            {{ $message->media_mime === 'application/pdf' ? 'Abrir PDF recebido' : 'Abrir imagem recebida' }}
                                        </a>
                                    @else
                                        <span>{{ $message->media_mime === 'application/pdf' ? 'PDF recebido' : 'Imagem recebida' }}</span>
                                    @endif
                                </div>
                            @endif
                            <div class="tattoo-chat-meta">
                                <time datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->timezone($timezone)->format('d/m H:i') }}</time>
                                @if (in_array($message->status, ['failed', 'failed_manual', 'suppressed'], true))
                                    <span class="tattoo-chat-send-error">{{ $message->status === 'suppressed' ? 'Resposta suspensa' : 'Envio não confirmado' }}</span>
                                @elseif (in_array($message->status, ['sending', 'sending_manual', 'pending'], true))
                                    <span>Enviando…</span>
                                @endif
                            </div>
                        </article>
                    </div>
                @empty
                    <div class="tattoo-chat-empty">As mensagens desta conversa aparecerão aqui.</div>
                @endforelse
            </div>

            <footer class="tattoo-chat-composer">
                @if ($conversation->human_takeover)
                    <form wire:submit.prevent="sendReply">
                        <label for="tattoo-chat-reply">Responder pelo WhatsApp</label>
                        <textarea id="tattoo-chat-reply" wire:model="messageDraft" maxlength="4000" rows="3" placeholder="Escreva sua mensagem para o cliente…" @disabled(! $this->canManageConversation())></textarea>
                        @error('messageDraft') <span class="tattoo-chat-validation">{{ $message }}</span> @enderror
                        <div class="tattoo-chat-composer-actions">
                            <span>A resposta será enviada pela instância WhatsApp da empresa.</span>
                            @if ($this->canManageConversation())
                                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="sendReply">Enviar mensagem</x-filament::button>
                            @endif
                        </div>
                    </form>
                @else
                    <div class="tattoo-chat-composer-locked">
                        @if ($externallyPaused)
                            A IA está pausada por uma mensagem enviada no WhatsApp da empresa. Use <strong>Retomar IA</strong> para responder às próximas mensagens, ou <strong>Assumir atendimento</strong> para responder pela equipe.
                        @else
                            A IA está atendendo. Use <strong>Assumir atendimento</strong> para responder como equipe.
                        @endif
                    </div>
                @endif
            </footer>
        </section>

        <aside class="tattoo-chat-details" aria-label="Detalhes do atendimento">
            <div class="tattoo-chat-detail-card">
                <h2>Atendimento</h2>
                <dl>
                    <div><dt>Status</dt><dd>{{ $statusLabels[$conversation->status] ?? str_replace('_', ' ', $conversation->status) }}</dd></div>
                    <div><dt>Última interação</dt><dd>{{ $conversation->last_interaction_at?->timezone($timezone)->format('d/m/Y H:i') ?: '—' }}</dd></div>
                    <div><dt>Profissional</dt><dd>{{ $request?->professional?->name ?: ($conversation->professional?->name ?: 'Ainda não atribuído') }}</dd></div>
                </dl>
                @if ($conversation->client)
                    <a class="tattoo-chat-detail-link" href="{{ ClientResource::getUrl('edit', ['record' => $conversation->client]) }}">Abrir cadastro do cliente</a>
                @endif
            </div>
            @unless ($isTattoo)
            <div class="tattoo-chat-detail-card">
                <h2>Agendamento</h2>
                <dl>
                    <div><dt>Serviço em conversa</dt><dd>{{ $collected['service_name'] ?? 'Ainda não escolhido' }}</dd></div>
                    @if (! empty($collected['selected_slot']['label']))
                        <div><dt>Horário escolhido</dt><dd>{{ $collected['selected_slot']['label'] }}</dd></div>
                    @endif
                    @if ($conversation->appointment)
                        <div><dt>Último agendamento</dt><dd>{{ $conversation->appointment->service_name_snapshot }} · {{ $conversation->appointment->start_at?->timezone($timezone)->format('d/m/Y H:i') }}</dd></div>
                    @endif
                </dl>
                @if ($conversation->appointment)
                    <a class="tattoo-chat-detail-link" href="{{ AppointmentResource::getUrl('view', ['record' => $conversation->appointment]) }}">Abrir agendamento</a>
                @endif
            </div>
            @else
            <div class="tattoo-chat-detail-card">
                <h2>Pedido de tatuagem</h2>
                @if ($request)
                    <p class="tattoo-chat-description">{{ $request->description }}</p>
                    <dl>
                        <div><dt>Local</dt><dd>{{ $request->body_placement }}</dd></div>
                        <div><dt>Tamanho</dt><dd>{{ $request->size_description ?: 'Não informado' }}</dd></div>
                        <div><dt>Orçamento</dt><dd>{{ $quote?->situationLabel() ?: 'Aguardando tatuador' }}</dd></div>
                    </dl>
                    <a class="tattoo-chat-detail-link" href="{{ TattooRequestResource::getUrl('edit', ['record' => $request]) }}">Abrir pedido e comprovantes</a>
                @else
                    <p class="tattoo-chat-muted">A IA ainda está reunindo os detalhes para criar o pedido.</p>
                @endif
            </div>
            @endunless
        </aside>
    </div>
</x-filament-panels::page>
