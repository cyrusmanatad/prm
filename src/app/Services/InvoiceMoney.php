<?php

namespace App\Services;

use InvalidArgumentException;

/** Exact arithmetic for integer quantities and signed-64-bit minor-unit storage. */
class InvoiceMoney
{
    public static function calculate(array $lines): array
    {
        $total = 0;
        $calculated = [];
        foreach ($lines as $line) {
            $price = $line['unit_price_minor'];
            $quantity = $line['quantity'];
            if (! is_string($price) || ! preg_match('/^(0|[1-9][0-9]{0,18})$/D', $price)
                || strlen($price) === 19 && strcmp($price, '9223372036854775807') > 0
                || ! is_int($quantity) || $quantity < 1 || $quantity > 1_000_000) {
                throw new InvalidArgumentException('Use integer quantities and canonical minor-unit prices within the supported range.');
            }
            $unit = (int) $price;
            if ($unit > intdiv(PHP_INT_MAX, $quantity)) {
                throw new InvalidArgumentException('A line total exceeds the supported money range.');
            }
            $amount = $unit * $quantity;
            if ($total > PHP_INT_MAX - $amount) {
                throw new InvalidArgumentException('The invoice total exceeds the supported money range.');
            }
            $total += $amount;
            $calculated[] = [...$line, 'total_minor' => (string) $amount];
        }

        return ['lines' => $calculated, 'total_minor' => (string) $total];
    }
}
