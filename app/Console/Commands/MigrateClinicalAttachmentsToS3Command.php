<?php

namespace App\Console\Commands;

use App\Models\ClinicalAttachment;
use App\Services\Clinical\ClinicalStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigrateClinicalAttachmentsToS3Command extends Command
{
    protected $signature = 'storage:migrate-clinical-attachments-to-s3 {--limit=0 : Máximo de arquivos a processar}';

    protected $description = 'Copia anexos clínicos locais para o S3 sem apagar os arquivos originais.';

    public function handle(ClinicalStorageService $storage): int
    {
        $limit = (int) $this->option('limit');
        $processed = 0;
        $migrated = 0;
        $errors = 0;
        $targetDisk = $storage->disk();

        ClinicalAttachment::query()
            ->with(['company', 'client.dentalProfile'])
            ->where('disk', 'local')
            ->orderBy('id')
            ->chunkById(100, function ($attachments) use ($storage, $targetDisk, $limit, &$processed, &$migrated, &$errors): bool {
                foreach ($attachments as $attachment) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }

                    $processed++;

                    try {
                        $source = Storage::disk('local');
                        if (! $source->exists($attachment->path)) {
                            throw new \RuntimeException('Arquivo local não encontrado.');
                        }

                        $stream = $source->readStream($attachment->path);
                        if (! is_resource($stream)) {
                            throw new \RuntimeException('Não foi possível ler o arquivo local.');
                        }

                        $targetPath = $storage->pathForAttachment($attachment->company, $attachment->client, $attachment);
                        Storage::disk($targetDisk)->writeStream($targetPath, $stream);
                        fclose($stream);

                        $size = Storage::disk($targetDisk)->size($targetPath);
                        if ((int) $size !== (int) $attachment->size_bytes) {
                            throw new \RuntimeException('O tamanho do arquivo copiado não confere.');
                        }

                        $checksum = hash_file('sha256', $source->path($attachment->path));
                        $attachment->forceFill([
                            'disk' => $targetDisk,
                            'path' => $targetPath,
                            'storage_migrated_at' => now(),
                            'storage_checksum' => $checksum ?: null,
                            'storage_migration_error' => null,
                        ])->save();
                        $migrated++;
                    } catch (Throwable $exception) {
                        $attachment->forceFill(['storage_migration_error' => $exception->getMessage()])->save();
                        $errors++;
                        $this->warn("Anexo #{$attachment->getKey()}: {$exception->getMessage()}");
                    }
                }

                return true;
            });

        $this->info("Processados: {$processed}; migrados: {$migrated}; erros: {$errors}. Arquivos locais foram preservados.");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
