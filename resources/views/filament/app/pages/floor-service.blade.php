@php
    use App\Support\Money;

    $tables = $this->floorTables();
    $selected = $this->selectedTable();
    $order = $selected?->currentOrder();
    $catalog = $this->catalog();
    $selectedProduct = collect($catalog)->firstWhere('id', (int) $this->productId);
    $tablesUrl = $this->tablesIndexUrl();
    $canCreateTable = $this->canCreateTable();

    $areas = $this->areas($tables);
    $activeArea = array_key_exists($this->areaFilter, $areas) ? $this->areaFilter : \App\Filament\App\Pages\FloorServicePage::AREA_ALL;
    $visibleTables = $activeArea === \App\Filament\App\Pages\FloorServicePage::AREA_ALL
        ? $tables
        : $tables->filter(fn ($table) => $this->areaKey($table) === $activeArea)->values();
    $grouped = $visibleTables->groupBy(fn ($table) => $this->areaKey($table));
    $showGroupTitles = $activeArea === \App\Filament\App\Pages\FloorServicePage::AREA_ALL && count($areas) > 1;
    $summary = $this->summary($tables);
@endphp

<div class="fs-board" wire:poll.5s>
    <style>
        .fs-board {
            --fs-surface: #ffffff;
            --fs-soft: #f8fafc;
            --fs-border: rgb(226 232 240);
            --fs-text: #0f172a;
            --fs-muted: #64748b;
            --fs-primary: var(--primary-600, #2563eb);
            --fs-on-primary: #ffffff;
            --fs-free: #16a34a;
            --fs-free-soft: #f0fdf4;
            --fs-danger: #dc2626;
            --fs-shadow: 0 10px 40px rgb(15 23 42 / .18);
            display: flex; flex-direction: column; gap: .85rem; color: var(--fs-text);
        }
        .dark .fs-board {
            --fs-surface: #18181b;
            --fs-soft: rgb(255 255 255 / .05);
            --fs-border: rgb(255 255 255 / .12);
            --fs-text: #f4f4f5;
            --fs-muted: #a1a1aa;
            --fs-primary: var(--primary-500, #3b82f6);
            --fs-free: #4ade80;
            --fs-free-soft: rgb(74 222 128 / .08);
            --fs-danger: #f87171;
            --fs-shadow: 0 10px 40px rgb(0 0 0 / .6);
        }
        body.fs-lock { overflow: hidden; }

        .fs-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; }
        .fs-chips { display: flex; flex-wrap: wrap; gap: .4rem; }
        .fs-chip { display: inline-flex; align-items: baseline; gap: .35rem; padding: .3rem .7rem; border-radius: 999px; background: var(--fs-soft); border: 1px solid var(--fs-border); font-size: .78rem; color: var(--fs-muted); }
        .fs-chip b { font-size: .95rem; color: var(--fs-text); }
        .fs-chip--free b { color: var(--fs-free); }
        .fs-chip--busy b { color: var(--fs-primary); }
        .fs-spacer { flex: 1 1 auto; }
        .fs-tabs { display: flex; gap: .35rem; overflow-x: auto; padding-bottom: .15rem; scrollbar-width: thin; }
        .fs-tab { flex: 0 0 auto; border: 1px solid var(--fs-border); background: transparent; color: var(--fs-muted); border-radius: 999px; padding: .3rem .8rem; font-size: .8rem; font-weight: 600; cursor: pointer; white-space: nowrap; }
        .fs-tab:hover { color: var(--fs-text); }
        .fs-tab--active { background: var(--fs-primary); border-color: var(--fs-primary); color: var(--fs-on-primary); }
        .fs-tab small { opacity: .75; margin-left: .25rem; }

        .fs-newtable { position: relative; }
        .fs-pop { position: absolute; right: 0; top: calc(100% + .4rem); z-index: 20; width: min(18rem, 86vw); background: var(--fs-surface); border: 1px solid var(--fs-border); border-radius: .85rem; box-shadow: var(--fs-shadow); padding: .8rem; display: grid; gap: .55rem; }
        .fs-pop label { display: grid; gap: .2rem; font-size: .72rem; font-weight: 600; color: var(--fs-muted); }

        .fs-group-title { margin: .35rem 0 .45rem; font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--fs-muted); display: flex; align-items: center; gap: .5rem; }
        .fs-group-title::after { content: ""; flex: 1; height: 1px; background: var(--fs-border); }
        .fs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(6.25rem, 1fr)); gap: .5rem; }
        @media (min-width: 640px) { .fs-grid { grid-template-columns: repeat(auto-fill, minmax(7.25rem, 1fr)); gap: .6rem; } }

        .fs-tile { position: relative; display: flex; flex-direction: column; gap: .12rem; min-height: 5.6rem; padding: .5rem .6rem; text-align: left; cursor: pointer; border-radius: .8rem; border: 1px solid var(--fs-border); background: var(--fs-surface); color: var(--fs-text); transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease; min-width: 0; }
        .fs-tile:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgb(15 23 42 / .1); }
        .fs-tile--free { border-color: color-mix(in srgb, var(--fs-free) 45%, var(--fs-border)); background: var(--fs-free-soft); }
        .fs-tile--busy { background: var(--fs-primary); border-color: var(--fs-primary); color: var(--fs-on-primary); }
        .fs-tile--active { outline: 2px solid var(--fs-text); outline-offset: 2px; }
        .fs-tile__top { display: flex; align-items: baseline; justify-content: space-between; gap: .3rem; }
        .fs-tile__num { font-size: 1.55rem; font-weight: 800; line-height: 1; }
        .fs-tile__state { font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; opacity: .85; }
        .fs-tile--free .fs-tile__state { color: var(--fs-free); opacity: 1; }
        .fs-tile__area { font-size: .62rem; text-transform: uppercase; letter-spacing: .04em; opacity: .7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fs-tile__total { margin-top: auto; font-size: .95rem; font-weight: 800; }
        .fs-tile__meta { font-size: .68rem; opacity: .85; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fs-tile--free .fs-tile__meta { margin-top: auto; color: var(--fs-muted); opacity: 1; }
        .fs-tile__guest { font-size: .68rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .fs-empty { padding: 2rem 1.25rem; border: 1px dashed var(--fs-border); border-radius: 1rem; text-align: center; color: var(--fs-muted); display: grid; gap: .6rem; justify-items: center; }
        .fs-empty h2 { margin: 0; font-size: 1.05rem; color: var(--fs-text); }

        .fs-btn { display: inline-flex; align-items: center; justify-content: center; gap: .35rem; border: 1px solid transparent; border-radius: .65rem; padding: .55rem .95rem; font-weight: 700; font-size: .85rem; cursor: pointer; text-decoration: none; line-height: 1.1; }
        .fs-btn--primary { background: var(--fs-primary); color: var(--fs-on-primary); }
        .fs-btn--ghost { background: transparent; border-color: var(--fs-border); color: var(--fs-text); }
        .fs-btn--danger { background: transparent; border-color: color-mix(in srgb, var(--fs-danger) 45%, transparent); color: var(--fs-danger); }
        .fs-btn--sm { padding: .35rem .65rem; font-size: .78rem; }
        .fs-btn:disabled { opacity: .55; cursor: wait; }
        .fs-input { width: 100%; min-width: 0; border: 1px solid var(--fs-border); border-radius: .65rem; padding: .55rem .7rem; font: inherit; font-size: .9rem; background: var(--fs-surface); color: var(--fs-text); }
        .fs-input:focus { outline: 2px solid var(--fs-primary); outline-offset: -1px; }

        .fs-overlay { position: fixed; inset: 0; z-index: 45; }
        .fs-backdrop { position: absolute; inset: 0; background: rgb(15 23 42 / .5); backdrop-filter: blur(2px); animation: fs-fade .15s ease; }
        .fs-drawer { position: absolute; top: 0; right: 0; bottom: 0; width: min(100%, 34rem); display: flex; flex-direction: column; background: var(--fs-surface); box-shadow: var(--fs-shadow); animation: fs-slide .18s ease; max-height: 100dvh; }
        @media (max-width: 640px) { .fs-drawer { width: 100%; } }
        @keyframes fs-slide { from { transform: translateX(2rem); opacity: 0; } to { transform: none; opacity: 1; } }
        @keyframes fs-fade { from { opacity: 0; } to { opacity: 1; } }
        .fs-loading { position: absolute; top: 0; left: 0; right: 0; height: 3px; overflow: hidden; z-index: 2; }
        .fs-loading::before { content: ""; position: absolute; inset: 0; width: 40%; background: var(--fs-primary); animation: fs-bar 1s ease-in-out infinite; }
        @keyframes fs-bar { from { transform: translateX(-100%); } to { transform: translateX(260%); } }

        .fs-drawer__head { display: flex; align-items: flex-start; gap: .75rem; padding: .9rem 1rem .75rem; border-bottom: 1px solid var(--fs-border); }
        .fs-drawer__num { display: grid; place-items: center; min-width: 3rem; height: 3rem; border-radius: .8rem; font-size: 1.5rem; font-weight: 800; background: var(--fs-soft); border: 1px solid var(--fs-border); }
        .fs-drawer__num--busy { background: var(--fs-primary); border-color: var(--fs-primary); color: var(--fs-on-primary); }
        .fs-drawer__title { margin: 0; font-size: 1.05rem; font-weight: 800; line-height: 1.2; }
        .fs-muted { color: var(--fs-muted); font-size: .8rem; margin: .15rem 0 0; }
        .fs-close { margin-left: auto; border: 0; background: transparent; cursor: pointer; color: var(--fs-muted); font-size: 1.6rem; line-height: 1; padding: .1rem .4rem; border-radius: .5rem; }
        .fs-close:hover { background: var(--fs-soft); color: var(--fs-text); }
        .fs-drawer__body { flex: 1 1 auto; overflow-y: auto; padding: .85rem 1rem; display: flex; flex-direction: column; gap: 1rem; }
        .fs-drawer__foot { padding: .75rem 1rem calc(.75rem + env(safe-area-inset-bottom)); border-top: 1px solid var(--fs-border); background: var(--fs-surface); display: grid; gap: .6rem; }

        .fs-open-row { display: flex; gap: .5rem; }
        .fs-open-row .fs-btn { flex: 0 0 auto; }
        .fs-section-title { margin: 0 0 .4rem; font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--fs-muted); }
        .fs-lines { display: flex; flex-direction: column; border: 1px solid var(--fs-border); border-radius: .8rem; overflow: hidden; }
        .fs-line { display: grid; grid-template-columns: auto 1fr auto auto; gap: .6rem; align-items: center; padding: .5rem .65rem; }
        .fs-line + .fs-line { border-top: 1px solid var(--fs-border); }
        .fs-stepper { display: inline-flex; align-items: center; border: 1px solid var(--fs-border); border-radius: .6rem; overflow: hidden; }
        .fs-stepper button { width: 2rem; height: 2rem; border: 0; background: var(--fs-soft); color: var(--fs-text); font-size: 1.05rem; font-weight: 700; cursor: pointer; }
        .fs-stepper button:disabled { opacity: .35; cursor: not-allowed; }
        .fs-stepper span { min-width: 1.8rem; text-align: center; font-weight: 700; font-size: .88rem; }
        .fs-line__name { font-size: .88rem; font-weight: 600; min-width: 0; }
        .fs-line__note { font-size: .72rem; color: var(--fs-muted); font-weight: 400; }
        .fs-line__total { font-size: .85rem; font-weight: 700; white-space: nowrap; }
        .fs-icon-btn { border: 0; background: transparent; color: var(--fs-muted); cursor: pointer; width: 2rem; height: 2rem; border-radius: .5rem; font-size: 1rem; }
        .fs-icon-btn:hover { color: var(--fs-danger); background: var(--fs-soft); }
        .fs-line-empty { padding: .9rem; font-size: .85rem; color: var(--fs-muted); text-align: center; }

        .fs-cats { display: flex; gap: .35rem; overflow-x: auto; padding: .1rem 0 .4rem; scrollbar-width: thin; }
        .fs-products { display: grid; grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr)); gap: .5rem; }
        .fs-prod { position: relative; display: flex; align-items: center; gap: .55rem; min-height: 3.6rem; padding: .4rem .55rem; text-align: left; border: 1px solid var(--fs-border); border-radius: .75rem; background: var(--fs-surface); color: var(--fs-text); cursor: pointer; min-width: 0; }
        .fs-prod:hover { border-color: var(--fs-primary); }
        .fs-prod:active { transform: scale(.98); }
        .fs-prod__img { flex: 0 0 auto; width: 2.7rem; height: 2.7rem; border-radius: .55rem; object-fit: cover; background: var(--fs-soft); }
        .fs-prod__img--ph { display: grid; place-items: center; font-weight: 800; color: var(--fs-muted); }
        .fs-prod__txt { display: flex; flex-direction: column; min-width: 0; }
        .fs-prod__name { font-size: .8rem; font-weight: 600; line-height: 1.2; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .fs-prod__price { font-size: .75rem; color: var(--fs-muted); }
        .fs-prod__qty { position: absolute; top: -.35rem; right: -.3rem; min-width: 1.35rem; height: 1.35rem; padding: 0 .3rem; border-radius: 999px; background: var(--fs-primary); color: var(--fs-on-primary); font-size: .7rem; font-weight: 800; display: grid; place-items: center; }
        .fs-picker { border: 1px solid var(--fs-primary); border-radius: .8rem; padding: .7rem; display: grid; gap: .5rem; background: var(--fs-soft); }
        .fs-picker__opts { display: flex; flex-wrap: wrap; gap: .4rem; }

        .fs-total { display: flex; align-items: baseline; justify-content: space-between; font-weight: 800; font-size: 1.15rem; }
        .fs-total small { font-size: .75rem; font-weight: 600; color: var(--fs-muted); }
        .fs-pay { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .5rem; }
        .fs-links { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .fs-cancel { display: grid; gap: .45rem; padding: .6rem; border: 1px solid color-mix(in srgb, var(--fs-danger) 35%, var(--fs-border)); border-radius: .75rem; }
    </style>

    @if ($tables->isEmpty())
        <div class="fs-empty">
            <h2>Cadastre suas mesas</h2>
            <p>Nenhuma mesa ativa. Cadastre as mesas do salão para abrir comandas.</p>
            <div class="fs-links">
                @if ($tablesUrl)
                    <a class="fs-btn fs-btn--primary" href="{{ $tablesUrl }}">Ir para o cadastro de mesas</a>
                @endif
                @if ($canCreateTable)
                    <div class="fs-open-row" style="max-width: 24rem;">
                        <input class="fs-input" type="text" inputmode="numeric" wire:model="newTableNumber" wire:keydown.enter="createTable" placeholder="Nº da mesa (1)">
                        <input class="fs-input" type="text" wire:model="newTableArea" wire:keydown.enter="createTable" placeholder="Área (opcional)">
                        <button type="button" class="fs-btn fs-btn--ghost" wire:click="createTable" wire:loading.attr="disabled" wire:target="createTable">Criar mesa</button>
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="fs-toolbar">
            <div class="fs-chips">
                <span class="fs-chip fs-chip--free"><b>{{ $summary['free'] }}</b> Livres</span>
                <span class="fs-chip fs-chip--busy"><b>{{ $summary['busy'] }}</b> Ocupadas</span>
                <span class="fs-chip">Em aberto <b>{{ Money::formatCents($summary['total_cents']) }}</b></span>
            </div>
            <span class="fs-spacer"></span>

            @if ($canCreateTable)
                <div class="fs-newtable" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                    <button type="button" class="fs-btn fs-btn--ghost fs-btn--sm" x-on:click="open = ! open">+ Nova mesa</button>
                    <div class="fs-pop" x-show="open" x-cloak x-on:click.outside="open = false">
                        <label>Número
                            <input class="fs-input" type="text" inputmode="numeric" wire:model="newTableNumber" wire:keydown.enter="createTable" placeholder="Próximo número livre">
                        </label>
                        <label>Área (opcional)
                            <input class="fs-input" type="text" list="fs-areas" wire:model="newTableArea" wire:keydown.enter="createTable" placeholder="Varanda, Salão…">
                            <datalist id="fs-areas">
                                @foreach ($areas as $areaLabel)
                                    @if ($areaLabel !== 'Sem área')
                                        <option value="{{ $areaLabel }}"></option>
                                    @endif
                                @endforeach
                            </datalist>
                        </label>
                        <button type="button" class="fs-btn fs-btn--primary" wire:click="createTable" wire:loading.attr="disabled" wire:target="createTable">Criar mesa</button>
                    </div>
                </div>
            @endif
        </div>

        @if (count($areas) > 1)
            <div class="fs-tabs" role="tablist" aria-label="Filtrar por área">
                <button type="button" role="tab" class="fs-tab {{ $activeArea === 'todas' ? 'fs-tab--active' : '' }}" wire:click="setArea('todas')">Todas<small>{{ $tables->count() }}</small></button>
                @foreach ($areas as $areaKey => $areaLabel)
                    <button type="button" role="tab" class="fs-tab {{ $activeArea === $areaKey ? 'fs-tab--active' : '' }}" wire:click="setArea({{ \Illuminate\Support\Js::from($areaKey) }})" wire:key="area-{{ md5($areaKey) }}">
                        {{ $areaLabel }}<small>{{ $tables->filter(fn ($t) => $this->areaKey($t) === $areaKey)->count() }}</small>
                    </button>
                @endforeach
            </div>
        @endif

        @foreach ($grouped as $groupKey => $groupTables)
            <section wire:key="group-{{ md5((string) $groupKey) }}">
                @if ($showGroupTitles)
                    <h3 class="fs-group-title">{{ $this->areaLabel($groupTables->first()) }}</h3>
                @endif
                <div class="fs-grid">
                    @foreach ($groupTables as $table)
                        @php
                            $open = $table->currentOrder();
                            $guest = $open && $open->customer_name !== $table->displayLabel() ? $open->customer_name : null;
                        @endphp
                        <button
                            type="button"
                            wire:click="selectTable({{ $table->getKey() }})"
                            wire:key="tile-{{ $table->getKey() }}"
                            title="{{ $table->displayLabel() }}"
                            aria-label="{{ $table->displayLabel() }}"
                            class="fs-tile {{ $open ? 'fs-tile--busy' : 'fs-tile--free' }} {{ $selected?->is($table) ? 'fs-tile--active' : '' }}"
                        >
                            <span class="fs-tile__top">
                                <span class="fs-tile__num">{{ $table->number }}</span>
                                <span class="fs-tile__state">{{ $open ? 'Ocupada' : 'Livre' }}</span>
                            </span>
                            <span class="fs-tile__area">{{ $this->areaLabel($table) }}</span>
                            @if ($open)
                                @if ($guest)
                                    <span class="fs-tile__guest">{{ $guest }}</span>
                                @endif
                                <span class="fs-tile__total">{{ $open->formattedTotal() }}</span>
                                <span class="fs-tile__meta">{{ $this->itemCount($open) }} {{ $this->itemCount($open) === 1 ? 'item' : 'itens' }} · {{ $this->elapsedLabel($open) }}</span>
                            @else
                                <span class="fs-tile__meta">Toque para abrir</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif

    @if ($selected)
        @php
            $term = mb_strtolower(trim($this->search));
            $categories = collect($catalog)->pluck('category')->filter()->unique()->values();
            $products = collect($catalog)->filter(
                fn ($p) => ($term === '' || str_contains(mb_strtolower($p['name']), $term))
                    && ($this->categoryFilter === '' || $p['category'] === $this->categoryFilter)
            )->values();
            $qtyByProduct = $order ? $order->items->groupBy('product_id')->map(fn ($items) => (int) $items->sum('quantity')) : collect();
            $guestLabel = $order && $order->customer_name !== $selected->displayLabel() ? $order->customer_name : null;
        @endphp

        <div
            class="fs-overlay"
            wire:key="fs-drawer-{{ $selected->getKey() }}"
            x-data="{ init() { document.body.classList.add('fs-lock') }, destroy() { document.body.classList.remove('fs-lock') } }"
            x-on:keydown.escape.window="$wire.closeDrawer()"
        >
            <div class="fs-backdrop" wire:click="closeDrawer"></div>

            <aside class="fs-drawer" role="dialog" aria-modal="true" aria-label="{{ $selected->displayLabel() }}">
                <div class="fs-loading" wire:loading wire:target="selectTable,openTab,quickAdd,pickProduct,incrementItem,decrementItem,removeItem,closeTab,cancelTab,addItem"></div>

                <header class="fs-drawer__head">
                    <span class="fs-drawer__num {{ $order ? 'fs-drawer__num--busy' : '' }}">{{ $selected->number }}</span>
                    <div>
                        <h2 class="fs-drawer__title">{{ $selected->displayLabel() }}</h2>
                        @if ($order)
                            <p class="fs-muted">
                                Comanda {{ $order->displayNumber() }}
                                @if ($guestLabel) · {{ $guestLabel }} @endif
                                · aberta há {{ $this->elapsedLabel($order) }}
                            </p>
                        @else
                            <p class="fs-muted">Mesa livre. Abra a comanda para começar a anotar os pedidos.</p>
                        @endif
                    </div>
                    <button type="button" class="fs-close" wire:click="closeDrawer" aria-label="Fechar">&times;</button>
                </header>

                @if ($order === null)
                    <div class="fs-drawer__body">
                        <div class="fs-open-row">
                            <input class="fs-input" type="text" wire:model="guestName" wire:keydown.enter="openTab" maxlength="255" placeholder="Nome do cliente (opcional)" autofocus>
                            <button type="button" class="fs-btn fs-btn--primary" wire:click="openTab" wire:loading.attr="disabled" wire:target="openTab">Abrir comanda</button>
                        </div>
                    </div>
                @else
                    <div class="fs-drawer__body">
                        <div>
                            <h3 class="fs-section-title">Itens da comanda ({{ $this->itemCount($order) }})</h3>
                            <div class="fs-lines">
                                @forelse ($order->items as $item)
                                    <div class="fs-line" wire:key="line-{{ $item->getKey() }}">
                                        <div class="fs-stepper">
                                            <button type="button" wire:click="decrementItem({{ $item->getKey() }})" wire:loading.attr="disabled" wire:target="decrementItem,incrementItem,removeItem" @disabled($item->quantity <= 1) aria-label="Diminuir">−</button>
                                            <span>{{ $item->quantity }}</span>
                                            <button type="button" wire:click="incrementItem({{ $item->getKey() }})" wire:loading.attr="disabled" wire:target="decrementItem,incrementItem,removeItem" @disabled($item->quantity >= 99) aria-label="Aumentar">+</button>
                                        </div>
                                        <div class="fs-line__name">
                                            {{ $item->name }}
                                            @if (filled($item->notes))
                                                <div class="fs-line__note">{{ $item->notes }}</div>
                                            @endif
                                        </div>
                                        <span class="fs-line__total">{{ $item->formattedLineTotal() }}</span>
                                        <button type="button" class="fs-icon-btn" wire:click="removeItem({{ $item->getKey() }})" wire:loading.attr="disabled" wire:target="removeItem" title="Remover item" aria-label="Remover {{ $item->name }}">🗑</button>
                                    </div>
                                @empty
                                    <p class="fs-line-empty">Nenhum item ainda. O que for lançado aparece na cozinha.</p>
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <h3 class="fs-section-title">Adicionar</h3>
                            <div style="display:grid; gap:.5rem;">
                                <input class="fs-input" type="search" wire:model.live.debounce.250ms="search" placeholder="Buscar produto…" autocomplete="off">

                                @if ($categories->count() > 1)
                                    <div class="fs-cats">
                                        <button type="button" class="fs-tab {{ $this->categoryFilter === '' ? 'fs-tab--active' : '' }}" wire:click="setCategory('')">Todos</button>
                                        @foreach ($categories as $category)
                                            <button type="button" class="fs-tab {{ $this->categoryFilter === $category ? 'fs-tab--active' : '' }}" wire:click="setCategory({{ \Illuminate\Support\Js::from($category) }})" wire:key="cat-{{ md5($category) }}">{{ $category }}</button>
                                        @endforeach
                                    </div>
                                @endif

                                <input class="fs-input" type="text" wire:model="itemNotes" maxlength="240" placeholder="Observação para o próximo item (ex.: sem gelo, ponto da carne)">

                                @if ($selectedProduct && $selectedProduct['variants'] !== [])
                                    <div class="fs-picker">
                                        <strong style="font-size:.85rem;">{{ $selectedProduct['name'] }}: escolha o tamanho</strong>
                                        <div class="fs-picker__opts">
                                            @foreach ($selectedProduct['variants'] as $variant)
                                                <button type="button" class="fs-btn fs-btn--primary" wire:click="quickAdd({{ $selectedProduct['id'] }}, {{ $variant['id'] }})" wire:loading.attr="disabled" wire:target="quickAdd" wire:key="variant-{{ $variant['id'] }}">
                                                    {{ $variant['name'] }} · {{ $variant['price'] }}
                                                </button>
                                            @endforeach
                                            <button type="button" class="fs-btn fs-btn--ghost" wire:click="$set('productId', '')">Cancelar</button>
                                        </div>
                                    </div>
                                @endif

                                @if ($products->isEmpty())
                                    <p class="fs-line-empty">Nenhum produto encontrado.</p>
                                @else
                                    <div class="fs-products">
                                        @foreach ($products as $product)
                                            <button type="button" class="fs-prod" wire:click="pickProduct({{ $product['id'] }})" wire:loading.attr="disabled" wire:target="pickProduct,quickAdd" wire:key="prod-{{ $product['id'] }}">
                                                @if ($product['image'])
                                                    <img class="fs-prod__img" src="{{ $product['image'] }}" alt="" loading="lazy">
                                                @else
                                                    <span class="fs-prod__img fs-prod__img--ph">{{ mb_strtoupper(mb_substr($product['name'], 0, 1)) }}</span>
                                                @endif
                                                <span class="fs-prod__txt">
                                                    <span class="fs-prod__name">{{ $product['name'] }}</span>
                                                    <span class="fs-prod__price">{{ $product['variants'] !== [] ? 'a partir de ' : '' }}{{ $product['price'] }}</span>
                                                </span>
                                                @if (($qtyByProduct[$product['id']] ?? 0) > 0)
                                                    <span class="fs-prod__qty">{{ $qtyByProduct[$product['id']] }}</span>
                                                @endif
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <footer class="fs-drawer__foot" x-data="{ cancel: false }">
                        <div class="fs-total">
                            <span>Total <small>· os itens vão direto para a cozinha</small></span>
                            <span>{{ $order->formattedTotal() }}</span>
                        </div>

                        <div class="fs-pay">
                            <select class="fs-input" wire:model="paymentMethod" aria-label="Forma de pagamento">
                                @foreach ($this->paymentOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <button type="button" class="fs-btn fs-btn--primary" wire:click="closeTab" wire:confirm="Fechar a conta de {{ $selected->displayLabel() }} ({{ $order->formattedTotal() }})?" wire:loading.attr="disabled" wire:target="closeTab">Fechar conta</button>
                        </div>

                        <div class="fs-links">
                            @if ($this->receiptUrl($order))
                                <a class="fs-btn fs-btn--ghost fs-btn--sm" href="{{ $this->receiptUrl($order) }}" target="_blank" rel="noopener">Imprimir conferência</a>
                            @endif
                            <button type="button" class="fs-btn fs-btn--danger fs-btn--sm" x-on:click="cancel = ! cancel">Cancelar comanda</button>
                        </div>

                        <div class="fs-cancel" x-show="cancel" x-cloak>
                            <input class="fs-input" type="text" wire:model="cancelReason" maxlength="500" placeholder="Motivo do cancelamento (obrigatório se já houver itens)">
                            <button type="button" class="fs-btn fs-btn--danger" wire:click="cancelTab" wire:confirm="Cancelar a comanda de {{ $selected->displayLabel() }}?" wire:loading.attr="disabled" wire:target="cancelTab">Confirmar cancelamento</button>
                        </div>
                    </footer>
                @endif
            </aside>
        </div>
    @endif
</div>