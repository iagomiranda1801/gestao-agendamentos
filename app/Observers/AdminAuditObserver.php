<?php

namespace App\Observers;

use App\Enums\AdminAuditAction;
use App\Models\Company;
use App\Models\User;
use App\Services\Admin\AdminAuditService;
use Illuminate\Database\Eloquent\Model;

class AdminAuditObserver
{
    public function __construct(private readonly AdminAuditService $audit) {}

    public function created(Model $model): void
    {
        if (! $this->shouldAudit()) {
            return;
        }

        $this->audit->record(
            auth()->user(),
            $model instanceof Company ? AdminAuditAction::CompanyCreated : AdminAuditAction::UserCreated,
            $model,
            after: $this->audit->snapshot($model),
            company: $model instanceof Company ? $model : null,
        );
    }

    public function updated(Model $model): void
    {
        if (! $this->shouldAudit()) {
            return;
        }

        $fields = array_keys($model->getChanges());
        $fields = array_values(array_diff($fields, ['updated_at']));

        if ($model instanceof User && in_array('password', $fields, true)) {
            $this->audit->record(
                auth()->user(),
                AdminAuditAction::UserPasswordChanged,
                $model,
                metadata: ['changed_fields' => ['password']],
            );
            $fields = array_values(array_diff($fields, ['password']));
        }

        if ($fields === []) {
            return;
        }

        $this->audit->record(
            auth()->user(),
            $model instanceof Company ? AdminAuditAction::CompanyUpdated : AdminAuditAction::UserUpdated,
            $model,
            before: $this->audit->snapshot($model, $fields, original: true),
            after: $this->audit->snapshot($model, $fields),
            company: $model instanceof Company ? $model : null,
        );
    }

    public function deleted(Model $model): void
    {
        if (! $this->shouldAudit()) {
            return;
        }

        /** @var User $actor */
        $actor = auth()->user();

        $this->audit->record(
            $actor,
            $model instanceof Company ? AdminAuditAction::CompanyDeleted : AdminAuditAction::UserDeleted,
            $model,
            before: $this->audit->snapshot($model),
            company: null,
            preserveActorReference: ! ($model instanceof User && $model->is($actor)),
        );
    }

    private function shouldAudit(): bool
    {
        return $this->audit->isCurrentAdminPanelAction()
            && auth()->user() instanceof User;
    }
}
