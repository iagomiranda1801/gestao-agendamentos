<div class="space-y-6">
    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <dt class="text-sm text-gray-500 dark:text-gray-400">Responsável</dt>
            <dd class="font-medium text-gray-950 dark:text-white">{{ $record->actor_name }}</dd>
            <dd class="text-sm text-gray-500 dark:text-gray-400">{{ $record->actor_email }}</dd>
        </div>
        <div>
            <dt class="text-sm text-gray-500 dark:text-gray-400">Data e hora</dt>
            <dd class="font-medium text-gray-950 dark:text-white">{{ $record->occurred_at->clone()->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') }}</dd>
        </div>
        <div>
            <dt class="text-sm text-gray-500 dark:text-gray-400">Tipo da ação</dt>
            <dd class="font-medium text-gray-950 dark:text-white">{{ $record->action->label() }}</dd>
        </div>
        <div>
            <dt class="text-sm text-gray-500 dark:text-gray-400">Entidade afetada</dt>
            <dd class="font-medium text-gray-950 dark:text-white">{{ $record->subject_label }}</dd>
        </div>
        @if ($record->company)
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Empresa relacionada</dt>
                <dd class="font-medium text-gray-950 dark:text-white">{{ $record->company->name }}</dd>
            </div>
        @endif
    </dl>

    @if (count($changes))
        <div>
            <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Alterações</h3>
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-gray-700 dark:bg-white/5 dark:text-gray-300">
                        <tr>
                            <th class="px-3 py-2 font-medium">Campo</th>
                            <th class="px-3 py-2 font-medium">Antes</th>
                            <th class="px-3 py-2 font-medium">Depois</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($changes as $change)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $change['field'] }}</td>
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $change['before'] }}</td>
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $change['after'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
