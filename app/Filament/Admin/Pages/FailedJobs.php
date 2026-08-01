<?php

namespace App\Filament\Admin\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use UnitEnum;

class FailedJobs extends Page
{
    protected static ?string $slug = 'operacao/jobs-falhos';

    protected static ?string $navigationLabel = 'Jobs falhados';

    protected static ?string $title = 'Jobs falhados';

    protected static string|UnitEnum|null $navigationGroup = 'Operação';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.admin.pages.failed-jobs';

    protected function getViewData(): array
    {
        return [
            'jobs' => $this->failedJobs(),
            'hasTable' => Schema::hasTable('failed_jobs'),
        ];
    }

    public function retryJob(string $id): void
    {
        Artisan::call('queue:retry', [$id, '--silent' => true]);

        Notification::make()
            ->success()
            ->title('Job enviado novamente para a fila')
            ->send();
    }

    public function forgetJob(string $id): void
    {
        Artisan::call('queue:forget', [$id, '--silent' => true]);

        Notification::make()
            ->success()
            ->title('Falha removida')
            ->send();
    }

    public function clearOldJobs(): void
    {
        $deleted = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(7))->delete()
            : 0;

        Notification::make()
            ->success()
            ->title('Limpeza concluída')
            ->body("{$deleted} falha(s) antiga(s) removida(s).")
            ->send();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->latest('failed_at')
            ->limit(100)
            ->get()
            ->map(function (object $job): array {
                $payload = json_decode((string) $job->payload, true) ?: [];
                $displayName = data_get($payload, 'displayName')
                    ?: data_get($payload, 'data.commandName')
                    ?: 'Job desconhecido';

                return [
                    'id' => (string) ($job->uuid ?? $job->id),
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'displayName' => class_basename((string) $displayName),
                    'failedAt' => $job->failed_at,
                    'exception' => Str::limit(trim((string) $job->exception), 260),
                ];
            })
            ->all();
    }
}
