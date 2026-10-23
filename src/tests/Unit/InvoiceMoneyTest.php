<?php

namespace Tests\Unit;

use App\Services\InvoiceMoney;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class InvoiceMoneyTest extends TestCase
{
    public function test_exact_max_zero_and_safe_multiplication(): void
    {
        $this->assertSame('9223372036854775807', InvoiceMoney::calculate([['quantity' => 1, 'unit_price_minor' => '9223372036854775807'], ['quantity' => 1000000, 'unit_price_minor' => '0']])['total_minor']);
        $this->assertSame('90071992547409930', InvoiceMoney::calculate([['quantity' => 10, 'unit_price_minor' => '9007199254740993']])['total_minor']);
    }

    public function test_sum_overflow_is_rejected_before_addition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceMoney::calculate([['quantity' => 1, 'unit_price_minor' => '9223372036854775807'], ['quantity' => 1, 'unit_price_minor' => '1']]);
    }

    public function test_product_overflow_is_rejected_before_multiplication(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InvoiceMoney::calculate([['quantity' => 2, 'unit_price_minor' => '9223372036854775807']]);
    }
}
