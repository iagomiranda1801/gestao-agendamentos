@php
    $intervalDisplay = filled($intervalLabel) ? $intervalLabel : 'Não definido';
    $priceDisplay = ($quotedPrice ?? '—') === '—' ? 'Não definido' : $quotedPrice;
    $periodDisplay = $periodEnd ?: 'Sem vencimento';
    $timezone = $company->timezone ?: 'America/Sao_Paulo';
    $badgeClass = fn (string $color): string => 'agendaqui-billing-badge agendaqui-billing-badge--'.$color;
@endphp

@once
    <style>
        .agendaqui-billing {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            max-width: 56rem;
            margin: 0 auto;
        }

        .agendaqui-billing-hero,
        .agendaqui-billing-card,
        .agendaqui-billing-pix,
        .agendaqui-billing-empty {
            border: 1px solid rgb(226 232 240);
            border-radius: 1rem;
            background: #fff;
            box-shadow: 0 1px 2px rgb(15 23 42 / 0.04);
        }

        .agendaqui-billing-hero {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.25rem 1.5rem;
        }

        .agendaqui-billing-hero__title {
            margin: 0.35rem 0 0;
            font-size: 1.25rem;
            line-height: 1.75rem;
            font-weight: 650;
            color: rgb(15 23 42);
        }

        .agendaqui-billing-hero__hint {
            margin: 0.35rem 0 0;
            font-size: 0.875rem;
            color: rgb(71 85 105);
        }

        .agendaqui-billing-hero--success { border-color: rgb(167 243 208); background: rgb(236 253 245); }
        .agendaqui-billing-hero--warning { border-color: rgb(253 230 138); background: rgb(255 251 235); }
        .agendaqui-billing-hero--danger { border-color: rgb(254 202 202); background: rgb(254 242 242); }
        .agendaqui-billing-hero--gray { border-color: rgb(226 232 240); background: rgb(248 250 252); }

        .agendaqui-billing-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        .agendaqui-billing-card {
            padding: 1rem 1.15rem;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }

        .agendaqui-billing-card:hover {
            border-color: rgb(18 107 255 / 0.45);
            box-shadow: 0 8px 20px rgb(15 23 42 / 0.08);
            transform: translateY(-1px);
        }

        .agendaqui-billing-card__label {
            margin: 0;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: rgb(100 116 139);
        }

        .agendaqui-billing-card__value {
            margin: 0.4rem 0 0;
            font-size: 1.05rem;
            font-weight: 650;
            color: rgb(15 23 42);
        }

        .agendaqui-billing-card__value--muted {
            color: rgb(148 163 184);
            font-weight: 500;
        }

        .agendaqui-billing-section__title {
            margin: 0 0 0.75rem;
            font-size: 1rem;
            font-weight: 650;
            color: rgb(15 23 42);
        }

        .agendaqui-billing-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .agendaqui-billing-chip {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.7rem;
            border-radius: 999px;
            background: rgb(239 246 255);
            border: 1px solid rgb(191 219 254);
            color: rgb(30 64 175);
            font-size: 0.8rem;
            font-weight: 600;
        }

        .agendaqui-billing-pix {
            padding: 1.15rem 1.25rem;
        }

        .agendaqui-billing-pix--due {
            border-color: rgb(253 230 138);
            background: rgb(255 251 235);
        }

        .agendaqui-billing-pix__title {
            margin: 0.65rem 0 0;
            font-size: 1rem;
            font-weight: 650;
            color: rgb(15 23 42);
        }

        .agendaqui-billing-pix__due {
            margin: 0.35rem 0 0;
            font-size: 0.875rem;
            color: rgb(71 85 105);
        }

        .agendaqui-billing-invoice-card__amount {
            margin: 0.35rem 0 0;
            font-size: 1.15rem;
            font-weight: 700;
            color: rgb(15 23 42);
        }

        .agendaqui-billing-pix__amount {
            margin: 0.35rem 0 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: rgb(15 23 42);
        }

        .agendaqui-billing-pix__copy {
            margin-top: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 0.85rem;
            border-radius: 0.5rem;
            border: 1px solid rgb(18 107 255 / 0.45);
            background: #126bff;
            color: #fff;
            font-size: 0.8rem;
            font-weight: 650;
            cursor: pointer;
        }

        .agendaqui-billing-pix__copy:hover {
            background: rgb(29 78 216);
        }

        .agendaqui-billing-empty {
            padding: 1.5rem 1.25rem;
            text-align: center;
            color: rgb(100 116 139);
            font-size: 0.875rem;
        }

        .agendaqui-billing-empty svg {
            width: 2rem;
            height: 2rem;
            margin: 0 auto 0.6rem;
            color: #126bff;
        }

        .agendaqui-billing-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.15rem 0.55rem;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .agendaqui-billing-badge--warning { background: rgb(254 243 199); color: rgb(146 64 14); }
        .agendaqui-billing-badge--danger { background: rgb(254 226 226); color: rgb(153 27 27); }
        .agendaqui-billing-badge--success { background: rgb(209 250 229); color: rgb(6 95 70); }
        .agendaqui-billing-badge--gray { background: rgb(226 232 240); color: rgb(51 65 85); }

        .agendaqui-billing-table-wrap {
            display: none;
            overflow-x: auto;
            border: 1px solid rgb(226 232 240);
            border-radius: 1rem;
        }

        .agendaqui-billing-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            text-align: left;
        }

        .agendaqui-billing-table th {
            padding: 0.7rem 0.85rem;
            font-size: 0.7rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: rgb(100 116 139);
            background: rgb(248 250 252);
        }

        .agendaqui-billing-table td {
            padding: 0.75rem 0.85rem;
            border-top: 1px solid rgb(226 232 240);
            color: rgb(15 23 42);
        }

        .agendaqui-billing-invoice-cards {
            display: grid;
            gap: 0.75rem;
        }

        .agendaqui-billing-invoice-card {
            border: 1px solid rgb(226 232 240);
            border-radius: 0.85rem;
            padding: 0.9rem 1rem;
            background: #fff;
        }

        .agendaqui-billing-invoice-card__row {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            align-items: baseline;
        }

        .agendaqui-billing-meta {
            margin: 0;
            font-size: 0.8rem;
            color: rgb(100 116 139);
        }

        @media (min-width: 640px) {
            .agendaqui-billing-stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (min-width: 768px) {
            .agendaqui-billing-invoice-cards { display: none; }
            .agendaqui-billing-table-wrap { display: block; }
        }

        .dark .agendaqui-billing-hero,
        .dark .agendaqui-billing-card,
        .dark .agendaqui-billing-pix,
        .dark .agendaqui-billing-empty,
        .dark .agendaqui-billing-invoice-card,
        .dark .agendaqui-billing-table-wrap {
            background: rgb(15 23 42);
            border-color: rgb(51 65 85);
        }

        .dark .agendaqui-billing-hero--success { background: rgb(6 78 59 / 0.25); border-color: rgb(16 185 129 / 0.35); }
        .dark .agendaqui-billing-hero--warning { background: rgb(120 53 15 / 0.2); border-color: rgb(245 158 11 / 0.35); }
        .dark .agendaqui-billing-hero--danger { background: rgb(127 29 29 / 0.25); border-color: rgb(248 113 113 / 0.35); }
        .dark .agendaqui-billing-hero--gray { background: rgb(15 23 42); }
        .dark .agendaqui-billing-hero__title,
        .dark .agendaqui-billing-card__value,
        .dark .agendaqui-billing-section__title,
        .dark .agendaqui-billing-pix__amount,
        .dark .agendaqui-billing-pix__title,
        .dark .agendaqui-billing-invoice-card__amount,
        .dark .agendaqui-billing-table td {
            color: rgb(248 250 252);
        }

        .dark .agendaqui-billing-hero__hint,
        .dark .agendaqui-billing-card__label,
        .dark .agendaqui-billing-meta,
        .dark .agendaqui-billing-empty,
        .dark .agendaqui-billing-table th {
            color: rgb(148 163 184);
        }

        .dark .agendaqui-billing-chip {
            background: rgb(30 58 138 / 0.35);
            border-color: rgb(59 130 246 / 0.4);
            color: rgb(191 219 254);
        }

        .dark .agendaqui-billing-pix--due {
            background: rgb(120 53 15 / 0.2);
            border-color: rgb(245 158 11 / 0.35);
        }

        .dark .agendaqui-billing-table th {
            background: rgb(30 41 59);
        }

        .dark .agendaqui-billing-table td {
            border-top-color: rgb(51 65 85);
        }
    </style>
@endonce

<x-filament-panels::page>
<div class="agendaqui-billing">
    @if (! $accessAllowed)
        <div class="agendaqui-billing-hero agendaqui-billing-hero--{{ $statusTone }}">
            <div>
                <span class="{{ $badgeClass($statusTone) }}">{{ $statusLabel }}</span>
                <p class="agendaqui-billing-hero__title">Acesso temporariamente indisponível</p>
                <p class="agendaqui-billing-hero__hint">
                    O acesso à empresa <strong>{{ $company->name }}</strong> está temporariamente indisponível
                    porque o período de teste expirou ou a assinatura não está em dia.
                </p>
            </div>
        </div>
    @else
        <div class="agendaqui-billing-hero agendaqui-billing-hero--{{ $statusTone }}">
            <div>
                <span class="{{ $badgeClass($statusTone) }}">{{ $statusLabel }}</span>
                <p class="agendaqui-billing-hero__title">{{ $company->name }}</p>
                <p class="agendaqui-billing-hero__hint">{{ $statusHint }}</p>
            </div>
        </div>
    @endif

    <div class="agendaqui-billing-stats">
        <article class="agendaqui-billing-card">
            <p class="agendaqui-billing-card__label">Ciclo</p>
            <p class="agendaqui-billing-card__value {{ $intervalLabel ? '' : 'agendaqui-billing-card__value--muted' }}">{{ $intervalDisplay }}</p>
        </article>
        <article class="agendaqui-billing-card">
            <p class="agendaqui-billing-card__label">Valor vigente</p>
            <p class="agendaqui-billing-card__value {{ ($quotedPrice ?? '—') === '—' ? 'agendaqui-billing-card__value--muted' : '' }}">{{ $priceDisplay }}</p>
        </article>
        <article class="agendaqui-billing-card">
            <p class="agendaqui-billing-card__label">Vigente até</p>
            <p class="agendaqui-billing-card__value {{ $periodEnd ? '' : 'agendaqui-billing-card__value--muted' }}">{{ $periodDisplay }}</p>
        </article>
    </div>

    <section>
        <h3 class="agendaqui-billing-section__title">Módulos</h3>
        @if ($moduleLabels !== [])
            <div class="agendaqui-billing-chips">
                @foreach ($moduleLabels as $label)
                    <span class="agendaqui-billing-chip">{{ $label }}</span>
                @endforeach
            </div>
        @else
            <p class="agendaqui-billing-meta">Nenhum módulo selecionado.</p>
        @endif
    </section>

    @if ($canSeeInvoices)
        <section
            class="agendaqui-billing-pix {{ $outstanding ? 'agendaqui-billing-pix--due' : '' }}"
            x-data="{ copied: false }"
        >
            @if ($outstanding)
                <span class="{{ $badgeClass($outstanding->status->color()) }}">
                    {{ $outstanding->status->label() }}
                </span>
                <p class="agendaqui-billing-pix__title">
                    Fatura {{ $outstanding->number }}
                </p>
                <p class="agendaqui-billing-pix__amount">{{ $formatReais($outstanding->amount_cents) }}</p>
                <p class="agendaqui-billing-pix__due">
                    Pague até
                    <strong>{{ $outstanding->due_at?->timezone($timezone)->format('d/m/Y') }}</strong>
                    via PIX.
                </p>
            @else
                <p class="agendaqui-billing-section__title">Pagamento via PIX</p>
            @endif

            <p class="agendaqui-billing-hero__hint" x-ref="pixText">{{ $pixInstructions }}</p>

            <button
                type="button"
                class="agendaqui-billing-pix__copy"
                @click="navigator.clipboard.writeText($refs.pixText.textContent.trim()); copied = true; setTimeout(() => copied = false, 2000)"
                x-text="copied ? 'Copiado' : 'Copiar instrução'"
            >Copiar instrução</button>
        </section>
    @else
        <p class="agendaqui-billing-meta">Fale com o administrador da empresa para renovar a assinatura.</p>
    @endif

    @if ($canSeeInvoices)
        <section>
            <h3 class="agendaqui-billing-section__title">Faturas</h3>

            @if ($invoices->isEmpty())
                <div class="agendaqui-billing-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="6" width="18" height="13" rx="2" />
                        <path d="M3 10h18M7 15h4" stroke-linecap="round" />
                    </svg>
                    <p>Nenhuma fatura emitida ainda.</p>
                    <p>Quando a Agendaqui emitir a fatura, ela aparece aqui.</p>
                </div>
            @else
                <div class="agendaqui-billing-invoice-cards">
                    @foreach ($invoices as $invoice)
                        <article class="agendaqui-billing-invoice-card">
                            <div class="agendaqui-billing-invoice-card__row">
                                <strong>{{ $invoice->number }}</strong>
                                <span class="{{ $badgeClass($invoice->status->color()) }}">{{ $invoice->status->label() }}</span>
                            </div>
                            <p class="agendaqui-billing-invoice-card__amount">{{ $formatReais($invoice->amount_cents) }}</p>
                            <p class="agendaqui-billing-meta">
                                Vence {{ $invoice->due_at?->timezone($timezone)->format('d/m/Y') }}
                                · {{ $invoice->period_start?->timezone($timezone)->format('d/m/Y') }}
                                —
                                {{ $invoice->period_end?->timezone($timezone)->format('d/m/Y') }}
                            </p>
                        </article>
                    @endforeach
                </div>

                <div class="agendaqui-billing-table-wrap">
                    <table class="agendaqui-billing-table">
                        <thead>
                            <tr>
                                <th>Número</th>
                                <th>Status</th>
                                <th>Valor</th>
                                <th>Vencimento</th>
                                <th>Período</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoices as $invoice)
                                <tr>
                                    <td><strong>{{ $invoice->number }}</strong></td>
                                    <td>
                                        <span class="{{ $badgeClass($invoice->status->color()) }}">{{ $invoice->status->label() }}</span>
                                    </td>
                                    <td>{{ $formatReais($invoice->amount_cents) }}</td>
                                    <td>{{ $invoice->due_at?->timezone($timezone)->format('d/m/Y') }}</td>
                                    <td>
                                        {{ $invoice->period_start?->timezone($timezone)->format('d/m/Y') }}
                                        —
                                        {{ $invoice->period_end?->timezone($timezone)->format('d/m/Y') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @if (filled($company->email))
        <p class="agendaqui-billing-meta">
            E-mail cadastrado: <strong>{{ $company->email }}</strong>
        </p>
    @endif
</div>
</x-filament-panels::page>
