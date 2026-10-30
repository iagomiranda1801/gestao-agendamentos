<?php

namespace App\Services\Tattoo;

use App\Models\Company;
use App\Models\Professional;
use Illuminate\Support\Str;

/**
 * Cuidados pós-tatuagem, endereço do estúdio e passagem para o tatuador,
 * com os textos editáveis nas configurações da IA de tatuagem.
 */
final class TattooStudioInfo
{
    public const DEFAULT_AFTERCARE = "Bora cuidar dessa tattoo 🙌\n"
        ."• Lave 2 a 3 vezes ao dia com água e sabonete neutro, sem esfregar, e seque com papel toalha dando batidinhas\n"
        ."• Passe uma camada fina da pomada indicada pelo tatuador, sem exagero\n"
        ."• Nada de coçar ou arrancar as casquinhas\n"
        ."• Evite sol, piscina, mar e sauna por uns 15 a 20 dias\n"
        ."• Use roupa leve que não fique roçando no local\n"
        .'Se aparecer inflamação, pus, dor forte ou alergia, me avisa aqui que eu chamo o tatuador.';

    public const DEFAULT_ADDRESS = 'O *{empresa}* fica em: {endereco}';

    public static function aftercare(Company $company): string
    {
        $text = trim((string) $company->schedulingSetting?->tattoo_aftercare_message);

        return $text !== '' ? $text : self::DEFAULT_AFTERCARE;
    }

    /** Endereço formatado no modelo configurado; null quando não há endereço cadastrado. */
    public static function address(Company $company): ?string
    {
        $address = trim((string) $company->schedulingSetting?->tattoo_studio_address);
        if ($address === '') {
            return null;
        }
        $template = trim((string) $company->schedulingSetting?->tattoo_address_message);

        return strtr($template !== '' ? $template : self::DEFAULT_ADDRESS,
            ['{endereco}' => $address, '{endereço}' => $address, '{empresa}' => $company->name]);
    }

    /** Nome configurado, o único tatuador ativo ou "o tatuador". */
    public static function artistName(Company $company): string
    {
        $name = trim((string) $company->schedulingSetting?->tattoo_artist_name);
        if ($name !== '') {
            return $name;
        }
        $names = Professional::query()->where('company_id', $company->id)->active()->pluck('name')->all();

        return count($names) === 1 ? Str::of($names[0])->trim()->explode(' ')->first() : 'o tatuador';
    }

    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($text))));
    }

    public static function isAftercareQuestion(string $normalized): bool
    {
        return (bool) preg_match('/\b(cuidados?|cuidar|cicatriza\w*|pomada|aftercare|descascand\w*|casquinha\w*|cocand\w*|coceira|pos[- ]tattoo|pos[- ]tatuagem)\b/u', $normalized)
            && ! preg_match('/\b(antes de tatuar|antes da sessao)\b/u', $normalized);
    }

    public static function isSeriousIssue(string $normalized): bool
    {
        return (bool) preg_match('/\b(inflam\w*|infec\w*|infecc\w*|pus|dor forte|muita dor|doendo muito|alergi\w*|inchad\w*|inchaco|retoque|retocar|touch ?up|febre|sangrando)\b/u', $normalized);
    }

    public static function isAddressQuestion(string $normalized): bool
    {
        return (bool) preg_match('/\b(endereco|localizacao|onde (?:fica|e o estudio|voces ficam|e ai)|como (?:chego|chegar)|qual (?:o )?local do estudio|manda (?:a )?(?:localizacao|location))\b/u', $normalized)
            && ! preg_match('/\bonde (?:fica|e) melhor\b/u', $normalized);
    }

    public static function asksForArtist(string $normalized, Company $company): bool
    {
        if (preg_match('/\b(falar|conversar|chamar?|chama)\b.{0,20}\b(tatuador|tatuadora|tatuador responsavel)\b/u', $normalized)) {
            return true;
        }
        $names = Professional::query()->where('company_id', $company->id)->active()->pluck('name')->all();
        $configured = trim((string) $company->schedulingSetting?->tattoo_artist_name);
        if ($configured !== '') {
            $names[] = $configured;
        }
        foreach ($names as $name) {
            $first = self::normalize((string) Str::of($name)->trim()->explode(' ')->first());
            if ($first !== '' && preg_match('/\b(falar|conversar)\b.{0,12}\b'.preg_quote($first, '/').'\b/u', $normalized)) {
                return true;
            }
        }

        return false;
    }

    public static function handoffMessage(Company $company, string $reason): string
    {
        $artist = self::artistName($company);

        return 'Já avisei '.($artist === 'o tatuador' ? 'o tatuador' : 'o '.$artist).$reason.', ele te responde por aqui 🤙';
    }
}
