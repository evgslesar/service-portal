<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class Fixed
{
    public static function quantity(string $value): int
    {
        return self::parse($value, 3);
    }

    public static function money(string $value): int
    {
        return self::parse($value, 2);
    }

    private static function parse(string $value, int $scale): int
    {
        $value = str_replace(',', '.', trim($value));
        if (! preg_match('/^\d{1,9}(?:\.\d{1,'.$scale.'})?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Укажите неотрицательное число без лишних знаков после запятой.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) ($whole.str_pad($fraction, $scale, '0'));
    }

    public static function ratio(int $a, int $b, int $divisor, bool $round = true): int
    {
        $product = bcmul((string) $a, (string) $b, 0);
        if ($round) {
            $product = bcadd($product, (string) intdiv($divisor, 2), 0);
        }
        $result = bcdiv($product, (string) $divisor, 0);
        if (bccomp($result, (string) PHP_INT_MAX, 0) > 0 || bccomp($result, '0', 0) < 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма превышает допустимый размер операции.']);
        }

        return (int) $result;
    }

    public static function display(int $value, int $scale = 3): string
    {
        if ($scale === 0) {
            return (string) $value;
        }
        $digits = str_pad(ltrim((string) $value, '-'), $scale + 1, '0', STR_PAD_LEFT);

        return ($value < 0 ? '-' : '').substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }
}
