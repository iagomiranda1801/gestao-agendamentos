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
            'single' => ['Paulo', 'Paulo'],
            'me_chamo' => ['me chamo Kauã', 'Kauã'],
            'small_talk_then_name' => ['tudo bem, me chamo Kauã', 'Kauã'],
            'blz_prefix' => ['blz, meu nome é Maria', 'Maria'],
            'lowercase' => ['lucas', 'Lucas'],
            'me_chamo_lower' => ['me chamo lucas', 'Lucas'],
            'two_names' => ['Ana Paula', 'Ana Paula'],
            'fala_neto' => ['Fala Neto', 'Fala Neto'],
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
            'idea_phrase' => ['Pensei em algo assim'],
            'style_answer' => ['o estilo é Lettering'],
            'opa' => ['Opa'],
            'request_phrase' => ['queria fazer um outro orçamento de tatto'],
            'size' => ['25cm'],
            'body_part' => ['no antebraço, parte de cima'],
        ];
    }

    public function test_stored_names_accept_particles_but_not_phrases(): void
    {
        $this->assertTrue(CustomerNameDetector::isValidStoredName('Maria de Souza'));
        $this->assertTrue(CustomerNameDetector::isValidStoredName('Lucas'));
        $this->assertFalse(CustomerNameDetector::isValidStoredName('queria fazer um orçamento'));
        $this->assertFalse(CustomerNameDetector::isValidStoredName('5511999999999'));
        $this->assertFalse(CustomerNameDetector::isPlausible('o estilo é Lettering'));
        $this->assertFalse(CustomerNameDetector::isPlausible('Opa'));
    }
}
