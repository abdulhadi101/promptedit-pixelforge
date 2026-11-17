<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Stretch goal (b): balances, fake purchases, and blocking unaffordable generations. */
class BalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/v1/pricing*' => Http::response(self::pricing()),
            // The create call too, because tests run on the sync queue and the
            // submit job would otherwise really POST to Pixelforge mid-test.
            // The id mirrors what deliverSucceeded() uses, so settlement matches.
            '*/v1/generations' => fn ($request) => Http::response([
                'id' => 'gen_'.md5($request['metadata']['generation_id']),
                'object' => 'generation', 'status' => 'queued', 'metadata' => [],
            ], 202),
        ]);
        $this->actingAs(User::factory()->create());
    }

    private static function pricing(): array
    {
        return [
            'object' => 'pricing', 'currency' => 'usd', 'unit' => 'per_1m_tokens',
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'tokens' => [
                'characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    private function submit(string $quality = 'medium', string $prompt = 'A lighthouse at dawn'): void
    {
        $this->post('/generations', [
            'request_id' => fake()->uuid(),
            'prompt' => $prompt,
            'size' => '1024x1024',
            'quality' => $quality,
            'reference_images' => [],
        ]);
    }

    public function test_buy_credits_adds_ten_dollars(): void
    {
        $this->post('/credits');
        $this->post('/credits');

        $this->assertSame(20_000_000_000, auth()->user()->fresh()->balance_nano);
    }

    public function test_generation_is_blocked_when_the_user_cannot_afford_the_worst_case(): void
    {
        // High quality worst case for this prompt: well over $1. Balance is $0.
        $this->submit('high');

        $this->assertDatabaseCount('generations', 0); // no half-created rows
        $this->assertSame(0, auth()->user()->fresh()->balance_nano);
    }

    public function test_generation_is_allowed_when_the_balance_covers_the_worst_case(): void
    {
        $this->post('/credits'); // $10

        $this->submit('high');

        $this->assertDatabaseCount('generations', 1);
        $this->assertSame(10_000_000_000, auth()->user()->fresh()->balance_nano); // nothing deducted yet
    }

    public function test_in_flight_generations_reserve_their_worst_case(): void
    {
        $this->post('/credits'); // $10

        // Exactly enough worst-case budget for ONE high-quality generation.
        $max = $this->postJson('/generations/estimate', [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1024x1024',
            'quality' => 'high',
            'reference_images' => [],
        ])->json('max');
        auth()->user()->forceFill(['balance_nano' => $max])->save();

        $this->submit('high'); // allowed; now in flight
        $this->submit('high'); // blocked: nothing left while the first is unresolved

        $this->assertDatabaseCount('generations', 1);
    }

    public function test_settlement_deducts_the_actual_charge_from_the_balance(): void
    {
        $this->post('/credits'); // $10
        $this->submit('low');
        $g = Generation::sole();

        $this->deliverSucceeded($g, providerCostNano: 12_500_000); // charge = 15_625_000

        $user = auth()->user()->fresh();
        $this->assertSame(10_000_000_000 - 15_625_000, $user->balance_nano);
    }

    public function test_failed_settlement_deducts_nothing(): void
    {
        $this->post('/credits');
        $this->submit('low');
        $g = Generation::sole();

        $this->deliverFailed($g);

        $this->assertSame(10_000_000_000, auth()->user()->fresh()->balance_nano);
        $this->assertSame('failed', $g->fresh()->status);
    }

    public function test_duplicate_success_events_deduct_exactly_once(): void
    {
        $this->post('/credits');
        $this->submit('low');
        $g = Generation::sole();

        $this->deliverSucceeded($g, 12_500_000, 'evt_dup1');
        $this->deliverSucceeded($g, 12_500_000, 'evt_dup1'); // same event id, delivered twice
        $this->deliverSucceeded($g, 12_500_000, 'evt_dup2'); // different event, same final state

        $this->assertSame(10_000_000_000 - 15_625_000, auth()->user()->fresh()->balance_nano);
    }

    private function deliverSucceeded(Generation $g, int $providerCostNano, string $eventId = 'evt_ok'): void
    {
        // Usage whose cost equals providerCostNano with the rates above.
        $usage = match ($providerCostNano) {
            12_500_000 => ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 100],
            default => $this->usageForCost($providerCostNano),
        };

        $body = json_encode([
            'id' => $eventId, 'object' => 'event', 'type' => 'generation.succeeded', 'created_at' => now()->toIso8601ZuluString(),
            'data' => ['object' => [
                'id' => 'gen_'.md5($g->id), 'object' => 'generation', 'status' => 'succeeded',
                'metadata' => ['generation_id' => $g->id],
                'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
                'usage' => $usage, 'error' => null,
                'started_at' => now()->toIso8601ZuluString(), 'completed_at' => now()->toIso8601ZuluString(),
                'image_url' => 'https://example.com/a.png', 'image_url_expires_at' => now()->addDay()->toIso8601ZuluString(),
            ]],
        ]);
        $t = time();
        $sig = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", 'whsec_test');

        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $sig,
        ], $body)->assertNoContent();
    }

    private function usageForCost(int $providerCostNano): array
    {
        // text tokens at $5/1M: providerCostNano = n * 5000 => n = cost / 5000.
        $n = intdiv($providerCostNano, 5000);
        $this->assertSame(0, $providerCostNano % 5000, 'test only supports costs that are multiples of the text-token rate');

        return ['input_text_tokens' => $n, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0];
    }

    private function deliverFailed(Generation $g): void
    {
        $body = json_encode([
            'id' => 'evt_fail', 'object' => 'event', 'type' => 'generation.failed', 'created_at' => now()->toIso8601ZuluString(),
            'data' => ['object' => [
                'id' => 'gen_'.md5($g->id), 'object' => 'generation', 'status' => 'failed',
                'metadata' => ['generation_id' => $g->id],
                'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
                'usage' => null, 'error' => ['code' => 'generation_failed', 'message' => 'No image.'],
                'started_at' => now()->toIso8601ZuluString(), 'completed_at' => now()->toIso8601ZuluString(),
                'image_url' => null, 'image_url_expires_at' => null,
            ]],
        ]);
        $t = time();
        $sig = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", 'whsec_test');

        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $sig,
        ], $body)->assertNoContent();
    }
}
