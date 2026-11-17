<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Stretch goal (a): the admin report, with provider costs checked against /v1/usage. */
class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['email' => 'admin@example.com']));
        config(['services.pixelforge.admin_emails' => ['admin@example.com']]);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/report')
            ->assertForbidden();
    }

    public function test_report_shows_charge_provider_cost_and_profit_per_day_and_user(): void
    {
        $this->succeeded(null, 15_625_000, 12_500_000); // today, admin user
        $this->succeeded(User::factory()->create(['name' => 'Second']), 10_000_000, 8_000_000); // today

        $today = now('UTC')->toDateString();
        Http::fake(['*/v1/usage*' => Http::response([
            'object' => 'usage',
            'data' => [['date' => $today, 'cost_usd' => '0.020500000']], // ours: 20_500_000
        ])]);

        $page = $this->get('/admin/report');
        $page->assertOk();

        $perDay = $page->viewData('page')['props']['perDay'];
        $this->assertCount(1, $perDay);
        $row = $perDay[0];
        $this->assertSame($today, $row['day']);
        $this->assertSame(25_625_000, $row['charge_nano']);
        $this->assertSame(20_500_000, $row['provider_cost_nano']);
        $this->assertSame(20_500_000, $row['pixelforge_nano']); // matches /v1/usage exactly
        $this->assertSame(5_125_000, $row['profit_nano']);

        $perUser = $page->viewData('page')['props']['perUser'];
        $this->assertCount(2, $perUser);
        $this->assertSame(15_625_000, $perUser[0]['charge_nano']); // ordered by spend
        $this->assertSame(10_000_000, $perUser[1]['charge_nano']);
    }

    public function test_report_flags_a_usage_mismatch(): void
    {
        $this->succeeded(null, 15_625_000, 12_500_000);
        $today = now('UTC')->toDateString();

        Http::fake(['*/v1/usage*' => Http::response([
            'object' => 'usage',
            'data' => [['date' => $today, 'cost_usd' => '0.099000000']], // we recorded 0.012500000
        ])]);

        $row = $this->get('/admin/report')->viewData('page')['props']['perDay'][0];
        $this->assertSame(99_000_000, $row['pixelforge_nano']);
        $this->assertSame(12_500_000, $row['provider_cost_nano']);
        $this->assertNotSame($row['pixelforge_nano'], $row['provider_cost_nano']); // rendered as MISMATCH
    }

    public function test_report_includes_provider_billed_days_missing_from_local_generations(): void
    {
        $this->freezeTime();
        $today = now('UTC')->toDateString();
        Http::fake(['*/v1/usage*' => Http::response([
            'object' => 'usage',
            'data' => [['date' => $today, 'cost_usd' => '0.099000000']],
        ])]);

        $props = $this->get('/admin/report')->viewData('page')['props'];

        $this->assertCount(1, $props['perDay']);
        $this->assertSame(0, $props['perDay'][0]['provider_cost_nano']);
        $this->assertSame(99_000_000, $props['perDay'][0]['pixelforge_nano']);
        $this->assertSame([], $props['perUser']);
    }

    public function test_failed_generations_do_not_add_local_cost_but_provider_cost_remains_visible(): void
    {
        $g = $this->succeeded(null, 15_625_000, 12_500_000);
        $g->forceFill(['status' => 'failed', 'charge_nano' => 0, 'provider_cost_nano' => 0])->save();

        $today = now('UTC')->toDateString();
        Http::fake(['*/v1/usage*' => Http::response([
            'object' => 'usage',
            'data' => [['date' => $today, 'cost_usd' => '0.012500000']], // Pixelforge DID bill it
        ])]);

        $props = $this->get('/admin/report')->viewData('page')['props'];
        $this->assertSame(0, $props['perDay'][0]['charge_nano']);
        $this->assertSame(0, $props['perDay'][0]['provider_cost_nano']);
        $this->assertSame(12_500_000, $props['perDay'][0]['pixelforge_nano']);
        $this->assertSame([], $props['perUser']);
    }

    private function succeeded(?User $user, int $charge, int $provider): Generation
    {
        return Generation::create([
            'user_id' => ($user ?? auth()->user())->id,
            'request_id' => fake()->uuid(),
            'prompt' => 'x',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'status' => 'succeeded',
            'estimated_charge_nano' => $charge,
            'max_charge_nano' => $charge,
            'charge_nano' => $charge,
            'provider_cost_nano' => $provider,
            'completed_at' => now('UTC'),
        ]);
    }
}
