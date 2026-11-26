<?php

namespace Tests\Feature\Nfe;

use App\Services\Nfe\NfeParseException;
use App\Services\Nfe\NfeXmlParser;
use Tests\TestCase;

class NfeXmlParserTest extends TestCase
{
    protected function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/fixtures/nfe/{$name}.xml"));
    }

    public function test_parses_nfe_proc_with_items_totals_and_installments(): void
    {
        $invoice = app(NfeXmlParser::class)->parse($this->fixture('nfe_proc_cerveja'));

        $this->assertSame('35261111222333000181550010000012341000012345', $invoice['access_key']);
        $this->assertSame('1234', $invoice['number']);
        $this->assertSame('1', $invoice['series']);
        $this->assertSame('2026-11-20', substr($invoice['issued_at'], 0, 10));
        $this->assertSame('11222333000181', $invoice['issuer']['document']);
        $this->assertSame('DISTRIBUIDORA DE BEBIDAS SOL LTDA', $invoice['issuer']['name']);
        $this->assertSame('Bebidas Sol', $invoice['issuer']['trade_name']);
        $this->assertStringContainsString('Rua das Palmeiras', $invoice['issuer']['address']);
        $this->assertSame('45723174000110', $invoice['recipient']['document']);
        $this->assertCount(2, $invoice['items']);
        $this->assertSame([], $invoice['warnings']);

        $first = $invoice['items'][0];
        $this->assertSame('CERV001', $first['code']);
        $this->assertSame('17891234567892', $first['ean']);
        $this->assertSame('7891234567895', $first['ean_tax']);
        $this->assertSame('CX', $first['unit']);
        $this->assertSame('10.0000', $first['quantity']);
        $this->assertSame('120.0000', $first['quantity_tax']);

        $this->assertSame('420.00', $invoice['totals']['invoice']);
        $this->assertSame('5.00', $invoice['totals']['discount']);

        $this->assertCount(2, $invoice['installments']);
        $this->assertSame('2026-12-20', $invoice['installments'][0]['due_date']);
        $this->assertSame('210.00', $invoice['installments'][0]['amount']);
    }

    public function test_sem_gtin_becomes_null(): void
    {
        $invoice = app(NfeXmlParser::class)->parse($this->fixture('nfe_proc_cerveja'));

        $this->assertNull($invoice['items'][1]['ean']);
        $this->assertNull($invoice['items'][1]['ean_tax']);
    }

    public function test_item_costs_add_up_to_invoice_total(): void
    {
        $invoice = app(NfeXmlParser::class)->parse($this->fixture('nfe_proc_cerveja'));

        $sum = array_reduce($invoice['items'], fn (string $carry, array $item): string => bcadd($carry, $item['cost_total'], 6), '0');

        $this->assertSame('380.000000', $invoice['items'][0]['cost_total']);
        $this->assertSame('40.000000', $invoice['items'][1]['cost_total']);
        $this->assertSame(0, bccomp($sum, $invoice['totals']['invoice'], 2));
    }

    public function test_header_level_freight_is_allocated_across_items(): void
    {
        $xml = str_replace('<vFrete>20.00</vFrete>'."\n".'          <indTot>1</indTot>', '<indTot>1</indTot>', $this->fixture('nfe_proc_cerveja'));
        $this->assertStringNotContainsString('<vFrete>20.00</vFrete>'."\n".'          <indTot>', $xml);

        $invoice = app(NfeXmlParser::class)->parse($xml);

        $sum = array_reduce($invoice['items'], fn (string $carry, array $item): string => bcadd($carry, $item['cost_total'], 6), '0');

        $this->assertSame(0, bccomp($sum, '420.00', 2));
        $this->assertGreaterThan(0, bccomp($invoice['items'][1]['cost_total'], '40', 2));
    }

    public function test_parses_bare_nfe_without_protocol_and_warns(): void
    {
        $invoice = app(NfeXmlParser::class)->parse($this->fixture('nfe_simples'));

        $this->assertSame('35261199888777000155550010000000551000000551', $invoice['access_key']);
        $this->assertSame('2026-11-22', substr($invoice['issued_at'], 0, 10));
        $this->assertSame('12345678909', $invoice['recipient']['document']);
        $this->assertSame([], $invoice['installments']);
        $this->assertNotEmpty($invoice['warnings']);
    }

    public function test_rejects_doctype_and_external_entities(): void
    {
        $this->expectException(NfeParseException::class);

        app(NfeXmlParser::class)->parse($this->fixture('nfe_doctype'));
    }

    public function test_rejects_garbage_and_wrong_documents(): void
    {
        $parser = app(NfeXmlParser::class);

        foreach (['', 'isto não é xml', '<foo><bar/></foo>', '<?xml version="1.0"?><procEventoNFe xmlns="http://www.portalfiscal.inf.br/nfe"><evento/></procEventoNFe>'] as $xml) {
            try {
                $parser->parse($xml);
                $this->fail('Deveria recusar: '.$xml);
            } catch (NfeParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_rejected_protocol(): void
    {
        $xml = str_replace('<cStat>100</cStat>', '<cStat>110</cStat>', $this->fixture('nfe_proc_cerveja'));

        $this->expectException(NfeParseException::class);

        app(NfeXmlParser::class)->parse($xml);
    }
}
