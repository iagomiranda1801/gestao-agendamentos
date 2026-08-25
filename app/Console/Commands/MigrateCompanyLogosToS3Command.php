<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MigrateCompanyLogosToS3Command extends Command
{
    protected $signature = 'storage:migrate-company-logos-to-s3';

    protected $description = 'Copia logos locais para o S3 sem apagar os originais.';

    public function handle(): int
    {
        $targetDisk = (string) config('filesystems.company_logo_disk', 's3');
        $migrated = 0;
        $errors = 0;

        Company::query()->whereNotNull('logo_path')->orderBy('id')->each(function (Company $company) use ($targetDisk, &$migrated, &$errors): void {
            if (Str::startsWith((string) $company->logo_path, ['http://', 'https://', '/'])) {
                return;
            }

            $sourceDisk = $company->logo_disk ?: 'public';
            if ($sourceDisk === $targetDisk) {
                return;
            }

            try {
                $source = Storage::disk($sourceDisk);
                if (! $source->exists($company->logo_path)) {
                    throw new \RuntimeException('Logo local não encontrada.');
                }

                $extension = pathinfo($company->logo_path, PATHINFO_EXTENSION) ?: 'png';
                $targetPath = 'agendaqui/'.$company->slug.'/empresa/logo/'.Str::uuid().'.'.Str::lower($extension);
                $stream = $source->readStream($company->logo_path);
                Storage::disk($targetDisk)->writeStream($targetPath, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $company->update(['logo_path' => $targetPath, 'logo_disk' => $targetDisk]);
                $migrated++;
            } catch (Throwable $exception) {
                $errors++;
                $this->warn("Empresa #{$company->getKey()}: {$exception->getMessage()}");
            }
        });

        $this->info("Logos migrados: {$migrated}; erros: {$errors}. Logos locais foram preservados.");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
