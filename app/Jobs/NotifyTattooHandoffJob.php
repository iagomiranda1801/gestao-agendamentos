<?php

namespace App\Jobs;

use App\Enums\CompanyRole;
use App\Filament\App\Resources\TattooAiConversations\TattooAiConversationResource;
use App\Models\Professional;
use App\Models\TattooAiConversation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Avisa admins, gerentes e tatuadores que a IA passou a conversa para um humano. */
class NotifyTattooHandoffJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $conversationId, public string $reason) {}

    public function handle(): void
    {
        $conversation = TattooAiConversation::query()->with(['company', 'client'])->find($this->conversationId);
        if (! $conversation) {
            return;
        }
        $company = $conversation->company;
        $users = $company->users()->wherePivot('is_active', true)->where('users.is_active', true)->get()
            ->filter(fn ($user) => in_array($user->pivot->role instanceof CompanyRole ? $user->pivot->role->value : $user->pivot->role,
                [CompanyRole::CompanyAdmin->value, CompanyRole::Manager->value], true))
            ->keyBy('id');
        Professional::query()->where('company_id', $company->id)->active()->with('user')->get()
            ->each(function (Professional $professional) use ($users, $company): void {
                if ($professional->user && $professional->user->hasActiveCompanyMembershipWith($company)) {
                    $users->put($professional->user->id, $professional->user);
                }
            });
        if ($users->isEmpty()) {
            return;
        }
        $name = $conversation->client?->name ?? ($conversation->collected_data['name'] ?? null) ?? $conversation->phone_normalized;
        $url = TattooAiConversationResource::getUrl('view', ['record' => $conversation], panel: 'app', tenant: $company);
        Notification::make()->title('Cliente aguardando o tatuador')
            ->body($name.' · '.$this->reason.' · A IA parou de responder nessa conversa.')
            ->actions([Action::make('view')->label('Abrir conversa')->url($url)->markAsRead()])
            ->sendToDatabase($users->values());
    }
}
