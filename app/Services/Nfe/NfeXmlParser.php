<?php

namespace App\Services\Nfe;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

/**
 * Lê o XML de uma NF-e (4.00) — nfeProc ou NFe solta — e devolve um array
 * simples e serializável, com valores monetários como strings decimais.
 *
 * @phpstan-type Item array{
 *     index: int, code: string, ean: ?string, ean_tax: ?string, name: string, ncm: ?string,
 *     unit: string, quantity: string, unit_price: string, total: string, discount: string,
 *     freight: string, insurance: string, other: string, ipi: string, st: string,
 *     unit_tax: ?string, quantity_tax: ?string, cost_total: string
 * }
 */
class NfeXmlParser
{
    public const NAMESPACE_URI = 'http://www.portalfiscal.inf.br/nfe';

    public const MAX_BYTES = 5_242_880;

    /**
     * @return array<string, mixed>
     */
    public function parse(string $xml): array
    {
        $xml = $this->prepare($xml);
        $dom = $this->load($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', self::NAMESPACE_URI);

        $root = $dom->documentElement?->localName;

        if (in_array($root, ['procEventoNFe', 'envEvento', 'resNFe', 'resEvento', 'inutNFe', 'procInutNFe'], true)) {
            throw new NfeParseException('Este XML não é uma NF-e completa (parece ser evento, resumo ou inutilização). Envie o XML da nota com os itens.');
        }

        $inf = $xpath->query('//n:NFe/n:infNFe')->item(0);

        if (! $inf instanceof DOMElement) {
            throw new NfeParseException('Não encontrei uma NF-e neste XML. Confira se o arquivo é o XML da nota fiscal eletrônica (NF-e 4.00).');
        }

        $warnings = [];

        $status = $this->text($xpath, '//n:protNFe/n:infProt/n:cStat');

        if ($status !== null && ! in_array($status, ['100', '150'], true)) {
            throw new NfeParseException("Esta NF-e não está autorizada (código {$status} na SEFAZ). Não é possível importá-la.");
        }

        if ($status === null) {
            $warnings[] = 'O XML não traz o protocolo de autorização da SEFAZ (nfeProc). Confira se a nota está autorizada.';
        }

        $version = $inf->getAttribute('versao');

        if ($version !== '' && ! str_starts_with($version, '4.')) {
            $warnings[] = "Versão do layout {$version} não é a 4.00; confira os valores antes de confirmar.";
        }

        $accessKey = $this->accessKey($xpath, $inf);
        $issuedAt = $this->issuedAt($xpath, $inf);

        $items = $this->items($xpath, $inf);

        if ($items === []) {
            throw new NfeParseException('A NF-e não possui itens (det/prod).');
        }

        $totals = $this->totals($xpath, $inf, $items);

        $items = $this->applyCosts($items, $totals);

        return [
            'access_key' => $accessKey,
            'version' => $version !== '' ? $version : null,
            'model' => $this->text($xpath, 'n:ide/n:mod', $inf),
            'number' => (string) $this->text($xpath, 'n:ide/n:nNF', $inf),
            'series' => (string) $this->text($xpath, 'n:ide/n:serie', $inf),
            'nature' => $this->text($xpath, 'n:ide/n:natOp', $inf),
            'issued_at' => $issuedAt->format('Y-m-d\TH:i:sP'),
            'issuer' => $this->issuer($xpath, $inf),
            'recipient' => [
                'document' => $this->digits($this->text($xpath, 'n:dest/n:CNPJ', $inf) ?? $this->text($xpath, 'n:dest/n:CPF', $inf)),
                'name' => $this->text($xpath, 'n:dest/n:xNome', $inf),
            ],
            'items' => $items,
            'totals' => $totals,
            'installments' => $this->installments($xpath, $inf, $issuedAt),
            'warnings' => $warnings,
        ];
    }

    protected function prepare(string $xml): string
    {
        if (strlen($xml) > self::MAX_BYTES) {
            throw new NfeParseException('O arquivo XML é grande demais (limite de 5 MB).');
        }

        $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");

        if ($xml === '') {
            throw new NfeParseException('O arquivo XML está vazio.');
        }

        // NF-e não usa DTD. Recusar de vez elimina XXE e "billion laughs".
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new NfeParseException('XML recusado: NF-e válida não contém DOCTYPE nem entidades.');
        }

        return $xml;
    }

    protected function load(string $xml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;
            $dom->preserveWhiteSpace = false;

            // Sem LIBXML_NOENT/DTDLOAD: entidades externas nunca são resolvidas.
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOWARNING | LIBXML_NOERROR);

            if (! $loaded || $dom->documentElement === null) {
                throw new NfeParseException('O arquivo não é um XML válido.');
            }

            if ($dom->doctype !== null) {
                throw new NfeParseException('XML recusado: NF-e válida não contém DOCTYPE nem entidades.');
            }

            return $dom;
        } catch (NfeParseException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new NfeParseException('O arquivo não é um XML válido.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    protected function accessKey(DOMXPath $xpath, DOMElement $inf): string
    {
        $key = $this->digits(substr($inf->getAttribute('Id'), 3));

        if (strlen((string) $key) !== 44) {
            $key = $this->digits($this->text($xpath, '//n:protNFe/n:infProt/n:chNFe'));
        }

        if (strlen((string) $key) !== 44) {
            throw new NfeParseException('Não consegui ler a chave de acesso (44 dígitos) da NF-e.');
        }

        return (string) $key;
    }

    protected function issuedAt(DOMXPath $xpath, DOMElement $inf): CarbonImmutable
    {
        $raw = $this->text($xpath, 'n:ide/n:dhEmi', $inf) ?? $this->text($xpath, 'n:ide/n:dEmi', $inf);

        if ($raw === null) {
            throw new NfeParseException('Não encontrei a data de emissão da NF-e.');
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            throw new NfeParseException('A data de emissão da NF-e é inválida.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function issuer(DOMXPath $xpath, DOMElement $inf): array
    {
        $address = collect([
            trim(implode(', ', array_filter([
                $this->text($xpath, 'n:emit/n:enderEmit/n:xLgr', $inf),
                $this->text($xpath, 'n:emit/n:enderEmit/n:nro', $inf),
                $this->text($xpath, 'n:emit/n:enderEmit/n:xCpl', $inf),
            ]))),
            $this->text($xpath, 'n:emit/n:enderEmit/n:xBairro', $inf),
            trim(implode('/', array_filter([
                $this->text($xpath, 'n:emit/n:enderEmit/n:xMun', $inf),
                $this->text($xpath, 'n:emit/n:enderEmit/n:UF', $inf),
            ]))),
            $this->text($xpath, 'n:emit/n:enderEmit/n:CEP', $inf),
        ])->filter(fn (?string $part): bool => filled($part))->implode(' - ');

        return [
            'document' => $this->digits($this->text($xpath, 'n:emit/n:CNPJ', $inf) ?? $this->text($xpath, 'n:emit/n:CPF', $inf)),
            'name' => (string) $this->text($xpath, 'n:emit/n:xNome', $inf),
            'trade_name' => $this->text($xpath, 'n:emit/n:xFant', $inf),
            'address' => $address !== '' ? $address : null,
            'phone' => $this->digits($this->text($xpath, 'n:emit/n:enderEmit/n:fone', $inf)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function items(DOMXPath $xpath, DOMElement $inf): array
    {
        $items = [];

        foreach ($xpath->query('n:det', $inf) as $position => $det) {
            if (! $det instanceof DOMElement) {
                continue;
            }

            $index = (int) ($det->getAttribute('nItem') ?: $position + 1);

            $code = (string) $this->text($xpath, 'n:prod/n:cProd', $det);
            $name = (string) $this->text($xpath, 'n:prod/n:xProd', $det);

            if ($code === '' || $name === '') {
                throw new NfeParseException("O item {$index} da NF-e não tem código ou descrição.");
            }

            $quantity = $this->decimal($this->text($xpath, 'n:prod/n:qCom', $det), 4);

            if (bccomp($quantity, '0', 4) <= 0) {
                throw new NfeParseException("O item {$index} da NF-e ({$name}) tem quantidade inválida.");
            }

            $items[] = [
                'index' => $index,
                'code' => $code,
                'ean' => $this->gtin($this->text($xpath, 'n:prod/n:cEAN', $det)),
                'ean_tax' => $this->gtin($this->text($xpath, 'n:prod/n:cEANTrib', $det)),
                'name' => $name,
                'ncm' => $this->text($xpath, 'n:prod/n:NCM', $det),
                'unit' => (string) ($this->text($xpath, 'n:prod/n:uCom', $det) ?? 'UN'),
                'quantity' => $quantity,
                'unit_price' => $this->decimal($this->text($xpath, 'n:prod/n:vUnCom', $det), 10),
                'total' => $this->decimal($this->text($xpath, 'n:prod/n:vProd', $det), 6),
                'discount' => $this->decimal($this->text($xpath, 'n:prod/n:vDesc', $det), 6),
                'freight' => $this->decimal($this->text($xpath, 'n:prod/n:vFrete', $det), 6),
                'insurance' => $this->decimal($this->text($xpath, 'n:prod/n:vSeg', $det), 6),
                'other' => $this->decimal($this->text($xpath, 'n:prod/n:vOutro', $det), 6),
                'ipi' => $this->decimal($this->text($xpath, 'n:imposto/n:IPI/n:IPITrib/n:vIPI', $det), 6),
                'st' => $this->decimal($this->text($xpath, 'n:imposto/n:ICMS/*/n:vICMSST', $det), 6),
                'unit_tax' => $this->text($xpath, 'n:prod/n:uTrib', $det),
                'quantity_tax' => $this->text($xpath, 'n:prod/n:qTrib', $det) !== null
                    ? $this->decimal($this->text($xpath, 'n:prod/n:qTrib', $det), 4)
                    : null,
                'cost_total' => '0',
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    protected function totals(DOMXPath $xpath, DOMElement $inf, array $items): array
    {
        $sum = fn (string $field): string => array_reduce(
            $items,
            fn (string $carry, array $item): string => bcadd($carry, (string) $item[$field], 6),
            '0',
        );

        $totals = [
            'products' => $this->decimal($this->text($xpath, 'n:total/n:ICMSTot/n:vProd', $inf), 2),
            'discount' => $this->decimal($this->text($xpath, 'n:total/n:ICMSTot/n:vDesc', $inf), 2),
            'freight' => $this->decimal($this->text($xpath, 'n:total/n:ICMSTot/n:vFrete', $inf), 2),
            'insurance' => $this->decimal($this->text($xpath, 'n:total/n:ICMSTot/n:vSeg', $inf), 2),
            'other' => $this->decimal($this->text($xpath, 'n:total/n:ICMSTot/n:vOutro', $inf), 2),
            'ipi' => $this->decimal($this->text($xpath, 'n:total/n:ICMSTot/n:vIPI', $inf), 2),
            'st' => $this->decimal($this->text($xpath, 'n:total/n:ICMSTot/n:vST', $inf), 2),
            'invoice' => $this->decimal($this->text($xpath, 'n:total/n:ICMSTot/n:vNF', $inf), 2),
        ];

        if (bccomp($totals['products'], '0', 2) === 0) {
            $totals['products'] = bcadd($sum('total'), '0', 2);
        }

        if (bccomp($totals['invoice'], '0', 2) === 0) {
            $totals['invoice'] = bcadd(
                bcsub(bcadd($totals['products'], bcadd(bcadd($totals['freight'], $totals['insurance'], 2), bcadd($totals['other'], bcadd($totals['ipi'], $totals['st'], 2), 2), 2), 2), $totals['discount'], 2),
                '0',
                2,
            );
        }

        return $totals;
    }

    /**
     * Custo de aquisição por item: produto − desconto + frete/seguro/outras
     * (do item e o resíduo do cabeçalho rateado) + IPI + ICMS-ST.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, string>  $totals
     * @return list<array<string, mixed>>
     */
    protected function applyCosts(array $items, array $totals): array
    {
        $net = array_map(
            fn (array $item): string => bcsub((string) $item['total'], (string) $item['discount'], 6),
            $items,
        );
        $netSum = array_reduce($net, fn (string $carry, string $value): string => bcadd($carry, $value, 6), '0');

        // Rateio do que veio só no cabeçalho (ex.: frete total da nota).
        $residuals = [];

        foreach (['freight', 'insurance', 'other', 'discount'] as $field) {
            $itemSum = array_reduce($items, fn (string $carry, array $item): string => bcadd($carry, (string) $item[$field], 6), '0');
            $residual = bcsub($totals[$field], $itemSum, 6);

            $residuals[$field] = $this->allocate($residual, $net, $netSum);
        }

        foreach ($items as $position => $item) {
            $cost = $net[$position];

            foreach (['freight', 'insurance', 'other'] as $field) {
                $cost = bcadd($cost, bcadd((string) $item[$field], $residuals[$field][$position], 6), 6);
            }

            $cost = bcsub($cost, $residuals['discount'][$position], 6);
            $cost = bcadd($cost, bcadd((string) $item['ipi'], (string) $item['st'], 6), 6);

            $items[$position]['cost_total'] = bccomp($cost, '0', 6) < 0 ? '0.000000' : $cost;
        }

        return $items;
    }

    /**
     * @param  list<string>  $weights
     * @return list<string>
     */
    protected function allocate(string $amount, array $weights, string $weightSum): array
    {
        $shares = array_fill(0, count($weights), '0.000000');

        if (bccomp($amount, '0', 6) <= 0 || bccomp($weightSum, '0', 6) <= 0) {
            return $shares;
        }

        $allocated = '0';
        $last = count($weights) - 1;

        foreach ($weights as $position => $weight) {
            $share = $position === $last
                ? bcsub($amount, $allocated, 6)
                : bcdiv(bcmul($amount, $weight, 12), $weightSum, 6);

            $shares[$position] = $share;
            $allocated = bcadd($allocated, $share, 6);
        }

        return $shares;
    }

    /**
     * @return list<array{number: string, due_date: string, amount: string}>
     */
    protected function installments(DOMXPath $xpath, DOMElement $inf, CarbonImmutable $issuedAt): array
    {
        $installments = [];

        foreach ($xpath->query('n:cobr/n:dup', $inf) as $position => $dup) {
            if (! $dup instanceof DOMElement) {
                continue;
            }

            $amount = $this->decimal($this->text($xpath, 'n:vDup', $dup), 2);

            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }

            $due = $this->text($xpath, 'n:dVenc', $dup);

            try {
                $dueDate = $due !== null ? CarbonImmutable::parse($due)->toDateString() : $issuedAt->toDateString();
            } catch (Throwable) {
                $dueDate = $issuedAt->toDateString();
            }

            $installments[] = [
                'number' => $this->text($xpath, 'n:nDup', $dup) ?? str_pad((string) ($position + 1), 3, '0', STR_PAD_LEFT),
                'due_date' => $dueDate,
                'amount' => $amount,
            ];
        }

        return $installments;
    }

    protected function text(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?string
    {
        $node = $context !== null
            ? $xpath->query($expression, $context)->item(0)
            : $xpath->query($expression)->item(0);

        if ($node === null) {
            return null;
        }

        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    protected function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' || $digits === null ? null : $digits;
    }

    /** GTIN válido (8/12/13/14 dígitos); "SEM GTIN" e zeros viram null. */
    protected function gtin(?string $value): ?string
    {
        $digits = $this->digits($value);

        if ($digits === null || ! in_array(strlen($digits), [8, 12, 13, 14], true) || trim($digits, '0') === '') {
            return null;
        }

        return $digits;
    }

    protected function decimal(?string $value, int $scale): string
    {
        if ($value === null || ! is_numeric($value)) {
            return bcadd('0', '0', $scale);
        }

        return bcadd(trim($value), '0', $scale);
    }
}
