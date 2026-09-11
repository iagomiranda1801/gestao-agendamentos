<?php

namespace App\Services\WhatsApp\Bot;

use App\Enums\WhatsAppBotConversationState;

class BotAction
{
    /**
     * @param  array<string, mixed>  $dataUpdates
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?WhatsAppBotConversationState $nextState = null,
        public readonly array $dataUpdates = [],
        public readonly ?string $errorMessage = null,
        public readonly ?string $closingMessage = null,
        public readonly ?string $finishedReason = null,
        public readonly ?int $appointmentId = null,
    ) {}

    /**
     * Manter o estado atual e reenviar o prompt, opcionalmente com uma mensagem de erro.
     */
    public static function stay(?string $errorMessage = null): self
    {
        return new self(kind: 'stay', errorMessage: $errorMessage);
    }

    /**
     * Avançar para o próximo estado, mesclando dados na conversa.
     *
     * @param  array<string, mixed>  $dataUpdates
     */
    public static function goTo(WhatsAppBotConversationState $state, array $dataUpdates = []): self
    {
        return new self(kind: 'go_to', nextState: $state, dataUpdates: $dataUpdates);
    }

    /**
     * Encerrar a conversa após sucesso (agendamento criado).
     *
     * @param  array<string, mixed>  $dataUpdates
     */
    public static function finish(
        string $closingMessage,
        ?int $appointmentId = null,
        array $dataUpdates = [],
        string $finishedReason = 'completed',
    ): self {
        return new self(
            kind: 'finish',
            nextState: WhatsAppBotConversationState::Done,
            dataUpdates: $dataUpdates,
            closingMessage: $closingMessage,
            finishedReason: $finishedReason,
            appointmentId: $appointmentId,
        );
    }

    /**
     * Encerrar sem sucesso (handoff, cancelamento, erro).
     */
    public static function abandon(string $closingMessage, string $finishedReason): self
    {
        return new self(
            kind: 'abandon',
            closingMessage: $closingMessage,
            finishedReason: $finishedReason,
        );
    }
}
