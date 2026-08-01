<div class="admin-operations-page">
    <div class="admin-operations-page__intro">
        <div>
            <p class="admin-operations-page__eyebrow">Evolution API</p>
            <h1 class="admin-operations-page__title">Webhooks recentes</h1>
            <p class="admin-operations-page__description">Acompanhe eventos recebidos e estados de entrega das mensagens.</p>
        </div>
    </div>

    @if (! $hasTable)
        <div class="admin-operations-empty">A tabela de webhooks ainda não existe neste ambiente.</div>
    @elseif ($events->isEmpty())
        <div class="admin-operations-empty">Nenhum webhook recebido.</div>
    @else
        <div class="admin-operations-table-wrap">
            <table class="admin-operations-table">
                <thead><tr><th>Evento</th><th>Instância</th><th>Mensagem</th><th>Status</th><th>Processado em</th><th>Recebido em</th></tr></thead>
                <tbody>
                    @foreach ($events as $event)
                        <tr><td>{{ $event->event ?: '-' }}</td><td>{{ $event->instance ?: '-' }}</td><td><code>{{ $event->message_id ?: '-' }}</code></td><td><span class="admin-operations-status admin-operations-status--{{ strtolower((string) $event->provider_status) === 'error' ? 'danger' : 'success' }}">{{ $event->provider_status ?: '-' }}</span></td><td>{{ $event->processed_at?->format('d/m/Y H:i:s') ?: 'Pendente' }}</td><td>{{ $event->created_at?->format('d/m/Y H:i:s') }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
