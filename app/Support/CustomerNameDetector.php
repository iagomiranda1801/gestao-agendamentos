<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Reconhece quando o cliente realmente informou o proprio nome no WhatsApp.
 * Frases como "quero fazer as unhas" ou "quanto custa?" nunca viram nome.
 */
final class CustomerNameDetector
{
    private const NOT_A_NAME = '/\b(quero|queria|gostaria|preciso|tatuagem|tattoo|tatuar|tatoo|orcamento|preco|valor|quanto|custa|agendar|agenda|horario|marcar|fazer|desenho|braco|perna|costas|obrigad[oa]|sim|nao|ok|blz|beleza|tudo|bem|aqui|voces|vcs|voce|vc|informacao|informacoes|duvida|menu|atendente|humano|unha|unhas|cabelo|corte|cortar|escova|progressiva|manicure|pedicure|mao|maos|pe|pes|sobrancelha|sobrancelhas|cilios|depilacao|maquiagem|make|luzes|mechas|tintura|pintar|hidratacao|limpeza|pele|massagem|design|gel|alongamento|servico|servicos|amanha|hoje|semana|manha|tarde|noite|segunda|terca|quarta|quinta|sexta|sabado|domingo|pode|qualquer|tanto|faz)\b/u';

    public static function isGreeting(string $text): bool
    {
        $normalized = Str::lower(Str::ascii(trim($text)));

        return (bool) preg_match('/^(oi+|ola|opa|e ai|eae|bom dia|boa tarde|boa noite|tudo bem)(,? tudo bem)?[!.? ]*$/u', $normalized);
    }

    /**
     * Extrai o nome de uma resposta como "Ana", "meu nome é Ana Souza" ou "sou a Bia".
     */
    public static function fromMessage(string $text): ?string
    {
        if (self::isGreeting($text) || str_contains($text, '?')) {
            return null;
        }

        $candidate = trim($text);
        $candidate = (string) preg_replace('/^(?:(?:oi+|ol[aá]|opa|bom dia|boa tarde|boa noite)[,!.\s]+)?(?:(?:o\s+)?meu nome [eé]|me chamo|pode me chamar de|me chama de|aqui [eé] (?:o|a)|sou (?:o|a)|sou|[eé] (?:o|a))\s+/iu', '', $candidate);
        $candidate = trim((string) preg_replace('/[\s!.,;:]+$/u', '', $candidate));

        if (! self::isPlausible($candidate)) {
            return null;
        }

        return $candidate === Str::lower($candidate) ? mb_convert_case($candidate, MB_CASE_TITLE, 'UTF-8') : $candidate;
    }

    /**
     * Valida um nome sugerido pela IA: precisa parecer nome de pessoa e ter
     * sido escrito pelo próprio cliente na mensagem.
     */
    public static function acceptFromModel(?string $name, string $message): ?string
    {
        $name = Str::squish((string) $name);
        if (! self::isPlausible($name)) {
            return null;
        }
        $haystack = ' '.Str::lower(Str::ascii($message)).' ';
        foreach (preg_split('/\s+/u', Str::lower(Str::ascii($name))) ?: [] as $part) {
            if ($part === '' || ! preg_match('/[^a-z0-9]'.preg_quote($part, '/').'[^a-z0-9]/', $haystack)) {
                return null;
            }
        }

        return $name === Str::lower($name) ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : $name;
    }

    public static function isPlausible(string $candidate): bool
    {
        if (mb_strlen($candidate) < 2 || mb_strlen($candidate) > 60
            || ! preg_match('/^\p{L}[\p{L}\'\-]*(?:\s+\p{L}[\p{L}\'\-]*){0,3}$/u', $candidate)) {
            return false;
        }

        return ! preg_match(self::NOT_A_NAME, Str::lower(Str::ascii($candidate)));
    }
}
