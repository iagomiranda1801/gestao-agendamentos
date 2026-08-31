<?php

namespace App\Enums;

enum AdminAuditAction: string
{
    case CompanyCreated = 'company.created';
    case CompanyUpdated = 'company.updated';
    case CompanyDeleted = 'company.deleted';
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserPasswordChanged = 'user.password_changed';
    case UserDeleted = 'user.deleted';
    case CompanyMembershipAttached = 'company_membership.attached';
    case CompanyMembershipUpdated = 'company_membership.updated';
    case CompanyMembershipDetached = 'company_membership.detached';
    case FailedJobRetried = 'failed_job.retried';
    case FailedJobForgotten = 'failed_job.forgotten';
    case FailedJobsCleaned = 'failed_jobs.cleaned';

    public function label(): string
    {
        return match ($this) {
            self::CompanyCreated => 'Empresa criada',
            self::CompanyUpdated => 'Empresa alterada',
            self::CompanyDeleted => 'Empresa excluída',
            self::UserCreated => 'Usuário criado',
            self::UserUpdated => 'Usuário alterado',
            self::UserPasswordChanged => 'Senha de usuário alterada',
            self::UserDeleted => 'Usuário excluído',
            self::CompanyMembershipAttached => 'Usuário vinculado à empresa',
            self::CompanyMembershipUpdated => 'Vínculo usuário–empresa alterado',
            self::CompanyMembershipDetached => 'Usuário desvinculado da empresa',
            self::FailedJobRetried => 'Job falhado reenviado',
            self::FailedJobForgotten => 'Job falhado removido',
            self::FailedJobsCleaned => 'Jobs falhados antigos removidos',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $action): array => [$action->value => $action->label()])
            ->all();
    }
}
