<?php

namespace App\Enums;

enum WhatsAppBotConversationState: string
{
    case Greeting = 'greeting';
    case ChoosingService = 'choosing_service';
    case ChoosingProfessional = 'choosing_professional';
    case ChoosingDate = 'choosing_date';
    case ChoosingTime = 'choosing_time';
    case CollectingName = 'collecting_name';
    case CollectingEmail = 'collecting_email';
    case AcceptingTerms = 'accepting_terms';
    case Confirming = 'confirming';
    case Done = 'done';
    case TattooName = 'tattoo_name';
    case TattooDescription = 'tattoo_description';
    case TattooPlacement = 'tattoo_placement';
    case TattooSize = 'tattoo_size';
    case TattooPhoto = 'tattoo_photo';
    case TattooConfirm = 'tattoo_confirm';

    public function label(): string
    {
        return match ($this) {
            self::Greeting => 'Saudação',
            self::ChoosingService => 'Escolhendo serviço',
            self::ChoosingProfessional => 'Escolhendo profissional',
            self::ChoosingDate => 'Escolhendo data',
            self::ChoosingTime => 'Escolhendo horário',
            self::CollectingName => 'Coletando nome',
            self::CollectingEmail => 'Coletando e-mail',
            self::AcceptingTerms => 'Aceitando termos',
            self::Confirming => 'Confirmando',
            self::Done => 'Finalizado',
            self::TattooName => 'Nome para orçamento',
            self::TattooDescription => 'Desenho da tatuagem',
            self::TattooPlacement => 'Local da tatuagem',
            self::TattooSize => 'Tamanho da tatuagem',
            self::TattooPhoto => 'Foto de referência',
            self::TattooConfirm => 'Confirmando pedido',
        };
    }
}
