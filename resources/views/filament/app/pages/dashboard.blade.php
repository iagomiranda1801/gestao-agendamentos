@php
    use App\Support\CompanyDateTime;

    $company = $dashboard['company'] ?? null;
    $cards = $dashboard['cards'] ?? [];
    $alerts = $dashboard['alerts'] ?? [];
    $agenda = $dashboard['agenda'] ?? collect();
    $sales = $dashboard['sales'] ?? collect();

    $tone = fn (?string $color): string => 'agendaqui-dashboard-tone--'.($color ?: 'primary');
@endphp

@once
    <style>
        .agendaqui-dashboard {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .agendaqui-dashboard__header {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .agendaqui-dashboard__date {
            margin: 0;
            font-size: 0.875rem;
            color: rgb(100 116 139);
        }

        .agendaqui-dashboard__subtitle {
            margin: 0.25rem 0 0;
            color: rgb(71 85 105);
            font-size: 0.875rem;
        }

        .agendaqui-dashboard__title {
            margin: 0;
            font-size: 1.5rem;
            line-height: 2rem;
            font-weight: 650;
            letter-spacing: 0;
            color: rgb(15 23 42);
        }

        .agendaqui-dashboard__cards {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 1rem;
        }

        .agendaqui-dashboard-card,
        .agendaqui-dashboard-panel {
            border: 1px solid rgb(226 232 240);
            border-radius: 0.5rem;
            background: #fff;
            box-shadow: 0 1px 2px rgb(15 23 42 / 0.04);
        }

        .agendaqui-dashboard-card {
            display: block;
            padding: 1rem;
            text-decoration: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }

        .agendaqui-dashboard-card:hover {
            border-color: rgb(18 107 255 / 0.45);
            box-shadow: 0 8px 20px rgb(15 23 42 / 0.08);
            transform: translateY(-1px);
        }

        .agendaqui-dashboard-actions {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 0.75rem;
        }

        .agendaqui-dashboard-action {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 0;
            padding: 0.875rem 1rem;
            border: 1px solid rgb(191 219 254);
            border-radius: 0.5rem;
            background: rgb(239 246 255);
            color: rgb(15 23 42);
            text-decoration: none;
            transition: border-color 0.18s ease, background 0.18s ease, transform 0.18s ease;
        }

        .agendaqui-dashboard-action:hover {
            border-color: rgb(18 107 255 / 0.55);
            background: rgb(219 234 254);
            transform: translateY(-1px);
        }

        .agendaqui-dashboard-action__icon {
            display: inline-flex;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 0.375rem;
            background: #126bff;
            color: #fff;
            font-size: 1.25rem;
            line-height: 1;
        }

        .agendaqui-dashboard-action strong,
        .agendaqui-dashboard-action small {
            display: block;
        }

        .agendaqui-dashboard-action strong {
            font-size: 0.875rem;
            font-weight: 700;
        }

        .agendaqui-dashboard-action small {
            margin-top: 0.125rem;
            color: rgb(71 85 105);
            font-size: 0.75rem;
        }

        .agendaqui-dashboard-card__top,
        .agendaqui-dashboard-list__row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .agendaqui-dashboard-card__label,
        .agendaqui-dashboard-card__description,
        .agendaqui-dashboard-list__description,
        .agendaqui-dashboard-list__meta,
        .agendaqui-dashboard-empty {
            color: rgb(100 116 139);
        }

        .agendaqui-dashboard-card__label {
            margin: 0;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .agendaqui-dashboard-card__value {
            margin: 0.5rem 0 0;
            font-size: 1.5rem;
            line-height: 2rem;
            font-weight: 700;
            letter-spacing: 0;
            color: rgb(15 23 42);
        }

        .agendaqui-dashboard-card__description {
            margin: 0.75rem 0 0;
            font-size: 0.875rem;
        }

        .agendaqui-dashboard-chip,
        .agendaqui-dashboard-counter {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 650;
            white-space: nowrap;
        }

        .agendaqui-dashboard-chip {
            padding: 0.25rem 0.5rem;
        }

        .agendaqui-dashboard-counter {
            min-width: 2.5rem;
            padding: 0.25rem 0.75rem;
            font-size: 0.875rem;
        }

        .agendaqui-dashboard-tone--primary {
            border-color: rgb(191 219 254);
            background: rgb(239 246 255);
            color: rgb(29 78 216);
        }

        .agendaqui-dashboard-tone--success {
            border-color: rgb(167 243 208);
            background: rgb(236 253 245);
            color: rgb(4 120 87);
        }

        .agendaqui-dashboard-tone--warning {
            border-color: rgb(254 240 138);
            background: rgb(254 252 232);
            color: rgb(161 98 7);
        }

        .agendaqui-dashboard-tone--danger {
            border-color: rgb(254 205 211);
            background: rgb(255 241 242);
            color: rgb(190 18 60);
        }

        .agendaqui-dashboard__main-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1.5rem;
        }

        .agendaqui-dashboard-panel {
            overflow: hidden;
        }

        .agendaqui-dashboard-panel__header {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid rgb(241 245 249);
        }

        .agendaqui-dashboard-panel__title {
            margin: 0;
            font-size: 1rem;
            line-height: 1.5rem;
            font-weight: 650;
            color: rgb(15 23 42);
        }

        .agendaqui-dashboard-list {
            display: flex;
            flex-direction: column;
        }

        .agendaqui-dashboard-list__item {
            padding: 0.875rem 1rem;
            border-top: 1px solid rgb(241 245 249);
        }

        .agendaqui-dashboard-list__item:first-child {
            border-top: 0;
        }

        .agendaqui-dashboard-list__item--link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            text-decoration: none;
        }

        .agendaqui-dashboard-list__item--link:hover {
            background: rgb(248 250 252);
        }

        .agendaqui-dashboard-list__title {
            margin: 0;
            font-size: 0.9375rem;
            font-weight: 650;
            color: rgb(15 23 42);
        }

        .agendaqui-dashboard-list__description {
            margin: 0.25rem 0 0;
            font-size: 0.875rem;
        }

        .agendaqui-dashboard-list__description--strong {
            color: rgb(71 85 105);
        }

        .agendaqui-dashboard-list__meta {
            margin: 0;
            font-size: 0.75rem;
        }

        .agendaqui-dashboard-list__amount {
            text-align: right;
            white-space: nowrap;
        }

        .agendaqui-dashboard-list__amount p {
            margin: 0;
            font-weight: 700;
            color: rgb(15 23 42);
        }

        .agendaqui-dashboard-list__amount span {
            font-size: 0.75rem;
            color: rgb(100 116 139);
        }

        .agendaqui-dashboard-empty {
            padding: 2rem 1rem;
            font-size: 0.875rem;
        }

        @media (min-width: 768px) {
            .agendaqui-dashboard-actions {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .agendaqui-dashboard__cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1280px) {
            .agendaqui-dashboard__cards {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .agendaqui-dashboard__main-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .agendaqui-dashboard-panel--wide {
                grid-column: span 2 / span 2;
            }
        }
    </style>
@endonce

<div class="agendaqui-dashboard">
    <div class="agendaqui-dashboard__header">
        <p class="agendaqui-dashboard__date">Hoje, {{ $dashboard['dateLabel'] ?? now()->format('d/m/Y') }}</p>
        <h2 class="agendaqui-dashboard__title">Olá, {{ $userName }}.</h2>
        <p class="agendaqui-dashboard__subtitle">Aqui está o resumo da operação de hoje.</p>
    </div>

    @if (filled($dashboard['quickActions'] ?? []))
        <section class="agendaqui-dashboard-actions" aria-label="Ações rápidas">
            @foreach ($dashboard['quickActions'] as $action)
                <a href="{{ $action['url'] }}" class="agendaqui-dashboard-action">
                    <span class="agendaqui-dashboard-action__icon" aria-hidden="true">+</span>
                    <span>
                        <strong>{{ $action['label'] }}</strong>
                        <small>{{ $action['description'] }}</small>
                    </span>
                </a>
            @endforeach
        </section>
    @endif

    <div class="agendaqui-dashboard__cards">
        @foreach ($cards as $card)
            <a href="{{ $card['url'] }}" class="agendaqui-dashboard-card">
                <div class="agendaqui-dashboard-card__top">
                    <div>
                        <p class="agendaqui-dashboard-card__label">{{ $card['label'] }}</p>
                        <p class="agendaqui-dashboard-card__value">{{ $card['value'] }}</p>
                    </div>
                    <span class="agendaqui-dashboard-chip {{ $tone($card['color'] ?? 'primary') }}">ver</span>
                </div>
                <p class="agendaqui-dashboard-card__description">{{ $card['description'] }}</p>
            </a>
        @endforeach
    </div>

    <div class="agendaqui-dashboard__main-grid">
        <section class="agendaqui-dashboard-panel agendaqui-dashboard-panel--wide">
            <div class="agendaqui-dashboard-panel__header">
                <h3 class="agendaqui-dashboard-panel__title">Ações pendentes</h3>
            </div>
            <div class="agendaqui-dashboard-list">
                @forelse ($alerts as $alert)
                    <a href="{{ $alert['url'] }}" class="agendaqui-dashboard-list__item agendaqui-dashboard-list__item--link">
                        <div>
                            <p class="agendaqui-dashboard-list__title">{{ $alert['label'] }}</p>
                            <p class="agendaqui-dashboard-list__description">{{ $alert['description'] }}</p>
                        </div>
                        <span class="agendaqui-dashboard-counter {{ $tone($alert['color'] ?? 'primary') }}">
                            {{ $alert['count'] }}
                        </span>
                    </a>
                @empty
                    <div class="agendaqui-dashboard-empty">Nenhuma pendência crítica para agora.</div>
                @endforelse
            </div>
        </section>

        <section class="agendaqui-dashboard-panel">
            <div class="agendaqui-dashboard-panel__header">
                <h3 class="agendaqui-dashboard-panel__title">Agenda de hoje</h3>
            </div>
            <div class="agendaqui-dashboard-list">
                @forelse ($agenda as $appointment)
                    <div class="agendaqui-dashboard-list__item">
                        <div class="agendaqui-dashboard-list__row">
                            <p class="agendaqui-dashboard-list__title">
                                {{ $company ? CompanyDateTime::formatLocal($company, $appointment->start_at, 'H:i') : $appointment->start_at?->format('H:i') }}
                            </p>
                            <span class="agendaqui-dashboard-list__meta">{{ $appointment->status->label() }}</span>
                        </div>
                        <p class="agendaqui-dashboard-list__description agendaqui-dashboard-list__description--strong">{{ $appointment->client_name_snapshot ?? $appointment->client?->name ?? 'Cliente' }}</p>
                        <p class="agendaqui-dashboard-list__meta">{{ $appointment->service_name_snapshot ?? $appointment->service?->name ?? 'Serviço' }}</p>
                    </div>
                @empty
                    <div class="agendaqui-dashboard-empty">Nenhum agendamento para hoje.</div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="agendaqui-dashboard-panel">
        <div class="agendaqui-dashboard-panel__header">
            <h3 class="agendaqui-dashboard-panel__title">Últimas vendas</h3>
        </div>
        <div class="agendaqui-dashboard-list">
            @forelse ($sales as $sale)
                <div class="agendaqui-dashboard-list__item agendaqui-dashboard-list__row">
                    <div>
                        <p class="agendaqui-dashboard-list__title">Venda #{{ $sale->getKey() }}</p>
                        <p class="agendaqui-dashboard-list__description">{{ $sale->client?->name ?? 'Cliente não informado' }}</p>
                    </div>
                    <div class="agendaqui-dashboard-list__amount">
                        <p>R$ {{ number_format((float) $sale->final_amount, 2, ',', '.') }}</p>
                        <span>{{ $sale->status->label() }}</span>
                    </div>
                </div>
            @empty
                <div class="agendaqui-dashboard-empty">Nenhuma venda recente.</div>
            @endforelse
        </div>
    </section>
</div>
