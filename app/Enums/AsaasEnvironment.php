<?php

namespace App\Enums;

enum AsaasEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Sandbox => 'Sandbox',
            self::Production => 'Produção',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $environment): array => [$environment->value => $environment->label()])
            ->all();
    }
}
