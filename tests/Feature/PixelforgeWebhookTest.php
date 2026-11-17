<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const RATES = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.webhook_secret' => 'whsec_test']);
    }

    private function generation(): Generation
    {
        return Generation::create([
            'user_id' => User::factory()->create()->id, 'request_id' => fake()->uuid(), 'prompt' => 'x',
            'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [], 'status' => 'queued',
            'estimated_charge_nano' => 1, 'max_charge_nano' => 1,
        ]);
    }

    private function deliver(string $eventId, string $type, string $status, Generation $g, array $extra = [])
    {
        $body = json_encode([
            'id' => $eventId, 'object' => 'event', 'type' => $type, 'created_at' => now()->toIso8601ZuluString(),
            'data' => ['object' => array_merge([
                'id' => 'gen_ABC', 'object' => 'generation', 'status' => $status, 'metadata' => ['generation_id' => $g->id],
                'rates' => self::RATES, 'usage' => null, 'error' => null,
                'started_at' => now()->toIso8601ZuluString(), 'completed_at' => null, 'image_url' => null, 'image_url_expires_at' => null,
            ], $extra)],
        ]);
        $t = time();
        $sig = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", 'whsec_test');

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $sig,
        ], $body);
    }

    private function succeeded(): array
    {
        return ['usage' => ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 100, 'total_tokens' => 390],
            'completed_at' => now()->toIso8601ZuluString(), 'image_url' => 'https://example.com/a.png'];
    }

    public function test_rejects_a_timestamp_older_than_five_minutes(): void
    {
        $body = '{}';
        $old = time() - 301;
        $sig = 't='.$old.',v1='.hash_hmac('sha256', "{$old}.{$body}", 'whsec_test');

        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $sig,
        ], $body)->assertStatus(400);
    }

    public function test_ping_event_is_accepted(): void
    {
        $body = json_encode(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => []]);
        $t = time();
        $sig = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", 'whsec_test');

        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $sig,
        ], $body)->assertNoContent();
    }

    public function test_rejects_bad_signature(): void
    {
        $this->call('POST', '/webhooks/pixelforge', [], [], [], ['HTTP_PIXELFORGE_SIGNATURE' => 't=1,v1=bad'], '{}')->assertStatus(400);
    }

    public function test_duplicate_success_is_recorded_once(): void
    {
        $g = $this->generation();
        $this->deliver('evt_1', 'generation.succeeded', 'succeeded', $g, $this->succeeded())->assertNoContent();
        $this->deliver('evt_1', 'generation.succeeded', 'succeeded', $g, $this->succeeded())->assertNoContent();

        // 10*5000 + 280*40000 + 100*12500 = 12_500_000 nano provider; x1.25 = 15_625_000
        $g->refresh();
        $this->assertSame('succeeded', $g->status);
        $this->assertSame(12_500_000, $g->provider_cost_nano);
        $this->assertSame(15_625_000, $g->charge_nano);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_late_processing_event_cannot_regress_a_final_generation(): void
    {
        $g = $this->generation();
        $this->deliver('evt_2', 'generation.succeeded', 'succeeded', $g, $this->succeeded());
        $this->deliver('evt_1', 'generation.processing', 'processing', $g);

        $this->assertSame('succeeded', $g->fresh()->status);
    }

    public function test_failed_generation_is_free(): void
    {
        $g = $this->generation();
        $this->deliver('evt_3', 'generation.failed', 'failed', $g, ['error' => ['code' => 'internal_error', 'message' => 'oops']]);

        $g->refresh();
        $this->assertSame('failed', $g->status);
        $this->assertSame(0, $g->charge_nano);
    }
}
