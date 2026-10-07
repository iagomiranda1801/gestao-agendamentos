<div class="admin-operations-page">
    <div class="admin-operations-page__intro">
        <div>
            <p class="admin-operations-page__eyebrow">Evolution API</p>
            <h1 class="admin-operations-page__title">Webhooks recentes</h1>
            <p class="admin-operations-page__description">Acompanhe mensagens recebidas e enviadas pela Evolution. Os horários abaixo estão no fuso de Brasília.</p>
        </div>
    </div>

    @if (! $hasTable)
        <div class="admin-operations-empty">A tabela de webhooks ainda não existe neste ambiente.</div>
    @elseif ($events->isEmpty())
        <div class="admin-operations-empty">Nenhum webhook recebido.</div>
    @else
        <div class="admin-operations-table-wrap">
            <table class="admin-operations-table">
                <thead><tr><th>Evento</th><th>Instância</th><th>Direção</th><th>Contato</th><th>Prévia</th><th>Mensagem</th><th>Status de entrega</th><th>Recebido em (Brasília)</th></tr></thead>
                <tbody>
                    @foreach ($events as $event)
                        <tr><td>{{ $event->event ?: '—' }}</td><td>{{ $event->instance ?: '—' }}</td><td>{{ $this->direction($event) }}</td><td>{{ $this->contact($event) }}</td><td>{{ $this->messagePreview($event) }}</td><td><code>{{ $event->message_id ?: '—' }}</code></td><td><span class="admin-operations-status admin-operations-status--{{ strtolower((string) $event->provider_status) === 'error' ? 'danger' : 'success' }}">{{ $event->provider_status ?: '—' }}</span></td><td>{{ $event->created_at?->copy()->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
