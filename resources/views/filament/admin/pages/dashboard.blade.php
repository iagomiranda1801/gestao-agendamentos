<div class="admin-dashboard">
    <header class="admin-dashboard__header">
        <div>
            <p class="admin-dashboard__eyebrow">Hoje, {{ $dashboard['dateLabel'] }}</p>
            <h1 class="admin-dashboard__title">Visão inteligente da plataforma</h1>
        </div>
        <p class="admin-dashboard__subtitle">Olá, {{ $userName }}. Aqui estão os sinais que merecem sua atenção agora.</p>
    </header>

    <section class="admin-dashboard__cards">
        @foreach ($dashboard['cards'] as $card)
            <a href="{{ $card['url'] }}" class="admin-dashboard-card admin-dashboard-tone--{{ $card['color'] }}">
                <span class="admin-dashboard-card__label">{{ $card['label'] }}</span>
                <strong class="admin-dashboard-card__value">{{ $card['value'] }}</strong>
                <span class="admin-dashboard-card__description">{{ $card['description'] }}</span>
            </a>
        @endforeach
    </section>

    <section class="admin-dashboard__grid">
        <div class="admin-dashboard-panel admin-dashboard-panel--wide">
            <div class="admin-dashboard-panel__header">
                <h2 class="admin-dashboard-panel__title">Alertas que pedem ação</h2>
            </div>
            <div class="admin-dashboard-alerts">
                @forelse ($dashboard['alerts'] as $alert)
                    <a href="{{ $alert['url'] }}" class="admin-dashboard-alert admin-dashboard-tone--{{ $alert['color'] }}">
                        <strong>{{ $alert['count'] }}</strong>
                        <span>
                            <b>{{ $alert['label'] }}</b>
                            <small>{{ $alert['description'] }}</small>
                        </span>
                    </a>
                @empty
                    <p class="admin-dashboard-empty">Nenhum alerta crítico no momento.</p>
                @endforelse
            </div>
        </div>

        <div class="admin-dashboard-panel">
            <div class="admin-dashboard-panel__header">
                <h2 class="admin-dashboard-panel__title">Uso em 7 dias</h2>
            </div>
            <dl class="admin-dashboard-metrics">
                <div>
                    <dt>Agendamentos</dt>
                    <dd>{{ $dashboard['usage']['appointments7d'] }}</dd>
                </div>
                <div>
                    <dt>Online</dt>
                    <dd>{{ $dashboard['usage']['onlineAppointments7d'] }}</dd>
                </div>
                <div>
                    <dt>Empresas ativas</dt>
                    <dd>{{ $dashboard['usage']['activeCompanies7d'] }}</dd>
                </div>
                <div>
                    <dt>Campanhas</dt>
                    <dd>{{ $dashboard['usage']['campaigns7d'] }}</dd>
                </div>
            </dl>
        </div>

        <div class="admin-dashboard-panel">
            <div class="admin-dashboard-panel__header">
                <h2 class="admin-dashboard-panel__title">WhatsApp e Evolution</h2>
            </div>
            <dl class="admin-dashboard-metrics">
                <div>
                    <dt>Campanhas hoje</dt>
                    <dd>{{ $dashboard['whatsapp']['campaignsToday'] }}</dd>
                </div>
                <div>
                    <dt>Aceitas</dt>
                    <dd>{{ $dashboard['whatsapp']['accepted'] }}</dd>
                </div>
                <div>
                    <dt>Confirmadas</dt>
                    <dd>{{ $dashboard['whatsapp']['sent'] }}</dd>
                </div>
                <div>
                    <dt>Falhas</dt>
                    <dd>{{ $dashboard['whatsapp']['failed'] }}</dd>
                </div>
                <div>
                    <dt>Webhooks hoje</dt>
                    <dd>{{ $dashboard['whatsapp']['webhooksToday'] }}</dd>
                </div>
            </dl>
        </div>

        <div class="admin-dashboard-panel admin-dashboard-panel--wide">
            <div class="admin-dashboard-panel__header">
                <h2 class="admin-dashboard-panel__title">Empresas que merecem atenção</h2>
            </div>
            <div class="admin-dashboard-table">
                @forelse ($dashboard['companiesAttention'] as $company)
                    <a href="{{ $company['url'] }}" class="admin-dashboard-company">
                        <span>
                            <b>{{ $company['name'] }}</b>
                            <small>{{ $company['modules'] ?: 'Sem módulos' }}</small>
                        </span>
                        <span>{{ $company['status'] }}</span>
                        <span>{{ $company['isActive'] ? 'Ativa' : 'Inativa' }}</span>
                        <span>{{ $company['activeAdmins'] }} admin(s)</span>
                        <span>{{ $company['appointments7d'] }} ag. 7d</span>
                        <span>{{ $company['failedCampaigns'] }} falha(s)</span>
                    </a>
                @empty
                    <p class="admin-dashboard-empty">Nenhuma empresa crítica agora.</p>
                @endforelse
            </div>
        </div>

        <div class="admin-dashboard-panel">
            <div class="admin-dashboard-panel__header">
                <h2 class="admin-dashboard-panel__title">Últimos jobs falhados</h2>
            </div>
            <div class="admin-dashboard-failures">
                @forelse ($dashboard['latestFailures'] as $failure)
                    <div class="admin-dashboard-failure">
                        <b>{{ $failure['queue'] }}</b>
                        <small>{{ $failure['failedAt'] }}</small>
                        <p>{{ $failure['error'] }}</p>
                    </div>
                @empty
                    <p class="admin-dashboard-empty">Nenhum job falhado registrado.</p>
                @endforelse
            </div>
        </div>
    </section>
</div>
