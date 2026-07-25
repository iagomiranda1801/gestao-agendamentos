<x-filament-panels::page>
    @php
        $summary = $this->cashFlowSummary;
        $format = fn (string $amount): string => 'R$ ' . number_format((float) $amount, 2, ',', '.');
    @endphp

    <div class="space-y-6">
        <x-filament::section heading="Resumo do período">
            <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div>
                    <dt class="text-sm font-medium text-gray-500">Saldo inicial</dt>
                    <dd class="text-lg font-semibold">{{ $format($summary['initialBalance']) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Entradas</dt>
                    <dd class="text-lg font-semibold text-success-600">{{ $format($summary['inflows']) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Saídas</dt>
                    <dd class="text-lg font-semibold text-danger-600">{{ $format($summary['outflows']) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Fluxo líquido</dt>
                    <dd class="text-lg font-semibold">{{ $format($summary['netFlow']) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Saldo final</dt>
                    <dd class="text-lg font-semibold">{{ $format($summary['finalBalance']) }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                Na visão consolidada, transferências entre contas não entram como receita ou despesa.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
