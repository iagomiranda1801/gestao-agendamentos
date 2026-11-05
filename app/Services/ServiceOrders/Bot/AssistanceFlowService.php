<?php

namespace App\Services\ServiceOrders\Bot;

use App\Enums\CompanyModule;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\AssistanceBotFlow;
use App\Models\AssistanceBotFlowVersion;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use App\Services\Company\CompanyPermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssistanceFlowService
{
    public function allows(Company $company, User $user, bool $edit = true): bool
    {
        return $company->is_active && $user->is_active && $company->isTechnicalAssistance()
            && app(CompanyModuleService::class)->hasModule($company, CompanyModule::WhatsApp)
            && app(CompanyModuleService::class)->hasModule($company, CompanyModule::ServiceOrders)
            && app(CompanyPermissionService::class)->allows($user, $company, CompanyPermission::ManageServiceOrders)
            && (! $edit || $user->hasActiveRoleInCompany($company, CompanyRole::CompanyAdmin, CompanyRole::Manager));
    }

    public function get(Company $company, User $user): AssistanceBotFlow
    {
        abort_unless($this->allows($company, $user), 403);

        return AssistanceBotFlow::firstOrCreate(['company_id' => $company->id], ['draft' => app(AssistanceFlowDefinition::class)->defaults()]);
    }

    public function save(Company $company, User $user, array $definition, int $revision): AssistanceBotFlow
    {
        abort_unless($this->allows($company, $user), 403);
        app(AssistanceFlowDefinition::class)->validate($definition, false);

        return DB::transaction(function () use ($company, $definition, $revision): AssistanceBotFlow {
            $flow = AssistanceBotFlow::where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            $this->checkRevision($flow, $revision);
            $flow->update(['draft' => $definition, 'revision' => $revision + 1]);

            return $flow;
        });
    }

    public function publish(Company $company, User $user, array $definition, int $revision): AssistanceBotFlow
    {
        abort_unless($this->allows($company, $user), 403);
        app(AssistanceFlowDefinition::class)->validate($definition);

        return DB::transaction(function () use ($company, $user, $definition, $revision): AssistanceBotFlow {
            $flow = $this->save($company, $user, $definition, $revision);
            $version = $flow->versions()->create([
                'number' => (int) $flow->versions()->max('number') + 1,
                'definition' => $definition, 'published_by' => $user->id,
            ]);
            $flow->update(['published_version_id' => $version->id]);

            return $flow;
        });
    }

    public function restore(Company $company, User $user, int $versionId, int $revision): AssistanceBotFlow
    {
        $flow = $this->get($company, $user);
        $version = $flow->versions()->findOrFail($versionId);

        return $this->save($company, $user, $version->definition, $revision);
    }

    public function setEnabled(Company $company, User $user, bool $enabled): void
    {
        abort_unless($this->allows($company, $user), 403);
        DB::transaction(function () use ($company, $enabled): void {
            $flow = AssistanceBotFlow::where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            if ($enabled && ! AssistanceBotFlowVersion::where('assistance_bot_flow_id', $flow->id)->whereKey($flow->published_version_id)->exists()) {
                throw ValidationException::withMessages(['flow' => 'Publique uma versão antes de ativar o bot.']);
            }
            $flow->update(['is_enabled' => $enabled]);
        });
    }

    private function checkRevision(AssistanceBotFlow $flow, int $revision): void
    {
        if ($flow->revision !== $revision) {
            throw ValidationException::withMessages(['flow' => 'Outro usuário alterou este rascunho. Recarregue a página antes de salvar.']);
        }
    }
}
