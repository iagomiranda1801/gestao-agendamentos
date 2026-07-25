<x-filament-panels::page>
    @php
        $dre = $this->dreSummary;
        $format = fn (string $amount): string => 'R$ ' . number_format((float) $amount, 2, ',', '.');
        $line = fn (string $label, string $amount, bool $deduction = false, bool $highlight = false): string => '';
    @endphp

    <div class="space-y-6">
        <x-filament::section heading="Demonstrativo gerencial">
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                Visão gerencial baseada em atendimentos concluídos e despesas por competência. Não substitui a DRE contábil oficial.
            </p>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <tr>
                            <td class="px-3 py-2 font-medium">Receita bruta de serviços</td>
                            <td class="px-3 py-2 text-right">{{ $format($dre['grossRevenue']) }}</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 pl-6 text-gray-600">(-) Descontos</td>
                            <td class="px-3 py-2 text-right text-danger-600">{{ $format($dre['discounts']) }}</td>
                        </tr>
                        <tr class="bg-gray-50 dark:bg-gray-900/40">
                            <td class="px-3 py-2 font-semibold">Receita líquida</td>
                            <td class="px-3 py-2 text-right font-semibold">{{ $format($dre['netRevenue']) }}</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 pl-6 text-gray-600">(-) Custo dos materiais consumidos</td>
                            <td class="px-3 py-2 text-right text-danger-600">{{ $format($dre['materialCost']) }}</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 pl-6 text-gray-600">(-) Comissões</td>
                            <td class="px-3 py-2 text-right text-danger-600">{{ $format($dre['commissions']) }}</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 pl-6 text-gray-600">(-) Taxas de pagamento</td>
                            <td class="px-3 py-2 text-right text-danger-600">{{ $format($dre['paymentFees']) }}</td>
                        </tr>
                        <tr class="bg-gray-50 dark:bg-gray-900/40">
                            <td class="px-3 py-2 font-semibold">Margem de contribuição</td>
                            <td class="px-3 py-2 text-right font-semibold">{{ $format($dre['contributionMargin']) }}</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 pl-6 text-gray-600">(-) Despesas operacionais e administrativas</td>
                            <td class="px-3 py-2 text-right text-danger-600">{{ $format($dre['operationalExpenses']) }}</td>
                        </tr>
                        <tr class="bg-primary-50 dark:bg-primary-950/30">
                            <td class="px-3 py-2 font-bold">Resultado operacional gerencial</td>
                            <td class="px-3 py-2 text-right font-bold">{{ $format($dre['operationalResult']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Distribuições gerenciais (informativo)">
            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-sm font-medium text-gray-500">Reserva para materiais</dt>
                    <dd>{{ $format($dre['materialReserve']) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Reserva do negócio</dt>
                    <dd>{{ $format($dre['businessReserve']) }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">Parcela do proprietário</dt>
                    <dd>{{ $format($dre['ownerAllocation']) }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                Estes valores são distribuições gerenciais dos atendimentos e não compõem despesas operacionais.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
