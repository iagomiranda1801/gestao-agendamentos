<x-filament-panels::page>
    <div class="mx-auto max-w-3xl space-y-6 text-sm text-gray-600 dark:text-gray-300">
        @if (! $accessAllowed)
            <p>
                O acesso à empresa <strong>{{ $company->name }}</strong> está temporariamente indisponível
                porque o período de teste expirou ou a assinatura não está em dia.
            </p>
        @endif

        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <h3 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">Plano atual</h3>
            <dl class="grid gap-2 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-400">Módulos</dt>
                    <dd>{{ $moduleLabels !== [] ? implode(', ', $moduleLabels) : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-400">Ciclo</dt>
                    <dd>{{ $intervalLabel ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-400">Valor vigente</dt>
                    <dd>{{ $quotedPrice }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-400">Vigente até</dt>
                    <dd>{{ $periodEnd ?: 'Sem vencimento' }}</dd>
                </div>
            </dl>
        </div>

        @if ($canSeeInvoices && $outstanding)
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">
                <p class="font-semibold">
                    Fatura {{ $outstanding->number }} · {{ $outstanding->status->label() }}
                </p>
                <p class="mt-1">
                    Pague
                    <strong>{{ $formatReais($outstanding->amount_cents) }}</strong>
                    até
                    <strong>{{ $outstanding->due_at?->timezone($company->timezone ?: 'America/Sao_Paulo')->format('d/m/Y') }}</strong>
                    via PIX.
                </p>
                <p class="mt-2">{{ $pixInstructions }}</p>
            </div>
        @elseif ($canSeeInvoices)
            <p>{{ $pixInstructions }}</p>
        @else
            <p>Fale com o administrador da empresa para renovar a assinatura.</p>
        @endif

        @if ($canSeeInvoices)
            <div>
                <h3 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">Faturas</h3>
                @if ($invoices->isEmpty())
                    <p>Nenhuma fatura emitida ainda.</p>
                @else
                    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 text-left dark:divide-gray-700">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                <tr>
                                    <th class="px-3 py-2">Número</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2">Valor</th>
                                    <th class="px-3 py-2">Vencimento</th>
                                    <th class="px-3 py-2">Período</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($invoices as $invoice)
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $invoice->number }}</td>
                                        <td class="px-3 py-2">{{ $invoice->status->label() }}</td>
                                        <td class="px-3 py-2">{{ $formatReais($invoice->amount_cents) }}</td>
                                        <td class="px-3 py-2">{{ $invoice->due_at?->timezone($company->timezone ?: 'America/Sao_Paulo')->format('d/m/Y') }}</td>
                                        <td class="px-3 py-2">
                                            {{ $invoice->period_start?->timezone($company->timezone ?: 'America/Sao_Paulo')->format('d/m/Y') }}
                                            —
                                            {{ $invoice->period_end?->timezone($company->timezone ?: 'America/Sao_Paulo')->format('d/m/Y') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        @if (filled($company->email))
            <p>
                E-mail cadastrado: <strong>{{ $company->email }}</strong>
            </p>
        @endif
    </div>
</x-filament-panels::page>
