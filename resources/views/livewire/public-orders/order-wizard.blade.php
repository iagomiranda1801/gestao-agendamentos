<div class="booking-wizard">
    <x-public-booking.step-indicator :steps="$steps" :current="$step" />

    <div class="booking-card">
        <div class="booking-card__header">
            @if ($step === \App\Livewire\PublicOrders\OrderWizard::STEP_CONFIRMATION)
                <h1 class="booking-title">Pedido enviado</h1>
                <p class="booking-subtitle">Guarde o código para acompanhar o pedido.</p>
            @else
                <h1 class="booking-title">{{ e($pageTitle) }}</h1>
                @if (filled($settings?->page_description))
                    <p class="booking-subtitle">{{ e($settings->page_description) }}</p>
                @endif
            @endif
        </div>

        <div class="booking-card__body">
            @if ($errorMessage)
                <div class="booking-alert booking-alert--error" role="alert">
                    {{ e($errorMessage) }}
                </div>
            @endif

            <div class="booking-honeypot" aria-hidden="true">
                <label for="website_url">Website</label>
                <input id="website_url" type="text" wire:model="website_url" tabindex="-1" autocomplete="off">
            </div>

            @if ($hours !== [])
                <p class="booking-subtitle" style="margin-bottom: 1rem;">
                    Horário: {{ e(implode(' · ', $hours)) }}
                </p>
            @endif

            @if ($step === \App\Livewire\PublicOrders\OrderWizard::STEP_MENU)
                @if ($groupedProducts->isEmpty())
                    <div class="booking-empty">Nenhum item disponível no cardápio online no momento.</div>
                @else
                    @foreach ($groupedProducts as $category => $products)
                        <h2 class="booking-title" style="font-size: 1.1rem; margin: 1rem 0 0.6rem;">{{ e($category) }}</h2>
                        <div class="booking-option-list">
                            @foreach ($products as $product)
                                @php
                                    $variants = $product->activeVariants;
                                    $hasVariants = $variants->isNotEmpty();
                                    $selectedVariantId = $hasVariants
                                        ? (int) ($this->selectedVariant[$product->getKey()] ?? $variants->firstWhere('is_default', true)?->id ?? $variants->first()->id)
                                        : 0;
                                    $cartKey = $product->getKey().':'.$selectedVariantId;
                                    $qty = $cart[$cartKey]['quantity'] ?? 0;
                                    $displayCents = $hasVariants
                                        ? \App\Support\Money::toCents($variants->firstWhere('id', $selectedVariantId)?->price ?? $variants->min('price'))
                                        : \App\Support\Money::toCents($product->sale_price);
                                    $showFrom = $hasVariants
                                        && $variants->count() > 1
                                        && (string) $variants->min('price') !== (string) $variants->max('price')
                                        && $qty < 1;
                                    $otherCartedSizes = collect($this->cartedSizesForProduct($product))
                                        ->reject(fn (array $line): bool => (int) $line['variant_id'] === $selectedVariantId)
                                        ->values();
                                @endphp
                                <div class="booking-option booking-option--menu" wire:key="menu-{{ $product->getKey() }}">
                                    <div class="booking-option__row">
                                        <div class="booking-option__content">
                                            <p class="booking-option__title">{{ e($product->name) }}</p>
                                            @if (filled($product->description))
                                                <p class="booking-option__subtitle">{{ e($product->description) }}</p>
                                            @endif
                                            <p class="booking-option__subtitle">
                                                @if ($showFrom)
                                                    A partir de {{ $this->formatMoneyCents(\App\Support\Money::toCents($variants->min('price'))) }}
                                                @else
                                                    {{ $this->formatMoneyCents($displayCents) }}
                                                @endif
                                                @if ($product->prep_time_minutes)
                                                    · {{ $product->prep_time_minutes }} min
                                                @endif
                                            </p>
                                        </div>
                                        <div class="order-item-actions">
                                            @if ($hasVariants)
                                                <select
                                                    class="booking-select order-size-select"
                                                    wire:model.live="selectedVariant.{{ $product->getKey() }}"
                                                >
                                                    @foreach ($variants as $variant)
                                                        <option value="{{ $variant->getKey() }}">
                                                            {{ e($variant->name) }} — {{ $this->formatMoneyCents(\App\Support\Money::toCents($variant->price)) }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            @endif
                                            <div class="order-qty">
                                                @if ($qty > 0)
                                                    <button type="button" class="booking-btn booking-btn--secondary" wire:click="decrementItem('{{ $cartKey }}')">−</button>
                                                    <span>{{ $qty }}</span>
                                                    <button type="button" class="booking-btn booking-btn--secondary" wire:click="incrementItem('{{ $cartKey }}')">+</button>
                                                @else
                                                    <button type="button" class="booking-btn booking-btn--primary" wire:click="addToCart({{ $product->getKey() }})">Adicionar</button>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    @foreach ($otherCartedSizes as $sizeLine)
                                        <div class="order-carted-size" wire:key="cart-size-{{ $sizeLine['cart_key'] }}">
                                            <span>{{ e($sizeLine['label']) }} no pedido</span>
                                            <div class="order-qty">
                                                <button type="button" class="booking-btn booking-btn--secondary" wire:click="decrementItem('{{ $sizeLine['cart_key'] }}')">−</button>
                                                <span>{{ $sizeLine['quantity'] }}</span>
                                                <button type="button" class="booking-btn booking-btn--secondary" wire:click="incrementItem('{{ $sizeLine['cart_key'] }}')">+</button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                @endif

                @if ($cart !== [])
                    <div class="booking-card__footer" style="margin-top: 1.25rem;">
                        <p class="booking-subtitle">
                            {{ collect($cart)->sum('quantity') }} item(ns) · {{ $this->formatMoneyCents($this->cartSubtotalCents()) }}
                        </p>
                        <button type="button" class="booking-btn booking-btn--primary" wire:click="goToFulfillment">
                            Continuar
                        </button>
                    </div>
                @endif
            @endif

            @if ($step === \App\Livewire\PublicOrders\OrderWizard::STEP_FULFILLMENT)
                <div class="booking-option-list">
                    @foreach ($fulfillmentOptions as $option)
                        <button
                            type="button"
                            class="booking-option @if ($fulfillment === $option->value) booking-option--selected @endif"
                            wire:click="selectFulfillment('{{ $option->value }}')"
                        >
                            <div class="booking-option__content">
                                <p class="booking-option__title">{{ $option->label() }}</p>
                                <p class="booking-option__subtitle">
                                    {{ $option->publicSubtitle() }}
                                    @if ($option === \App\Enums\OrderFulfillment::Delivery && ($settings?->delivery_fee_cents ?? 0) > 0)
                                        Taxa {{ $this->formatMoneyCents((int) $settings->delivery_fee_cents) }}.
                                    @endif
                                </p>
                                @if ($option === \App\Enums\OrderFulfillment::Delivery && filled($settings?->delivery_radius_note))
                                    <p class="booking-option__subtitle">{{ e($settings->delivery_radius_note) }}</p>
                                @endif
                            </div>
                        </button>
                    @endforeach
                </div>
                <div class="booking-card__footer" style="margin-top: 1.25rem;">
                    <button type="button" class="booking-btn booking-btn--secondary" wire:click="backToMenu">Voltar ao cardápio</button>
                </div>
            @endif

            @if ($step === \App\Livewire\PublicOrders\OrderWizard::STEP_CUSTOMER)
                <div class="booking-field">
                    <label class="booking-label" for="customerName">Nome <span class="booking-label__required">*</span></label>
                    <input id="customerName" type="text" class="booking-input" wire:model="customerName" autocomplete="name">
                    @error('customerName') <p class="booking-field-error">{{ $message }}</p> @enderror
                </div>
                <div class="booking-field">
                    <label class="booking-label" for="customerPhone">Telefone / WhatsApp <span class="booking-label__required">*</span></label>
                    <input id="customerPhone" type="tel" class="booking-input" wire:model="customerPhone" autocomplete="tel" placeholder="(00) 00000-0000">
                    @error('customerPhone') <p class="booking-field-error">{{ $message }}</p> @enderror
                </div>
                <div class="booking-field">
                    <label class="booking-label" for="customerEmail">E-mail (opcional)</label>
                    <input id="customerEmail" type="email" class="booking-input" wire:model="customerEmail" autocomplete="email">
                </div>
                @if ($fulfillment === 'delivery')
                    <div class="booking-field">
                        <label class="booking-label" for="deliveryAddress">Endereço <span class="booking-label__required">*</span></label>
                        <input id="deliveryAddress" type="text" class="booking-input" wire:model="deliveryAddress" autocomplete="street-address">
                        @error('deliveryAddress') <p class="booking-field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="booking-field">
                        <label class="booking-label" for="deliveryComplement">Complemento</label>
                        <input id="deliveryComplement" type="text" class="booking-input" wire:model="deliveryComplement">
                    </div>
                    <div class="booking-field">
                        <label class="booking-label" for="deliveryNeighborhood">Bairro</label>
                        <input id="deliveryNeighborhood" type="text" class="booking-input" wire:model="deliveryNeighborhood">
                    </div>
                    <div class="booking-field">
                        <label class="booking-label" for="deliveryCity">Cidade</label>
                        <input id="deliveryCity" type="text" class="booking-input" wire:model="deliveryCity">
                    </div>
                @endif
                <div class="booking-field">
                    <label class="booking-label" for="orderNotes">Observações do pedido</label>
                    <textarea id="orderNotes" class="booking-textarea" wire:model="notes" rows="3"></textarea>
                </div>
                <div class="booking-card__footer" style="margin-top: 1.25rem;">
                    <button type="button" class="booking-btn booking-btn--secondary" wire:click="backToFulfillment">Voltar</button>
                    <button type="button" class="booking-btn booking-btn--primary" wire:click="goToReview">Revisar pedido</button>
                </div>
            @endif

            @if ($step === \App\Livewire\PublicOrders\OrderWizard::STEP_REVIEW)
                <ul class="booking-option-list">
                    @foreach ($cartLines as $line)
                        <li class="booking-option">
                            <div class="booking-option__content">
                                <p class="booking-option__title">{{ $line['quantity'] }}× {{ e($line['label']) }}</p>
                                @if (filled($line['notes']))
                                    <p class="booking-option__subtitle">{{ e($line['notes']) }}</p>
                                @endif
                            </div>
                            <span>{{ $this->formatMoneyCents($line['line_total_cents']) }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="booking-subtitle" style="margin-top: 1rem;">
                    {{ $this->selectedFulfillment()?->label() }}
                    · {{ e($customerName) }} · {{ e($customerPhone) }}
                </p>
                @if ($fulfillment === 'delivery')
                    <p class="booking-subtitle">{{ e($deliveryAddress) }} {{ e($deliveryComplement) }} {{ e($deliveryNeighborhood) }} {{ e($deliveryCity) }}</p>
                    <p class="booking-subtitle">Taxa de entrega: {{ $this->formatMoneyCents($this->deliveryFeeCents()) }}</p>
                @endif
                <p class="booking-title" style="font-size: 1.15rem; margin-top: 0.75rem;">
                    Total {{ $this->formatMoneyCents($this->cartTotalCents()) }}
                </p>
                <p class="booking-subtitle">{{ $this->selectedFulfillment()?->paymentInstruction() }} Sem pagamento online.</p>
                <div class="booking-card__footer" style="margin-top: 1.25rem;">
                    <button type="button" class="booking-btn booking-btn--secondary" wire:click="backToCustomer">Voltar</button>
                    <button
                        type="button"
                        class="booking-btn booking-btn--primary"
                        wire:click="submit"
                        wire:loading.attr="disabled"
                    >
                        Confirmar pedido
                    </button>
                </div>
            @endif

            @if ($step === \App\Livewire\PublicOrders\OrderWizard::STEP_CONFIRMATION)
                <div class="booking-empty">
                    <p class="booking-title">{{ e($confirmationNumber) }}</p>
                    <p class="booking-subtitle">Código: <strong>{{ e($confirmationCode) }}</strong></p>
                    <p class="booking-subtitle">{{ e($confirmationMessage) }}</p>
                </div>
            @endif
        </div>
    </div>
</div>
