<?php

namespace App\Support;

use Illuminate\Support\Str;

class WhatsAppInboundText
{
    public static function normalize(string $text): string
    {
        $normalized = Str::lower(Str::ascii(trim($text)));
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';

        return trim($normalized);
    }

    public static function containsPhrase(string $text, string $phrase): bool
    {
        $normalized = self::normalize($text);
        $phrase = self::normalize($phrase);

        if ($normalized === '' || $phrase === '') {
            return false;
        }

        if ($normalized === $phrase) {
            return true;
        }

        $pattern = '/(?:^|\s)'.preg_quote($phrase, '/').'(?:\s|$)/u';

        return preg_match($pattern, $normalized) === 1;
    }

    /**
     * @param  list<string>  $phrases
     */
    public static function containsAnyPhrase(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (self::containsPhrase($text, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
