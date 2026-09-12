@php
    use App\Enums\OrderStatus;
    use App\Support\CompanyDateTime;
    use App\Support\Money;
    use Filament\Facades\Filament;

    $company = Filament::getTenant();
    $columns = $this->kitchenOrders();
@endphp

<div
    class="kitchen-display"
    wire:poll.4s
>
    <style>
        .kitchen-display {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
            gap: 1rem;
            min-height: calc(100dvh - 10rem);
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
    </style>

    @foreach (OrderStatus::kitchenColumns() as $status)
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
                        <span>{{ $order->fulfillment?->label() }}</span>
                    </div>
                    <p class="kitchen-card__meta">
                        {{ $order->customer_name }}
                        · {{ $order->created_at ? CompanyDateTime::formatLocal($company, $order->created_at, 'H:i') : '' }}
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
                    @if ($order->fulfillment?->usesDeliveryAddress() && filled($order->formattedDeliveryAddress()))
                        <p class="kitchen-card__notes">{{ $order->formattedDeliveryAddress() }}</p>
                    @endif
                    <div class="kitchen-card__actions">
                        @if ($order->canAdvance() && auth()->user()?->can('advance', $order))
                            <x-filament::button
                                size="sm"
                                wire:click="advanceOrder({{ $order->getKey() }})"
                                wire:loading.attr="disabled"
                            >
                                {{ $order->nextStatus()?->label() }}
                            </x-filament::button>
                        @endif
                        @if ($order->canCancel() && auth()->user()?->can('cancel', $order))
                            <x-filament::button
                                size="sm"
                                color="danger"
                                outlined
                                wire:click="startCancel({{ $order->getKey() }})"
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
                    <x-filament::button color="gray" wire:click="dismissCancel">Voltar</x-filament::button>
                    <x-filament::button color="danger" wire:click="confirmCancel">Confirmar cancelamento</x-filament::button>
                </div>
            </div>
        </div>
    @endif
</div>
