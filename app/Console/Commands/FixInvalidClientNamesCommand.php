<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\TattooAiConversation;
use App\Models\WhatsAppContact;
use App\Support\CustomerNameDetector;
use App\Support\PhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Limpeza única de clientes cujo nome é uma frase gravada pelo WhatsApp
 * (ex.: "queria fazer um orçamento"). Por padrão só simula e gera relatório;
 * grava apenas com --apply.
 */
class FixInvalidClientNamesCommand extends Command
{
    protected $signature = 'clients:fix-invalid-names
        {--apply : Grava as correções (sem esta opção nada é alterado)}
        {--company= : Limita a uma empresa (id)}
        {--include-manual : Inclui clientes que não vieram do WhatsApp}
        {--by= : Quem está executando (registrado na auditoria)}';

    protected $description = 'Encontra clientes com nome inválido (frase no lugar do nome) e propõe/aplica a correção.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $by = (string) ($this->option('by') ?: (get_current_user() ?: 'cli'));
        $rows = [];

        Client::query()
            ->when($this->option('company'), fn ($query, $company) => $query->where('company_id', (int) $company))
            ->when(! $this->option('include-manual'), fn ($query) => $query->where('source', 'whatsapp'))
            ->orderBy('id')
            ->chunkById(500, function ($clients) use (&$rows): void {
                foreach ($clients as $client) {
                    $name = trim((string) $client->name);
                    if ($this->isPlaceholder($name) || CustomerNameDetector::isValidStoredName($name)) {
                        continue;
                    }
                    [$proposed, $source] = $this->proposal($client);
                    $rows[] = ['company_id' => $client->company_id, 'client_id' => $client->id,
                        'current' => $name, 'proposed' => $proposed, 'source' => $source];
                }
            });

        $path = 'reports/clients-fix-invalid-names-'.now()->format('Ymd-His').($apply ? '-apply' : '-dry-run').'.csv';
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['company_id', 'client_id', 'current', 'proposed', 'source']);
        foreach ($rows as $row) {
            fputcsv($csv, array_values($row));
        }
        rewind($csv);
        Storage::disk('local')->put($path, (string) stream_get_contents($csv));
        fclose($csv);

        $this->table(['Empresa', 'Cliente', 'Nome atual', 'Nome proposto', 'Origem'], array_map('array_values', $rows));
        $bySource = collect($rows)->countBy('source')->map(fn ($count, $source) => "{$source}: {$count}")->implode(', ');
        $this->info(count($rows).' cliente(s) com nome inválido'.($bySource !== '' ? " ({$bySource})" : '').'.');
        $this->info('Relatório: '.Storage::disk('local')->path($path));

        if (! $apply) {
            $this->warn('Simulação (dry-run): nada foi alterado. Revise o relatório e rode com --apply para gravar.');

            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $by, &$updated): void {
                $client = Client::query()->lockForUpdate()->find($row['client_id']);
                if (! $client || trim((string) $client->name) !== $row['current']) {
                    return; // mudou desde a simulação: não mexe.
                }
                $client->update(['name' => $row['proposed']]);
                $updated++;
                Log::info('clients:fix-invalid-names applied.', ['by' => $by, 'company_id' => $row['company_id'],
                    'client_id' => $row['client_id'], 'before' => $row['current'], 'after' => $row['proposed'],
                    'source' => $row['source']]);
            });
        }
        $this->info("{$updated} cliente(s) corrigido(s) por {$by}.");

        return self::SUCCESS;
    }

    /**
     * Nome mais confiável disponível: o coletado pela IA na conversa, o da
     * agenda do WhatsApp sincronizada, ou um provisório com o fim do telefone.
     *
     * @return array{0: string, 1: string}
     */
    protected function proposal(Client $client): array
    {
        $collected = TattooAiConversation::query()->where('company_id', $client->company_id)
            ->where('client_id', $client->id)->latest('last_interaction_at')->latest('id')
            ->limit(10)->get(['collected_data'])
            ->map(fn (TattooAiConversation $conversation) => trim((string) ($conversation->collected_data['name'] ?? '')))
            ->first(fn (string $name) => $name !== '' && ! $this->isPlaceholder($name) && CustomerNameDetector::isPlausible($name));
        if ($collected !== null) {
            return [$collected, 'conversation'];
        }

        $phone = (string) $client->phone_normalized;
        if ($phone !== '') {
            $contact = WhatsAppContact::query()->where('company_id', $client->company_id)
                ->whereIn('phone_normalized', PhoneNormalizer::candidates($phone))->pluck('name')
                ->map(fn ($name) => trim((string) $name))
                ->first(fn (string $name) => $name !== '' && ! $this->isPlaceholder($name) && CustomerNameDetector::isPlausible($name));
            if ($contact !== null) {
                return [$contact, 'contact_book'];
            }
        }

        return ['Cliente WhatsApp '.($phone !== '' ? mb_substr($phone, -4) : $client->id), 'placeholder'];
    }

    protected function isPlaceholder(string $name): bool
    {
        return preg_match('/^(?:Cliente WhatsApp|Contato)\s*\d+$/iu', $name) === 1 || ctype_digit($name);
    }
}
