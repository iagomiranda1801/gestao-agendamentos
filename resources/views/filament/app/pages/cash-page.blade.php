<x-filament-panels::page>
    <div class="space-y-6">
        @if ($this->selectedRegisterId === null)
            <x-filament::section>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Nenhum caixa físico cadastrado para esta empresa.
                </p>
            </x-filament::section>
        @elseif ($this->openSession)
            <x-filament::section heading="Sessão aberta">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Caixa</dt>
                        <dd>{{ $this->openSession->cashRegister->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Aberto em</dt>
                        <dd>{{ $this->openSession->opened_at?->format('d/m/Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Saldo esperado na abertura</dt>
                        <dd>R$ {{ number_format((float) $this->openSession->expected_opening_amount, 2, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Valor contado na abertura</dt>
                        <dd>R$ {{ number_format((float) $this->openSession->counted_opening_amount, 2, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Diferença de abertura</dt>
                        <dd>R$ {{ number_format((float) $this->openSession->opening_difference_amount, 2, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Saldo atual da conta</dt>
                        <dd>R$ {{ number_format((float) $this->openSession->cashRegister->financialAccount->getCurrentBalance(), 2, ',', '.') }}</dd>
                    </div>
                </dl>
            </x-filament::section>
        @else
            <x-filament::section heading="Caixa fechado">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Não há sessão aberta para o caixa selecionado. Utilize a ação "Abrir caixa" para iniciar uma nova sessão.
                </p>
            </x-filament::section>
        @endif

        <x-filament::section heading="Sessões anteriores">
            @php
                $sessions = \App\Models\CashSession::query()
                    ->where('company_id', \Filament\Facades\Filament::getTenant()?->getKey())
                    ->when($this->selectedRegisterId, fn ($query) => $query->where('cash_register_id', $this->selectedRegisterId))
                    ->orderByDesc('opened_at')
                    ->limit(10)
                    ->get();
            @endphp

            @if ($sessions->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-300">Nenhuma sessão registrada.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left">Abertura</th>
                                <th class="px-3 py-2 text-left">Fechamento</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-right">Esperado</th>
                                <th class="px-3 py-2 text-right">Contado</th>
                                <th class="px-3 py-2 text-right">Diferença</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($sessions as $session)
                                <tr>
                                    <td class="px-3 py-2">{{ $session->opened_at?->format('d/m/Y H:i') }}</td>
                                    <td class="px-3 py-2">{{ $session->closed_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                    <td class="px-3 py-2">{{ $session->status->label() }}</td>
                                    <td class="px-3 py-2 text-right">
                                        R$ {{ number_format((float) ($session->expected_closing_amount ?? $session->expected_opening_amount), 2, ',', '.') }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        R$ {{ number_format((float) ($session->counted_closing_amount ?? $session->counted_opening_amount), 2, ',', '.') }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        R$ {{ number_format((float) ($session->closing_difference_amount ?? $session->opening_difference_amount), 2, ',', '.') }}
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
