<?php

namespace App\Filament\App\Resources\TattooAiConversations\Pages;

use App\Filament\App\Resources\TattooAiConversations\TattooAiConversationResource;
use App\Filament\App\Resources\TattooRequests\TattooRequestResource;
use App\Models\TattooAiMessage;
use App\Models\WhatsAppContact;
use App\Services\Tattoo\TattooConversationTakeoverService;
use App\Services\Tattoo\TattooManualReplyService;
use App\Support\PhoneNormalizer;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ViewTattooAiConversation extends ViewRecord
{
    protected static string $resource = TattooAiConversationResource::class;

    protected string $view = 'filament.app.resources.tattoo-ai-conversations.view-conversation';

    public string $messageDraft = '';

    public int $messageLimit = 80;

    public function getTitle(): string
    {
        $conversation = $this->getRecord();

        return 'Conversa com '.($conversation->client?->name ?: $conversation->phone_normalized);
    }

    public function getChatMessages(): Collection
    {
        return $this->getRecord()->messages()->latest('id')->limit($this->messageLimit)
            ->get()->reverse()->values();
    }

    public function hasOlderMessages(): bool
    {
        return $this->getRecord()->messages()->count() > $this->messageLimit;
    }

    public function loadOlderMessages(): void
    {
        $this->messageLimit += 80;
    }

    public function messageAttachmentUrl(TattooAiMessage $message): ?string
    {
        if (! $message->media_mime || ! $message->provider_message_id) {
            return null;
        }
        $conversation = $this->getRecord();
        $image = $conversation->request?->images()
            ->where('company_id', $conversation->company_id)
            ->where('whatsapp_message_id', $message->provider_message_id)->first();
        if ($image) {
            return route('tattoo.images.download', ['company' => $conversation->company, 'image' => $image]);
        }
        $receipt = $conversation->receipts()->where('company_id', $conversation->company_id)
            ->where('whatsapp_message_id', $message->provider_message_id)->first();

        return $receipt ? route('tattoo.receipts.download', ['company' => $conversation->company, 'receipt' => $receipt]) : null;
    }

    public function sendReply(TattooManualReplyService $replies): void
    {
        abort_unless($this->canManageConversation(), 403);
        $body = trim($this->messageDraft);
        if ($body === '' || mb_strlen($body) > 4000) {
            throw ValidationException::withMessages(['messageDraft' => 'Escreva uma mensagem de até 4.000 caracteres.']);
        }

        try {
            $replies->send($this->getRecord(), $body);
            $this->messageDraft = '';
            $this->dispatch('tattoo-chat-sent');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            Notification::make()->danger()->title('Não foi possível confirmar o envio.')
                ->body('Confira a conversa no WhatsApp antes de tentar novamente.')->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('takeover')->label('Assumir atendimento')->requiresConfirmation()
                ->visible(fn () => $this->canManageConversation() && ! $this->getRecord()->human_takeover)
                ->modalDescription('A IA será pausada. Confira o nome antes de vincular o celular ao cadastro do cliente.')
                ->form([
                    TextInput::make('name')->label('Nome do cliente')->required()->minLength(2)->maxLength(255)
                        ->default(fn (): string => $this->suggestedClientName()),
                    TextInput::make('phone')->label('Celular do WhatsApp')
                        ->default(fn (): string => $this->getRecord()->phone_normalized)->disabled()->dehydrated(false),
                ])
                ->action(function (array $data): void {
                    abort_unless($this->canManageConversation(), 403);
                    app(TattooConversationTakeoverService::class)->takeOver($this->getRecord(), $data['name']);
                    $this->getRecord()->refresh();
                    Notification::make()->success()->title('Atendimento assumido e cliente vinculado.')->send();
                }),
            Action::make('resume_ai')->label('Devolver para IA')->requiresConfirmation()
                ->visible(fn () => $this->canManageConversation() && $this->getRecord()->human_takeover)
                ->action(fn () => $this->getRecord()->update(['human_takeover' => false, 'status' => 'collecting_information'])),
        ];
    }

    public function canManageConversation(): bool
    {
        return TattooRequestResource::canManageRequests()
            || (($this->getRecord()->request?->professional_id ?? $this->getRecord()->professional_id) !== null
                && (int) ($this->getRecord()->request?->professional?->user_id ?? $this->getRecord()->professional?->user_id) === (int) auth()->id());
    }

    public function suggestedClientName(): string
    {
        $conversation = $this->getRecord();
        $name = $conversation->client?->name ?: ($conversation->collected_data['name'] ?? null);
        if (filled($name) && ! $this->isPlaceholderName((string) $name)) {
            return trim((string) $name);
        }

        $contact = WhatsAppContact::query()
            ->where('company_id', $conversation->company_id)
            ->where('company_whatsapp_instance_id', $conversation->company_whatsapp_instance_id)
            ->whereIn('phone_normalized', PhoneNormalizer::candidates($conversation->phone_normalized))
            ->first();

        return $contact && filled($contact->name) && ! $this->isPlaceholderName($contact->name)
            && PhoneNormalizer::normalize($contact->name) !== PhoneNormalizer::normalize($conversation->phone_normalized)
            ? trim((string) $contact->name) : '';
    }

    protected function isPlaceholderName(string $name): bool
    {
        return preg_match('/^(?:Cliente WhatsApp|Contato)\s*\d+$/iu', trim($name)) === 1
            || ctype_digit(trim($name));
    }
}
