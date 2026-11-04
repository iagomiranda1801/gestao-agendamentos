<?php

namespace App\Services\Tattoo;

use Illuminate\Support\Str;

/**
 * Lê uma mensagem que o tatuador digitou no WhatsApp do estúdio e tenta achar
 * um orçamento: valor em dinheiro (R$ 450, 450,00, 1.200, "fica 600") e,
 * opcionalmente, o sinal ("sinal de 150"). Horários, datas, telefones e
 * valores abaixo de R$ 50 nunca contam.
 */
class ManualQuoteDetector
{
    public const MIN_AMOUNT = 50.0;

    private const PRICE_WORDS = '(fica|ficaria|ficou|valor|total|sai|saira|sairia|custa|custaria|cobro|cobrar|orcamento|preco|investimento|por)';

    private const DEPOSIT_WORDS = '(sinal|entrada|adiantamento|reserva|deposito)';

    /**
     * @return array{total: float, deposit: ?float, totals: list<float>}|null
     */
    public function detect(string $text): ?array
    {
        $clean = $this->stripNoise(Str::lower(Str::ascii($text)));
        $totals = [];
        $deposit = null;

        preg_match_all('/(r\$\s*)?(?<![\d.,])(\d{1,3}(?:\.\d{3})+|\d+)(,\d{1,2})?(?![\d.,]*\d)(\s*(?:reais|conto|contos|pila))?/u', $clean, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $offset = $match[0][1];
            $before = substr($clean, max(0, $offset - 30), min(30, $offset));
            $hasCurrency = trim($match[1][0] ?? '') !== '' || trim($match[4][0] ?? '') !== '';
            $hasThousands = str_contains($match[2][0], '.');
            $hasCents = ($match[3][0] ?? '') !== '';
            $isDeposit = (bool) preg_match('/\b'.self::DEPOSIT_WORDS.'\b[^0-9]{0,15}$/u', $before);
            $hasPriceWord = (bool) preg_match('/\b'.self::PRICE_WORDS.'\b[^0-9]{0,15}$/u', $before);
            if (! ($hasCurrency || $hasThousands || $hasCents || $isDeposit || $hasPriceWord)) {
                continue;
            }
            $value = (float) (str_replace('.', '', $match[2][0]).'.'.ltrim($match[3][0] ?? '', ',').'0');
            if ($value < self::MIN_AMOUNT) {
                continue;
            }
            if ($isDeposit) {
                $deposit ??= $value;

                continue;
            }
            if (! in_array($value, $totals, true)) {
                $totals[] = $value;
            }
        }

        if ($totals === []) {
            return null;
        }

        return ['total' => $totals[0], 'deposit' => $deposit, 'totals' => $totals];
    }

    /** Tira horários, datas e telefones antes de procurar valores. */
    private function stripNoise(string $text): string
    {
        $patterns = [
            '/\+?\d[\d\s().-]{8,}\d/u',                                   // telefones
            '/\b\d{1,2}\/\d{1,2}(\/\d{2,4})?\b/u',                       // 15/11, 15/11/2026
            '/\b\d{1,2}:\d{2}\b/u',                                       // 14:00
            '/\b\d{1,2}\s*(h|hs|hrs|hr|horas?)(\d{2})?\b/u',              // 14h, 14h30, 3 horas
            '/\b(das|a partir das|as|ate as)\s+\d{1,2}(?![\d.,])(\s*(as|a|ate)\s+\d{1,2}(?![\d.,]))?/u', // das 14 as 22
            '/\bdia\s+\d{1,2}\b/u',                                       // dia 15
            '/\b\d+\s*(sess(ao|oes)|cm|mm|min|minutos|dias?|semanas?|meses|x)\b/u',
        ];

        return (string) preg_replace($patterns, ' ', $text);
    }
}
