<?php

namespace App\Services\Pixelforge;

use App\Support\Money;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for prices: the pre-submit estimate and the final settlement
 * both go through here.
 */
class CostCalculator
{
    private const TOKEN_KEYS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private PixelforgeClient $client) {}

    /** Final provider cost in nanodollars from real usage and the rates snapshot on the generation. */
    public static function providerCostNano(array $usage, array $rates): int
    {
        $total = 0;
        foreach (self::TOKEN_KEYS as $key) {
            $total += (int) $usage[$key] * Money::ratePerTokenNano($rates[$key]);
        }

        return $total;
    }

    /**
     * Pre-submit estimate. Text tokens are +-15% of ceil(chars/4) and reasoning tokens vary within a
     * range per quality; image and output tokens are exact. Returns charge min/expected/max in nanodollars.
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages): array
    {
        $p = Cache::remember('pixelforge.pricing', 300, fn () => $this->client->pricing());
        $t = $p['tokens'];

        $est = (int) ceil(mb_strlen($prompt) / $t['characters_per_text_token']);
        $pct = (int) round($t['text_token_variance'] * 100);
        $reasoning = $t['reasoning'][$quality];

        $tokens = fn (int $text, int $reasoningTokens) => [
            'input_text_tokens' => $text,
            'input_image_tokens' => $referenceImages * $t['per_reference_image'],
            'output_tokens' => $t['output'][$quality][$size],
            'reasoning_tokens' => $reasoningTokens,
        ];

        $cases = [
            'min' => $tokens(intdiv($est * (100 - $pct), 100), $reasoning['min']),
            'expected' => $tokens($est, intdiv($reasoning['min'] + $reasoning['max'], 2)),
            'max' => $tokens(intdiv($est * (100 + $pct) + 99, 100), $reasoning['max']),
        ];

        return array_map(
            fn (array $usage) => Money::withMarkup(self::providerCostNano($usage, $p['rates'])),
            $cases,
        );
    }
}
