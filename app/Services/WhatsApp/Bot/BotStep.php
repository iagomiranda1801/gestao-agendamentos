<?php

namespace App\Services\WhatsApp\Bot;

interface BotStep
{
    /**
     * Texto enviado quando o cliente entra neste estado.
     */
    public function prompt(BotContext $context): string;

    /**
     * Processa a mensagem do cliente e devolve a ação a executar.
     */
    public function process(BotContext $context, string $input): BotAction;
}
