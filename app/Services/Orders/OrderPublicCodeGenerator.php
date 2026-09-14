<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\Order;
use Illuminate\Support\Str;

class OrderPublicCodeGenerator
{
    private const SAFE_CHARSET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function generate(Company $company): string
    {
        $prefix = $this->resolvePrefix($company);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $suffixLength = max(4, 12 - strlen($prefix) - 1);
            $code = $prefix.'-'.$this->randomSuffix($suffixLength);

            if (strlen($code) < 8 || strlen($code) > 16) {
                continue;
            }

            $exists = Order::query()
                ->where('company_id', $company->getKey())
                ->where('public_code', $code)
                ->exists();

            if (! $exists) {
                return $code;
            }
        }

        return $prefix.'-'.strtoupper(Str::random(6));
    }

    protected function resolvePrefix(Company $company): string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $company->name) ?? '';
        $prefix = strtoupper(substr($letters, 0, 3));

        if (strlen($prefix) < 3) {
            $prefix = str_pad($prefix, 3, 'X');
        }

        return $prefix;
    }

    protected function randomSuffix(int $length): string
    {
        $charset = self::SAFE_CHARSET;
        $maxIndex = strlen($charset) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $charset[random_int(0, $maxIndex)];
        }

        return $result;
    }
}
