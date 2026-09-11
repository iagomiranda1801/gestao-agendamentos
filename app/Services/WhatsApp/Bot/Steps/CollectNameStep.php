<?php

namespace App\Services\WhatsApp\Bot\Steps;

use App\Services\WhatsApp\Bot\BotAction;
use App\Services\WhatsApp\Bot\BotClientLookup;
use App\Services\WhatsApp\Bot\BotContext;
use App\Services\WhatsApp\Bot\BotStep;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotMessageBuilder;
use App\Support\PublicBookingTextSanitizer;

class CollectNameStep implements BotStep
{
    public function __construct(
        protected WhatsAppBookingBotMessageBuilder $messages,
        protected BotClientLookup $clients,
    ) {}

    public function prompt(BotContext $context): string
    {
        $existing = $this->clients->find($context);

        if ($existing !== null && filled($existing->name)) {
            return "Encontrei seu cadastro: *{$existing->name}*. Está correto?\n\n"
                ."1 - Sim, sou eu\n"
                ."2 - Não, quero atualizar o nome";
        }

        return $this->messages->askName();
    }

    public function process(BotContext $context, string $input): BotAction
    {
        $trimmed = trim($input);
        $existing = $this->clients->find($context);

        if ($existing !== null && filled($existing->name)) {
            if ($trimmed === '1') {
                return $this->clients->advanceAfterIdentity(
                    $context,
                    (string) $existing->name,
                    filled($existing->email) ? (string) $existing->email : null,
                    $existing,
                );
            }

            if ($trimmed === '2') {
                return BotAction::stay($this->messages->askName());
            }

            if (mb_strlen(PublicBookingTextSanitizer::clientName($trimmed) ?? '') >= 3
                && ! in_array($trimmed, ['1', '2'], true)) {
                return $this->clients->advanceAfterIdentity($context, (string) PublicBookingTextSanitizer::clientName($trimmed));
            }

            return BotAction::stay($this->messages->invalidOption());
        }

        $name = PublicBookingTextSanitizer::clientName($trimmed);

        if (blank($name) || mb_strlen((string) $name) < 3) {
            return BotAction::stay("Informe o *nome completo* (mínimo 3 caracteres).");
        }

        return $this->clients->advanceAfterIdentity($context, (string) $name);
    }
}
