<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Reconhece quando o cliente realmente informou o proprio nome no WhatsApp.
 * Frases como "quero fazer as unhas" ou "quanto custa?" nunca viram nome.
 */
final class CustomerNameDetector
{
    private const NOT_A_NAME = '/\b(quero|queria|gostaria|preciso|tatuagem|tattoo|tatuar|tatoo|orcamento|preco|valor|quanto|custa|agendar|agenda|horario|marcar|fazer|desenho|braco|perna|costas|obrigad[oa]|sim|nao|ok|blz|beleza|tudo|bem|aqui|voces|vcs|voce|vc|informacao|informacoes|duvida|menu|atendente|humano|unha|unhas|cabelo|corte|cortar|escova|progressiva|manicure|pedicure|mao|maos|pe|pes|sobrancelha|sobrancelhas|cilios|depilacao|maquiagem|make|luzes|mechas|tintura|pintar|hidratacao|limpeza|pele|massagem|design|gel|alongamento|servico|servicos|amanha|hoje|semana|manha|tarde|noite|segunda|terca|quarta|quinta|sexta|sabado|domingo|pode|qualquer|tanto|faz|pensei|imagino|penso|algo|assim|tipo|mais|menos|um|uma|de|em|com|estilo|tamanho|local|lugar|parte|cima|baixo|antebraco|ombro|pescoco|costela|coxa|panturrilha|pulso|nuca|peito|barriga|cm|centimetros|referencia|foto|fotos|imagem|lettering|realismo|blackwork|fine|line|fineline|aquarela|tribal|colorida|colorido|sombreado|pontilhismo|minimalista|oi|oie|ola|opa|eae|eai|salve)\b/u';

    /** Partículas comuns em nomes completos ("Maria de Souza"). */
    private const NAME_PARTICLES = ['de', 'da', 'do', 'das', 'dos', 'e', "d'"];

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

        $smallTalk = '(?:oi+|ol[aá]|opa|e ai|eae|bom dia|boa tarde|boa noite|tudo bem|td bem|beleza|blz|tudo certo)';
        $nameIntro = '(?:(?:o\s+)?meu nome [eé]|me chamo|pode me chamar de|me chama de|aqui [eé] (?:o|a)|sou (?:o|a)|sou|[eé] (?:o|a))';

        $candidate = trim($text);
        $candidate = (string) preg_replace('/^(?:'.$smallTalk.'[,!.\s]+)+/iu', '', $candidate);
        $candidate = (string) preg_replace('/^'.$nameIntro.'\s+/iu', '', $candidate);
        $candidate = trim((string) preg_replace('/[\s!.,;:]+$/u', '', $candidate));

        if (! self::isPlausible($candidate) && preg_match('/(?:'.$nameIntro.')\s+(.+)$/iu', trim($text), $matches) === 1) {
            $candidate = trim((string) preg_replace('/[\s!.,;:]+$/u', '', $matches[1]));
        }

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

    /**
     * Nome já gravado num cadastro: aceita nomes completos com partículas
     * ("Maria de Souza", até 8 palavras), mas recusa frases como
     * "queria fazer um orçamento" e números soltos.
     */
    public static function isValidStoredName(?string $name): bool
    {
        $name = Str::squish((string) $name);
        if ($name === '' || mb_strlen($name) > 120) {
            return false;
        }
        if (self::isPlausible($name)) {
            return true;
        }
        $words = array_values(array_filter(preg_split('/\s+/u', $name) ?: [],
            fn (string $word) => ! in_array(Str::lower(Str::ascii($word)), self::NAME_PARTICLES, true)));
        if ($words === [] || count($words) > 8) {
            return false;
        }
        foreach ($words as $word) {
            if (! preg_match('/^\p{L}[\p{L}\'\-.]*$/u', $word) || preg_match(self::NOT_A_NAME, Str::lower(Str::ascii($word)))) {
                return false;
            }
        }

        return true;
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
