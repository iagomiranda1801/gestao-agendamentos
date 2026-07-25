<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNormalizerTest extends TestCase
{
    #[DataProvider('phoneProvider')]
    public function test_it_normalizes_phone_numbers(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::normalize($input));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function phoneProvider(): array
    {
        return [
            'parentheses' => ['(34) 99999-9999', '34999999999'],
            'hyphen' => ['3499999-9999', '34999999999'],
            'spaces' => ['34 99999 9999', '34999999999'],
            'plus55' => ['+55 (34) 99999-9999', '5534999999999'],
            'empty string' => ['', null],
            'null' => [null, null],
        ];
    }
}
