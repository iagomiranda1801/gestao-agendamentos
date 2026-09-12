<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Received = 'received';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case OutForDelivery = 'out_for_delivery';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recebido',
            self::Preparing => 'Preparando',
            self::Ready => 'Pronto',
            self::OutForDelivery => 'Saiu para entrega',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Received => 'warning',
            self::Preparing => 'info',
            self::Ready => 'success',
            self::OutForDelivery => 'primary',
            self::Completed => 'gray',
            self::Cancelled => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function timestampColumn(): string
    {
        return match ($this) {
            self::Received => 'received_at',
            self::Preparing => 'preparing_at',
            self::Ready => 'ready_at',
            self::OutForDelivery => 'out_for_delivery_at',
            self::Completed => 'completed_at',
            self::Cancelled => 'cancelled_at',
        };
    }

    public function next(?OrderFulfillment $fulfillment = null): ?self
    {
        return match ($this) {
            self::Received => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => $fulfillment === OrderFulfillment::Delivery
                ? self::OutForDelivery
                : self::Completed,
            self::OutForDelivery => self::Completed,
            default => null,
        };
    }

    public function canTransitionTo(self $next, ?OrderFulfillment $fulfillment = null): bool
    {
        return $this->next($fulfillment) === $next;
    }

    /**
     * @return list<self>
     */
    public static function kitchenColumns(): array
    {
        return [
            self::Received,
            self::Preparing,
            self::Ready,
            self::OutForDelivery,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->all();
    }
}
