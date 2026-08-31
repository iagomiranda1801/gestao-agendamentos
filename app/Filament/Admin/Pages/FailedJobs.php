<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AdminAuditAction;
use App\Models\User;
use App\Services\Admin\AdminAuditService;
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
        $snapshot = $this->failedJobSnapshot($id);
        $exitCode = Artisan::call('queue:retry', [$id, '--silent' => true]);

        if ($exitCode !== 0) {
            Notification::make()->danger()->title('Não foi possível reenviar o job')->send();

            return;
        }

        $this->audit(AdminAuditAction::FailedJobRetried, "Job falhado reenviado: {$id}", $snapshot);

        Notification::make()
            ->success()
            ->title('Job enviado novamente para a fila')
            ->send();
    }

    public function forgetJob(string $id): void
    {
        $snapshot = $this->failedJobSnapshot($id);
        $exitCode = Artisan::call('queue:forget', [$id, '--silent' => true]);

        if ($exitCode !== 0) {
            Notification::make()->danger()->title('Não foi possível remover a falha')->send();

            return;
        }

        $this->audit(AdminAuditAction::FailedJobForgotten, "Job falhado removido: {$id}", $snapshot);

        Notification::make()
            ->success()
            ->title('Falha removida')
            ->send();
    }

    public function clearOldJobs(): void
    {
        $cutoff = now()->subDays(7);
        $deleted = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('failed_at', '<', $cutoff)->delete()
            : 0;

        if (Schema::hasTable('failed_jobs')) {
            $this->audit(
                AdminAuditAction::FailedJobsCleaned,
                'Limpeza de jobs falhados antigos',
                ['deleted_count' => $deleted, 'cutoff_at' => $cutoff->utc()->toIso8601String()],
            );
        }

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

    /** @return array<string, mixed> */
    protected function failedJobSnapshot(string $id): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['job_id' => $id];
        }

        $job = DB::table('failed_jobs')
            ->where('uuid', $id)
            ->orWhere('id', $id)
            ->first(['id', 'uuid', 'queue', 'payload']);

        if ($job === null) {
            return ['job_id' => $id];
        }

        $payload = json_decode((string) $job->payload, true) ?: [];
        $displayName = data_get($payload, 'displayName')
            ?: data_get($payload, 'data.commandName')
            ?: 'Job desconhecido';

        return [
            'job_id' => (string) ($job->uuid ?? $job->id),
            'queue' => (string) $job->queue,
            'job' => class_basename((string) $displayName),
        ];
    }

    /** @param array<string, mixed> $after */
    protected function audit(AdminAuditAction $action, string $subjectLabel, array $after): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        app(AdminAuditService::class)->record(
            $actor,
            $action,
            null,
            after: $after,
            subjectLabel: $subjectLabel,
        );
    }
}
