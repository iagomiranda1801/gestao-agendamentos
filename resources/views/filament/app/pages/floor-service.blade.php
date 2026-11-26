@php
    use App\Support\Money;

    $tables = $this->floorTables();
    $selected = $this->selectedTable();
    $order = $selected?->currentOrder();
    $catalog = $this->catalog();
    $selectedProduct = collect($catalog)->firstWhere('id', (int) $this->productId);
    $tablesUrl = $this->tablesIndexUrl();
@endphp

<div class="floor-board" wire:poll.8s>
    <style>
        .floor-board { display: flex; flex-direction: column; gap: 1rem; }
        .floor-layout { display: grid; grid-template-columns: minmax(16rem, 1.1fr) minmax(20rem, 1.4fr); gap: 1rem; align-items: start; }
        @media (max-width: 960px) { .floor-layout { grid-template-columns: 1fr; } }
        .floor-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr)); gap: 0.75rem; }
        .floor-card, .floor-panel { background: white; border: 1px solid rgb(226 232 240); border-radius: 1rem; }
        .floor-card { padding: 0.85rem; text-align: left; cursor: pointer; display: flex; flex-direction: column; gap: 0.35rem; }
        .floor-card:hover { border-color: rgb(148 163 184); }
        .floor-card--selected { border-color: rgb(15 23 42); box-shadow: 0 0 0 1px rgb(15 23 42); }
        .floor-card__name { font-weight: 700; color: rgb(15 23 42); }
        .floor-card__meta { font-size: 0.8rem; color: rgb(71 85 105); }
        .floor-badge { align-self: flex-start; border-radius: 999px; padding: 0.1rem 0.55rem; font-size: 0.75rem; font-weight: 700; }
        .floor-badge--free { background: rgb(220 252 231); color: rgb(22 101 52); }
        .floor-badge--busy { background: rgb(254 243 199); color: rgb(146 64 14); }
        .floor-panel { padding: 1rem 1.1rem; display: flex; flex-direction: column; gap: 0.9rem; }
        .floor-panel h2 { margin: 0; font-size: 1.15rem; font-weight: 700; }
        .floor-muted { color: rgb(71 85 105); font-size: 0.9rem; margin: 0; }
        .floor-items { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        .floor-items th { text-align: left; font-size: 0.75rem; color: rgb(100 116 139); font-weight: 600; padding-bottom: 0.35rem; }
        .floor-items td { border-top: 1px solid rgb(241 245 249); padding: 0.45rem 0.25rem 0.45rem 0; vertical-align: top; }
        .floor-items .num { text-align: right; white-space: nowrap; }
        .floor-total { display: flex; justify-content: space-between; font-weight: 800; font-size: 1.05rem; }
        .floor-form { display: grid; gap: 0.55rem; }
        .floor-form label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.8rem; font-weight: 600; color: rgb(51 65 85); }
        .floor-form input, .floor-form select, .floor-form textarea { border: 1px solid rgb(203 213 225); border-radius: 0.6rem; padding: 0.45rem 0.6rem; font: inherit; background: white; }
        .floor-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .floor-btn { border: 0; border-radius: 0.65rem; padding: 0.5rem 0.85rem; font-weight: 700; cursor: pointer; }
        .floor-btn--primary { background: rgb(15 23 42); color: white; }
        .floor-btn--ghost { background: white; border: 1px solid rgb(203 213 225); color: rgb(15 23 42); }
        .floor-btn--danger { background: white; border: 1px solid rgb(252 165 165); color: rgb(185 28 28); }
        .floor-btn:disabled { opacity: 0.55; cursor: wait; }
        .floor-empty { padding: 1.5rem; border: 1px dashed rgb(203 213 225); border-radius: 1rem; color: rgb(71 85 105); }
        .floor-note { color: rgb(100 116 139); font-size: 0.8rem; }
    </style>

    @if ($tables->isEmpty())
        <div class="floor-empty">
            <p>Nenhuma mesa ativa. Cadastre as mesas do salão para abrir comandas.</p>
            @if ($tablesUrl)
                <p><a href="{{ $tablesUrl }}">Ir para o cadastro de mesas</a></p>
            @endif
        </div>
    @else
        <div class="floor-layout">
            <div class="floor-grid">
                @foreach ($tables as $table)
                    @php $open = $table->currentOrder(); @endphp
                    <button
                        type="button"
                        wire:click="selectTable({{ $table->getKey() }})"
                        class="floor-card {{ $selected?->is($table) ? 'floor-card--selected' : '' }}"
                    >
                        <span class="floor-badge {{ $open ? 'floor-badge--busy' : 'floor-badge--free' }}">
                            {{ $open ? 'Ocupada' : 'Livre' }}
                        </span>
                        <span class="floor-card__name">{{ $table->displayLabel() }}</span>
                        <span class="floor-card__meta">
                            {{ $open ? Money::formatCents((int) $open->total_cents) : 'Sem comanda' }}
                        </span>
                    </button>
                @endforeach
            </div>

            <section class="floor-panel">
                @if ($selected === null)
                    <h2>Salão</h2>
                    <p class="floor-muted">Escolha uma mesa para abrir a comanda ou lançar pedidos.</p>
                @elseif ($order === null)
                    <h2>{{ $selected->displayLabel() }}</h2>
                    <p class="floor-muted">Mesa livre. Abra a comanda para começar a anotar os pedidos.</p>
                    <div class="floor-form">
                        <label>
                            Nome do cliente
                            <input type="text" wire:model="guestName" maxlength="255" placeholder="Opcional">
                        </label>
                    </div>
                    <div class="floor-actions">
                        <button type="button" class="floor-btn floor-btn--primary" wire:click="openTab" wire:loading.attr="disabled" wire:target="openTab">Abrir comanda</button>
                    </div>
                @else
                    <div>
                        <h2>{{ $selected->displayLabel() }}</h2>
                        <p class="floor-muted">
                            Comanda {{ $order->displayNumber() }}
                            @if ($order->customer_name !== $selected->displayLabel())
                                · {{ $order->customer_name }}
                            @endif
                        </p>
                    </div>

                    @if ($order->items->isEmpty())
                        <p class="floor-muted">Nenhum item ainda. O que for lançado aparece na cozinha.</p>
                    @else
                        <table class="floor-items">
                            <thead>
                                <tr>
                                    <th>Qtd</th>
                                    <th>Item</th>
                                    <th class="num">Total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->items as $item)
                                    <tr>
                                        <td>{{ $item->quantity }}</td>
                                        <td>
                                            {{ $item->name }}
                                            @if (filled($item->notes))
                                                <div class="floor-note">{{ $item->notes }}</div>
                                            @endif
                                        </td>
                                        <td class="num">{{ $item->formattedLineTotal() }}</td>
                                        <td class="num">
                                            <button type="button" class="floor-btn floor-btn--danger" wire:click="removeItem({{ $item->getKey() }})" wire:loading.attr="disabled" wire:target="removeItem">Remover</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <div class="floor-total">
                            <span>Total em aberto</span>
                            <span>{{ $order->formattedTotal() }}</span>
                        </div>
                    @endif

                    <div class="floor-form">
                        <label>
                            Produto
                            <select wire:model.live="productId">
                                <option value="">Selecione</option>
                                @foreach ($catalog as $product)
                                    <option value="{{ $product['id'] }}">{{ $product['name'] }} — {{ $product['price'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if ($selectedProduct && $selectedProduct['variants'] !== [])
                            <label>
                                Tamanho
                                <select wire:model="variantId">
                                    <option value="">Selecione</option>
                                    @foreach ($selectedProduct['variants'] as $variant)
                                        <option value="{{ $variant['id'] }}">{{ $variant['name'] }} — {{ $variant['price'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                        <label>
                            Quantidade
                            <input type="number" min="1" max="99" wire:model="quantity">
                        </label>
                        <label>
                            Observação
                            <input type="text" wire:model="itemNotes" maxlength="240" placeholder="Ex.: sem gelo, ponto da carne">
                        </label>
                    </div>
                    <div class="floor-actions">
                        <button type="button" class="floor-btn floor-btn--primary" wire:click="addItem" wire:loading.attr="disabled" wire:target="addItem">Lançar item</button>
                        @if ($this->receiptUrl($order))
                            <a class="floor-btn floor-btn--ghost" href="{{ $this->receiptUrl($order) }}" target="_blank" rel="noopener">Imprimir conferência</a>
                        @endif
                    </div>

                    <div class="floor-form">
                        <label>
                            Forma de pagamento
                            <select wire:model="paymentMethod">
                                @foreach ($this->paymentOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="floor-actions">
                        <button type="button" class="floor-btn floor-btn--primary" wire:click="closeTab" wire:loading.attr="disabled" wire:target="closeTab">Fechar conta</button>
                    </div>

                    <div class="floor-form">
                        <label>
                            Motivo do cancelamento
                            <input type="text" wire:model="cancelReason" maxlength="500" placeholder="Obrigatório se já houver itens">
                        </label>
                    </div>
                    <div class="floor-actions">
                        <button type="button" class="floor-btn floor-btn--danger" wire:click="cancelTab" wire:loading.attr="disabled" wire:target="cancelTab">Cancelar comanda</button>
                    </div>
                @endif
            </section>
        </div>
    @endif
</div>
