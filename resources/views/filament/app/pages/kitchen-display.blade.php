@php
    use App\Enums\OrderStatus;
    use App\Support\CompanyDateTime;
    use App\Support\Money;
    use Filament\Facades\Filament;

    $company = Filament::getTenant();
    $columns = $this->kitchenOrders();
    $visibleStatuses = $this->visibleKitchenColumns();
    $companyIsTenant = $company instanceof \App\Models\Company;
@endphp

<div
    class="kitchen-board"
    wire:poll.4s
>
    <style>
        .kitchen-board {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .kitchen-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .kitchen-tab {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: 1px solid rgb(226 232 240);
            background: white;
            border-radius: 999px;
            padding: 0.4rem 0.85rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: rgb(51 65 85);
            cursor: pointer;
        }

        .kitchen-tab:hover {
            border-color: rgb(148 163 184);
        }

        .kitchen-tab--active {
            background: rgb(15 23 42);
            color: white;
            border-color: rgb(15 23 42);
        }

        .kitchen-tab__count {
            font-size: 0.75rem;
            font-weight: 700;
            background: rgb(241 245 249);
            color: rgb(51 65 85);
            border-radius: 999px;
            padding: 0.05rem 0.45rem;
        }

        .kitchen-tab--active .kitchen-tab__count {
            background: rgb(255 255 255 / 0.18);
            color: white;
        }

        .kitchen-display {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
            gap: 1rem;
            min-height: calc(100dvh - 13rem);
        }

        .kitchen-column {
            background: rgb(248 250 252);
            border: 1px solid rgb(226 232 240);
            border-radius: 1rem;
            padding: 0.85rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            min-height: 20rem;
        }

        .kitchen-column__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .kitchen-column__title {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
        }

        .kitchen-column__count {
            font-size: 0.75rem;
            background: white;
            border-radius: 999px;
            padding: 0.15rem 0.5rem;
            color: rgb(71 85 105);
        }

        .kitchen-card {
            background: white;
            border-radius: 0.85rem;
            border: 1px solid rgb(226 232 240);
            padding: 0.85rem;
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
            box-shadow: 0 1px 2px rgb(15 23 42 / 0.06);
        }

        .kitchen-card__top {
            display: flex;
            justify-content: space-between;
            gap: 0.5rem;
            align-items: baseline;
        }

        .kitchen-card__number {
            font-size: 1.25rem;
            font-weight: 800;
        }

        .kitchen-card__meta,
        .kitchen-card__items,
        .kitchen-card__notes {
            margin: 0;
            font-size: 0.875rem;
            color: rgb(51 65 85);
        }

        .kitchen-card__items {
            padding-left: 1.1rem;
        }

        .kitchen-card__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }

        .kitchen-empty {
            color: rgb(148 163 184);
            font-size: 0.875rem;
            margin: 0.5rem 0 0;
        }

        .kitchen-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            border-radius: 999px;
            padding: 0.15rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 700;
            line-height: 1.2;
            white-space: nowrap;
        }

        .kitchen-badge__icon {
            width: 0.85rem;
            height: 0.85rem;
            flex-shrink: 0;
        }

        .kitchen-badge--info {
            background: rgb(239 246 255);
            color: rgb(29 78 216);
        }

        .kitchen-badge--warning {
            background: rgb(255 251 235);
            color: rgb(180 83 9);
        }

        .kitchen-badge--success {
            background: rgb(236 253 245);
            color: rgb(21 128 61);
        }
    </style>

    <div class="kitchen-tabs" role="tablist" aria-label="Filtrar pedidos por tipo">
        @foreach ($this->fulfillmentTabs() as $tab)
            <button
                type="button"
                role="tab"
                class="kitchen-tab {{ $this->fulfillmentFilter === $tab['value'] ? 'kitchen-tab--active' : '' }}"
                wire:key="kitchen-tab-{{ $tab['value'] }}"
                wire:click.prevent="setFulfillmentFilter('{{ $tab['value'] }}')"
                aria-selected="{{ $this->fulfillmentFilter === $tab['value'] ? 'true' : 'false' }}"
            >
                {{ $tab['label'] }}
                <span class="kitchen-tab__count">{{ $tab['count'] }}</span>
            </button>
        @endforeach
    </div>

    <div class="kitchen-display">
    @foreach ($visibleStatuses as $status)
        @php
            $orders = $columns[$status->value] ?? collect();
        @endphp
        <section class="kitchen-column" wire:key="kitchen-col-{{ $status->value }}">
            <header class="kitchen-column__header">
                <h2 class="kitchen-column__title">{{ $status->label() }}</h2>
                <span class="kitchen-column__count">{{ $orders->count() }}</span>
            </header>

            @forelse ($orders as $order)
                <article class="kitchen-card" wire:key="kitchen-order-{{ $order->getKey() }}">
                    <div class="kitchen-card__top">
                        <span class="kitchen-card__number">{{ $order->displayNumber() }}</span>
                        @if ($order->fulfillment)
                            <span class="kitchen-badge kitchen-badge--{{ $order->fulfillment->color() }}">
                                @if ($order->fulfillment === \App\Enums\OrderFulfillment::Delivery)
                                    <svg class="kitchen-badge__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7h11v10H3zM14 11h4.5L21 14.5V17h-7M6.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm9 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>
                                    </svg>
                                @elseif ($order->fulfillment === \App\Enums\OrderFulfillment::Pickup)
                                    <svg class="kitchen-badge__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 8h12l-1 12H7L6 8Zm3 0V7a3 3 0 0 1 6 0v1"/>
                                    </svg>
                                @else
                                    <svg class="kitchen-badge__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 3v7a2 2 0 1 0 4 0V3M7 3v0M9 3v7M11 3v7M9 10v11M16 3c1.5 1 2 3.2 2 5.2V21M16 8h2"/>
                                    </svg>
                                @endif
                                {{ $order->fulfillment->label() }}
                            </span>
                        @endif
                    </div>
                    <p class="kitchen-card__meta">
                        {{ $order->customer_name }}
                        · {{ $order->created_at && $companyIsTenant ? CompanyDateTime::formatLocal($company, $order->created_at, 'H:i') : '' }}
                        · {{ Money::formatCents((int) $order->total_cents) }}
                    </p>
                    <ul class="kitchen-card__items">
                        @foreach ($order->items as $item)
                            <li>
                                {{ $item->quantity }}× {{ $item->name }}
                                @if (filled($item->notes))
                                    <em>({{ $item->notes }})</em>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if (filled($order->notes))
                        <p class="kitchen-card__notes">Obs.: {{ $order->notes }}</p>
                    @endif
                    @if (filled($order->kitchenFulfillmentDetail()))
                        <p class="kitchen-card__notes">{{ $order->kitchenFulfillmentDetail() }}</p>
                    @endif
                    @if ($order->isFloorOrder() && ! $order->canAdvance() && $order->status === OrderStatus::Ready)
                        <p class="kitchen-card__notes">Feche a conta na tela de Comandas.</p>
                    @endif
                    <div class="kitchen-card__actions">
                        @if ($order->canAdvance() && auth()->user()?->can('advance', $order))
                            <x-filament::button
                                type="button"
                                size="sm"
                                wire:click.prevent="advanceOrder({{ $order->getKey() }})"
                                wire:loading.attr="disabled"
                            >
                                {{ $order->nextStatus()?->label() }}
                            </x-filament::button>
                        @endif
                        @if ($order->canCancel() && auth()->user()?->can('cancel', $order))
                            <x-filament::button
                                type="button"
                                size="sm"
                                color="danger"
                                outlined
                                wire:click.prevent="startCancel({{ $order->getKey() }})"
                            >
                                Cancelar
                            </x-filament::button>
                        @endif
                    </div>
                </article>
            @empty
                <p class="kitchen-empty">Nenhum pedido nesta etapa.</p>
            @endforelse
        </section>
    @endforeach
    </div>

    @if ($this->cancelingOrderId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
                <h3 class="text-lg font-semibold">Cancelar pedido</h3>
                <p class="mt-1 text-sm text-slate-600">Informe o motivo do cancelamento.</p>
                <textarea
                    wire:model="cancelReason"
                    class="mt-3 w-full rounded-lg border border-slate-300 p-2 text-sm"
                    rows="3"
                ></textarea>
                @error('cancelReason')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
                <div class="mt-4 flex justify-end gap-2">
                    <x-filament::button type="button" color="gray" wire:click.prevent="dismissCancel">Voltar</x-filament::button>
                    <x-filament::button type="button" color="danger" wire:click.prevent="confirmCancel">Confirmar cancelamento</x-filament::button>
                </div>
            </div>
        </div>
    @endif
</div>
