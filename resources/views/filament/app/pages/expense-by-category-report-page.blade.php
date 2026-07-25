<x-filament-panels::page>
    @php
        $rows = $this->expenseRows;
        $format = fn (string $amount): string => 'R$ ' . number_format((float) $amount, 2, ',', '.');
    @endphp

    <x-filament::section heading="Despesas por categoria">
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">Nenhuma despesa encontrada no período selecionado.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left">Categoria</th>
                            <th class="px-3 py-2 text-right">Competência</th>
                            <th class="px-3 py-2 text-right">Pago</th>
                            <th class="px-3 py-2 text-right">Em aberto</th>
                            <th class="px-3 py-2 text-right">% do total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-3 py-2">{{ $row->categoryName }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($row->competenceAmount) }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($row->paidAmount) }}</td>
                                <td class="px-3 py-2 text-right">{{ $format($row->outstandingAmount) }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format((float) $row->percentage, 2, ',', '.') }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
