<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Our per-UTC-completion-day provider costs must equal GET /v1/usage exactly. */
class ReconcileUsageTest extends TestCase
{
    use RefreshDatabase;

    private function succeededGeneration(int $providerCostNano): Generation
    {
        return Generation::create([
            'user_id' => User::factory()->create()->id,
            'request_id' => fake()->uuid(),
            'prompt' => 'x',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'status' => 'succeeded',
            'estimated_charge_nano' => 1,
            'max_charge_nano' => 1,
            'provider_cost_nano' => $providerCostNano,
            'charge_nano' => Money::withMarkup($providerCostNano),
            'completed_at' => now('UTC'),
        ]);
    }

    public function test_matching_costs_pass(): void
    {
        $this->succeededGeneration(12_500_000);
        $this->succeededGeneration(3_750_000);
        $today = now('UTC')->toDateString();
        $total = 16_250_000;

        Http::fake(['*/v1/usage*' => Http::response([
            'object' => 'usage',
            'start_date' => $today,
            'end_date' => $today,
            'data' => [['date' => $today, 'generations' => 2, 'cost_usd' => '0.016250000']],
            'total' => ['generations' => 2, 'cost_usd' => '0.016250000'],
        ])]);

        $this->artisan('pixelforge:reconcile')
            ->expectsTable(['date (UTC)', 'pixelforge', 'ours', ''], [[$today, '0.016250000', '0.016250000', 'ok']])
            ->assertExitCode(0);
    }

    public function test_a_mismatch_fails_the_command(): void
    {
        $this->succeededGeneration(12_500_000);
        $today = now('UTC')->toDateString();

        // We recorded 0.012500000, Pixelforge billed us 0.013000000: money is missing.
        Http::fake(['*/v1/usage*' => Http::response([
            'object' => 'usage',
            'data' => [['date' => $today, 'generations' => 1, 'cost_usd' => '0.013000000']],
        ])]);

        $this->artisan('pixelforge:reconcile')->assertExitCode(1);
    }

    public function test_failed_generations_never_appear_in_usage(): void
    {
        // A failed local row must not contribute to any day's total, or reconcile
        // would wrongly expect a cost for it.
        $g = $this->succeededGeneration(0);
        $g->forceFill(['status' => 'failed', 'completed_at' => now('UTC')])->save();
        $today = now('UTC')->toDateString();

        Http::fake(['*/v1/usage*' => Http::response([
            'object' => 'usage',
            'data' => [['date' => $today, 'generations' => 0, 'cost_usd' => '0.000000000']],
        ])]);

        $this->artisan('pixelforge:reconcile')->assertExitCode(0);
    }
}
