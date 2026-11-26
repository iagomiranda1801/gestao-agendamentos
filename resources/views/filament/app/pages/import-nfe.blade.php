<x-filament-panels::page>
    @if ($step === 'upload')
        <form wire:submit="readXml" class="space-y-6">
            <x-filament::section heading="1. Envie o XML da nota">
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
                    Você revisa tudo antes de gravar: nada entra no estoque até clicar em "Importar compra".
                </p>

                {{ $this->form }}
            </x-filament::section>

            <div class="flex gap-3">
                <x-filament::button type="submit" icon="heroicon-o-document-magnifying-glass" wire:loading.attr="disabled" wire:target="readXml">
                    Ler XML
                </x-filament::button>
                <x-filament::button tag="a" :href="$purchasesUrl" color="gray">
                    Voltar para Compras
                </x-filament::button>
            </div>
        </form>
    @else
        @php
            $fmt = fn ($value, int $decimals = 2) => number_format((float) $value, $decimals, ',', '.');
            $issuer = $invoice['issuer'] ?? [];
            $totals = $invoice['totals'] ?? [];
            $sourceLabels = [
                'mapping' => ['De/para salvo', 'success'],
                'barcode' => ['Código de barras', 'success'],
                'name' => ['Nome parecido: confira', 'warning'],
                'none' => ['Não encontrado', 'gray'],
            ];
        @endphp

        <div class="space-y-6">
            <x-filament::section heading="2. Confira a nota">
                <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">NF-e</dt>
                        <dd>
                            Nº {{ $invoice['number'] }}@if (filled($invoice['series'] ?? null))/{{ $invoice['series'] }}@endif
                            — {{ \Carbon\CarbonImmutable::parse($invoice['issued_at'])->format('d/m/Y') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Fornecedor</dt>
                        <dd>
                            {{ $issuer['name'] ?? '—' }}
                            @if (filled($analysis['supplier_name'] ?? null))
                                <x-filament::badge color="success" class="ml-1">já cadastrado</x-filament::badge>
                            @else
                                <x-filament::badge color="info" class="ml-1">será cadastrado</x-filament::badge>
                            @endif
                        </dd>
                        <dd class="text-xs text-gray-500">CNPJ/CPF {{ $issuer['document'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Total da nota</dt>
                        <dd>R$ {{ $fmt($totals['invoice'] ?? 0) }}</dd>
                        <dd class="text-xs text-gray-500">
                            Produtos R$ {{ $fmt($totals['products'] ?? 0) }}
                            @if (bccomp((string) ($totals['freight'] ?? '0'), '0', 2) > 0) · frete R$ {{ $fmt($totals['freight']) }} @endif
                            @if (bccomp((string) ($totals['discount'] ?? '0'), '0', 2) > 0) · desconto R$ {{ $fmt($totals['discount']) }} @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Chave de acesso</dt>
                        <dd class="break-all text-xs">{{ $invoice['access_key'] }}</dd>
                    </div>
                </dl>

                @foreach ($invoice['warnings'] ?? [] as $warning)
                    <p class="mt-3 text-sm text-warning-600 dark:text-warning-400">⚠ {{ $warning }}</p>
                @endforeach

                @if (($analysis['recipient'] ?? null) === 'mismatch')
                    <div class="mt-4 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-600 dark:bg-warning-950">
                        <p class="font-medium">O destinatário da nota ({{ $invoice['recipient']['name'] ?? '—' }}, {{ $invoice['recipient']['document'] ?? '—' }}) não é o CNPJ/CPF desta empresa.</p>
                        <label class="mt-2 flex items-center gap-2">
                            <x-filament::input.checkbox wire:model="acknowledgeMismatch" />
                            <span>Quero importar mesmo assim</span>
                        </label>
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section heading="3. Itens da nota" description="Para cada item, escolha o produto do estoque, cadastre um novo ou ignore. O fator de conversão multiplica a quantidade da nota (ex.: 1 caixa com 12 latas = fator 12).">
                <div class="space-y-4">
                    @foreach ($invoice['items'] as $item)
                        @php
                            $i = $item['index'];
                            $row = $rows[$i] ?? [];
                            $label = $sourceLabels[$row['source'] ?? 'none'] ?? $sourceLabels['none'];
                            $preview = $previews[$i] ?? null;
                        @endphp

                        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10" wire:key="nfe-item-{{ $i }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="font-medium">{{ $i }}. {{ $item['name'] }}</p>
                                    <p class="text-xs text-gray-500">
                                        Cód. {{ $item['code'] }}
                                        @if ($item['ean']) · EAN {{ $item['ean'] }} @endif
                                        · {{ $fmt($item['quantity'], 4) }} {{ $item['unit'] }} × R$ {{ $fmt($item['unit_price'], 4) }}
                                        · custo da linha R$ {{ $fmt($item['cost_total']) }}
                                    </p>
                                </div>
                                <x-filament::badge :color="$label[1]">{{ $label[0] }}</x-filament::badge>
                            </div>

                            <div class="mt-3 grid gap-3 md:grid-cols-4">
                                <div>
                                    <label class="text-sm font-medium">O que fazer</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model.live="rows.{{ $i }}.action">
                                            <option value="match">Usar produto existente</option>
                                            <option value="create">Cadastrar produto novo</option>
                                            <option value="ignore">Ignorar este item</option>
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </div>

                                @if (($row['action'] ?? '') === 'match')
                                    <div class="md:col-span-2">
                                        <label class="text-sm font-medium">Produto no estoque</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model.live="rows.{{ $i }}.product_id">
                                                <option value="">Selecione…</option>
                                                @foreach ($stockProducts as $productId => $productName)
                                                    <option value="{{ $productId }}">{{ $productName }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </div>
                                @endif

                                @if (($row['action'] ?? '') !== 'ignore')
                                    <div>
                                        <label class="text-sm font-medium">Fator de conversão</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" inputmode="decimal" wire:model.live.debounce.500ms="rows.{{ $i }}.factor" />
                                        </x-filament::input.wrapper>
                                    </div>
                                @endif
                            </div>

                            @if (($row['action'] ?? '') === 'create')
                                <div class="mt-3 grid gap-3 md:grid-cols-4">
                                    <div class="md:col-span-2">
                                        <label class="text-sm font-medium">Nome do produto</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" wire:model="rows.{{ $i }}.new_name" />
                                        </x-filament::input.wrapper>
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium">Código de barras</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" wire:model="rows.{{ $i }}.new_barcode" />
                                        </x-filament::input.wrapper>
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium">Preço de venda (opcional)</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" inputmode="decimal" placeholder="0,00" wire:model="rows.{{ $i }}.new_sale_price" />
                                        </x-filament::input.wrapper>
                                    </div>
                                    @if ($ordersEnabled)
                                        <div class="md:col-span-2">
                                            <label class="text-sm font-medium">Categoria do cardápio (opcional)</label>
                                            <x-filament::input.wrapper>
                                                <x-filament::input type="text" wire:model="rows.{{ $i }}.new_category" />
                                            </x-filament::input.wrapper>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            @if (($row['action'] ?? '') !== 'ignore')
                                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <label class="flex items-center gap-2">
                                        <x-filament::input.checkbox wire:model="rows.{{ $i }}.remember" />
                                        <span>Lembrar para as próximas notas deste fornecedor</span>
                                    </label>
                                    @if ($preview)
                                        <span class="text-gray-600 dark:text-gray-300">
                                            Entra no estoque: <strong>{{ $fmt($preview['quantity'], 4) }}</strong>
                                            a R$ {{ $fmt($preview['unit_cost'], 4) }} cada
                                        </span>
                                    @else
                                        <span class="text-danger-600">Fator inválido</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section heading="4. Contas a pagar">
                @if ($analysis['finance_enabled'] ?? false)
                    <label class="flex items-center gap-2">
                        <x-filament::input.checkbox wire:model.live="createPayable" />
                        <span>Gerar contas a pagar a partir da nota</span>
                    </label>
                    @if ($createPayable)
                        <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($installments as $installment)
                                <li>Parcela {{ $installment['number'] }} — vence {{ \Carbon\CarbonImmutable::parse($installment['due_date'])->format('d/m/Y') }} — R$ {{ $fmt($installment['amount']) }}</li>
                            @endforeach
                        </ul>
                        @unless ($analysis['installments_from_xml'] ?? false)
                            <p class="mt-2 text-xs text-gray-500">A nota não traz duplicatas: será criada uma conta com o valor total vencendo hoje.</p>
                        @endunless
                    @endif
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        O módulo Financeiro não está ativo (ou você não tem permissão): a compra entra no estoque sem gerar contas a pagar.
                    </p>
                @endif
            </x-filament::section>

            @error('import')
                <p class="text-sm text-danger-600">{{ $message }}</p>
            @enderror

            <div class="flex flex-wrap gap-3">
                <x-filament::button wire:click="confirmImport" wire:loading.attr="disabled" wire:target="confirmImport" icon="heroicon-o-check">
                    Importar compra
                </x-filament::button>
                <x-filament::button wire:click="restart" color="gray">
                    Escolher outro arquivo
                </x-filament::button>
            </div>
        </div>
    @endif
</x-filament-panels::page>