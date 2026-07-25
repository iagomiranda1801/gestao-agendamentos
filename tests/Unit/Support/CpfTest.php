<?php

namespace Tests\Unit\Support;

use App\Support\Cpf;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CpfTest extends TestCase
{
    public function test_normalize_keeps_digits_only(): void
    {
        $this->assertSame('52998224725', Cpf::normalize('529.982.247-25'));
        $this->assertNull(Cpf::normalize(''));
        $this->assertNull(Cpf::normalize(null));
    }

    public function test_format_applies_mask(): void
    {
        $this->assertSame('529.982.247-25', Cpf::format('52998224725'));
    }

    #[DataProvider('validCpfs')]
    public function test_accepts_valid_cpfs(string $cpf): void
    {
        $this->assertTrue(Cpf::isValid($cpf));
    }

    #[DataProvider('invalidCpfs')]
    public function test_rejects_invalid_cpfs(string $cpf): void
    {
        $this->assertFalse(Cpf::isValid($cpf));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validCpfs(): array
    {
        return [
            'masked' => ['529.982.247-25'],
            'digits' => ['52998224725'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidCpfs(): array
    {
        return [
            'repeated' => ['11111111111'],
            'wrong_check' => ['52998224726'],
            'short' => ['123'],
            'empty' => [''],
        ];
    }
}
