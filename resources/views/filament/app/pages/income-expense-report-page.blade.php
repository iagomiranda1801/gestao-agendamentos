<x-filament-panels::page>
    @php
        $report = $this->report;
        $format = fn (string $amount): string => 'R$ ' . number_format((float) $amount, 2, ',', '.');
    @endphp

    <div class="space-y-6">
        <x-filament::section heading="Resumo do período">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                {{ $report['periodStartLabel'] }} a {{ $report['periodEndLabel'] }}.
                Lançamentos de caixa (sem estornos e sem transferências entre contas).
            </p>
            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-sm font-medium text-gray-500">Receitas</dt>
                    <dd class="text-lg font-semibold text-success-600">{{ $format($report['incomeTotal']) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Gastos</dt>
                    <dd class="text-lg font-semibold text-danger-600">{{ $format($report['expenseTotal']) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Saldo</dt>
                    <dd class="text-lg font-semibold">{{ $format($report['balance']) }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Lançamentos">
            @if ($report['rows'] === [])
                <p class="text-sm text-gray-600 dark:text-gray-300">Nenhum lançamento no período selecionado.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left">Data</th>
                                <th class="px-3 py-2 text-left">Tipo</th>
                                <th class="px-3 py-2 text-left">Descrição</th>
                                <th class="px-3 py-2 text-left">Conta</th>
                                <th class="px-3 py-2 text-left">Movimento</th>
                                <th class="px-3 py-2 text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($report['rows'] as $row)
                                <tr>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $row['occurredAtLocal'] }}</td>
                                    <td class="px-3 py-2">{{ $row['typeLabel'] }}</td>
                                    <td class="px-3 py-2">{{ $row['description'] }}</td>
                                    <td class="px-3 py-2">{{ $row['accountName'] }}</td>
                                    <td class="px-3 py-2">{{ $row['directionLabel'] }}</td>
                                    <td class="px-3 py-2 text-right {{ $row['isInbound'] ? 'text-success-600' : 'text-danger-600' }}">
                                        {{ $format($row['amount']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
