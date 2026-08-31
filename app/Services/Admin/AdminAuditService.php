<?php

namespace App\Services\Admin;

use App\Enums\AdminAuditAction;
use App\Models\AdminAuditLog;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class AdminAuditService
{
    /** @var array<class-string<Model>, list<string>> */
    private const AUDITED_FIELDS = [
        Company::class => [
            'name', 'slug', 'business_profile', 'document', 'phone', 'email', 'timezone',
            'is_active', 'enabled_modules', 'subscription_status', 'trial_ends_at',
        ],
        User::class => ['name', 'email', 'is_super_admin', 'is_active'],
    ];

    public function isCurrentAdminPanelAction(): bool
    {
        return auth()->user() instanceof User
            && Filament::getCurrentPanel()?->getId() === 'admin';
    }

    /** @return array<string, mixed> */
    public function snapshot(Model $model, ?array $fields = null, bool $original = false): array
    {
        $fields ??= self::AUDITED_FIELDS[$model::class] ?? [];

        return collect($fields)
            ->mapWithKeys(function (string $field) use ($model, $original): array {
                $value = $original ? $model->getOriginal($field) : $model->getAttribute($field);

                return [$field => $this->normalize($value)];
            })
            ->all();
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @param array<string, mixed> $metadata */
    public function record(
        User $actor,
        AdminAuditAction $action,
        ?Model $subject,
        array $before = [],
        array $after = [],
        ?Company $company = null,
        ?string $subjectLabel = null,
        array $metadata = [],
        bool $preserveActorReference = true,
    ): AdminAuditLog {
        $request = app()->bound('request') ? request() : null;

        return AdminAuditLog::query()->create([
            'actor_id' => $preserveActorReference ? $actor->getKey() : null,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'company_id' => $company?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subjectLabel ?? $this->subjectLabel($subject),
            'before' => $before,
            'after' => $after,
            'metadata' => array_filter([
                'origin' => 'admin_panel',
                'route' => $request?->route()?->getName(),
                'ip_address' => $request?->ip(),
                ...$metadata,
            ], fn (mixed $value): bool => $value !== null && $value !== []),
            'occurred_at' => now(),
        ]);
    }

    public function subjectLabel(?Model $subject): string
    {
        return match (true) {
            $subject instanceof Company => "Empresa: {$subject->name}",
            $subject instanceof User => "Usuário: {$subject->name} ({$subject->email})",
            default => $subject ? class_basename($subject)." #{$subject->getKey()}" : 'Operação administrativa',
        };
    }

    /** @return array<string, mixed> */
    public function membershipSnapshot(Company $company, User $user): array
    {
        $pivot = $company->users()
            ->where('users.id', $user->getKey())
            ->first()
            ?->pivot;

        return [
            'company_id' => $company->getKey(),
            'company_name' => $company->name,
            'user_id' => $user->getKey(),
            'user_name' => $user->name,
            'user_email' => $user->email,
            'role' => $this->normalize($pivot?->role),
            'is_active' => $pivot?->is_active === null ? null : (bool) $pivot->is_active,
            'permissions' => $this->normalize($pivot?->permissions),
        ];
    }

    public function membershipLabel(Company $company, User $user): string
    {
        return "Vínculo: {$user->name} ({$user->email}) → {$company->name}";
    }

    public function fieldLabel(string $field): string
    {
        return match ($field) {
            'name' => 'Nome',
            'slug' => 'Slug',
            'business_profile' => 'Perfil do negócio',
            'document' => 'Documento',
            'phone' => 'Telefone',
            'email' => 'E-mail',
            'timezone' => 'Fuso horário',
            'is_active' => 'Ativo',
            'is_super_admin' => 'Superadministrador',
            'enabled_modules' => 'Módulos habilitados',
            'subscription_status' => 'Status da assinatura',
            'trial_ends_at' => 'Trial até',
            'role' => 'Papel',
            'permissions' => 'Permissões',
            'company_id' => 'Empresa (ID)',
            'company_name' => 'Empresa',
            'user_id' => 'Usuário (ID)',
            'user_name' => 'Usuário',
            'user_email' => 'E-mail do usuário',
            'job_id' => 'Identificador do job',
            'queue' => 'Fila',
            'job' => 'Job',
            'deleted_count' => 'Total removido',
            'cutoff_at' => 'Data de corte',
            default => str($field)->replace('_', ' ')->title()->toString(),
        };
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof CarbonInterface) {
            return $value->utc()->toIso8601String();
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        return $value;
    }
}
