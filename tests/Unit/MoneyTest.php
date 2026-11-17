<?php

namespace Tests\Unit;

use App\Services\Pixelforge\CostCalculator;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_rates_become_nanodollars_per_token(): void
    {
        $this->assertSame(5000, Money::ratePerTokenNano('5.00'));
        $this->assertSame(12500, Money::ratePerTokenNano('12.50'));
    }

    public function test_docs_worked_example_max_cost(): void
    {
        $cost = CostCalculator::providerCostNano(
            ['input_text_tokens' => 115, 'input_image_tokens' => 1530, 'output_tokens' => 6450, 'reasoning_tokens' => 8000],
            ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        );

        $this->assertSame(373_875_000, $cost);                       // $0.373875000, as in the docs
        $this->assertSame('0.373875000', Money::toDecimal($cost));
        $this->assertSame(467_343_750, Money::withMarkup($cost));    // +25%
    }

    public function test_markup_rounds_half_up(): void
    {
        $this->assertSame(13, Money::withMarkup(10)); // 12.5 -> 13
        $this->assertSame(0, Money::withMarkup(0));
    }

    public function test_decimal_parsing_is_exact(): void
    {
        $this->assertSame(46_125_000, Money::parseDecimal('0.046125000', 9));
        $this->assertSame('0.000000001', Money::toDecimal(1));
    }
}
