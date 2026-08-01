<div class="admin-operations-page">
    <div class="admin-operations-page__intro">
        <div>
            <p class="admin-operations-page__eyebrow">Fila e processamento</p>
            <h1 class="admin-operations-page__title">Jobs falhados</h1>
            <p class="admin-operations-page__description">Revise falhas recentes, reenvie jobs corrigidos e mantenha a fila sob controle.</p>
        </div>
        <button type="button" wire:click="clearOldJobs" class="admin-operations-button admin-operations-button--secondary">Limpar falhas com mais de 7 dias</button>
    </div>

    @if (! $hasTable)
        <div class="admin-operations-empty">A tabela de jobs falhados ainda não existe neste ambiente.</div>
    @elseif (count($jobs) === 0)
        <div class="admin-operations-empty">Nenhum job falhado registrado.</div>
    @else
        <div class="admin-operations-table-wrap">
            <table class="admin-operations-table">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Fila</th>
                        <th>Falhou em</th>
                        <th>Exceção</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($jobs as $job)
                        <tr>
                            <td><strong>{{ $job['displayName'] }}</strong><small>{{ $job['connection'] }}</small></td>
                            <td>{{ $job['queue'] }}</td>
                            <td>{{ IlluminateSupportCarbon::parse($job['failedAt'])->format('d/m/Y H:i:s') }}</td>
                            <td><code>{{ $job['exception'] }}</code></td>
                            <td class="admin-operations-table__actions">
                                <button type="button" wire:click="retryJob(@js($job['id']))" class="admin-operations-button admin-operations-button--primary">Tentar novamente</button>
                                <button type="button" wire:click="forgetJob(@js($job['id']))" wire:confirm="Excluir esta falha?" class="admin-operations-button admin-operations-button--danger">Excluir</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
