<?php

namespace Tests\Unit\Support;

use App\Support\CustomerNameDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerNameDetectorTest extends TestCase
{
    #[DataProvider('names')]
    public function test_extracts_real_customer_names(string $message, string $expected): void
    {
        $this->assertSame($expected, CustomerNameDetector::fromMessage($message));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function names(): array
    {
        return [
            'plain' => ['Ana Souza', 'Ana Souza'],
            'me_chamo' => ['me chamo Kauã', 'Kauã'],
            'small_talk_then_name' => ['tudo bem, me chamo Kauã', 'Kauã'],
            'blz_prefix' => ['blz, meu nome é Maria', 'Maria'],
        ];
    }

    #[DataProvider('notNames')]
    public function test_does_not_treat_ideas_or_greetings_as_names(string $message): void
    {
        $this->assertNull(CustomerNameDetector::fromMessage($message));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notNames(): array
    {
        return [
            'greeting' => ['tudo bem'],
            'tattoo_idea' => ['quero uma tattoo de lobo'],
            'price' => ['quanto custa?'],
            'date' => ['Pra mim fica melhor em janeiro, o dia que estiver disponível'],
        ];
    }
}
