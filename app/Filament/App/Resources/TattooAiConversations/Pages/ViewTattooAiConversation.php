<?php

namespace App\Filament\App\Resources\TattooAiConversations\Pages;

use App\Filament\App\Resources\TattooAiConversations\TattooAiConversationResource;
use App\Filament\App\Resources\TattooRequests\TattooRequestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewTattooAiConversation extends ViewRecord
{
    protected static string $resource = TattooAiConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('takeover')->label('Assumir atendimento')->requiresConfirmation()
                ->visible(fn () => $this->canManageConversation() && ! $this->getRecord()->human_takeover)
                ->action(fn () => $this->getRecord()->update(['human_takeover' => true, 'status' => 'human_takeover'])),
            Action::make('resume_ai')->label('Devolver para IA')->requiresConfirmation()
                ->visible(fn () => $this->canManageConversation() && $this->getRecord()->human_takeover)
                ->action(fn () => $this->getRecord()->update(['human_takeover' => false, 'status' => 'collecting_information'])),
        ];
    }

    protected function canManageConversation(): bool
    {
        return TattooRequestResource::canManageRequests()
            || (($this->getRecord()->request?->professional_id ?? $this->getRecord()->professional_id) !== null
                && (int) ($this->getRecord()->request?->professional?->user_id ?? $this->getRecord()->professional?->user_id) === (int) auth()->id());
    }
}
