<?php

namespace App\Enums;

enum AttendanceHistoryType: string
{
    case Completed = 'completed';
    case PaymentRecorded = 'payment_recorded';
    case PaymentCancelled = 'payment_cancelled';
    case ReceivableUpdated = 'receivable_updated';
    case NotesUpdated = 'notes_updated';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Atendimento concluído',
            self::PaymentRecorded => 'Pagamento registrado',
            self::PaymentCancelled => 'Pagamento cancelado',
            self::ReceivableUpdated => 'Conta a receber atualizada',
            self::NotesUpdated => 'Observações atualizadas',
        };
    }
}
