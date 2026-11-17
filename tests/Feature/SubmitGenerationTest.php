<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const RATES = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];

    /** SubmitGeneration that records release() calls instead of talking to a real worker. */
    private function releasingJob(Generation $g): SubmitGeneration
    {
        return new class($g->id) extends SubmitGeneration
        {
            public array $releasedDelays = [];

            public function release($delay = 0): void
            {
                $this->releasedDelays[] = $delay;
            }
        };
    }

    private function queuedGeneration(): Generation
    {
        return Generation::create([
            'user_id' => User::factory()->create()->id,
            'request_id' => fake()->uuid(),
            'prompt' => 'A lighthouse at dawn',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'status' => 'queued',
            'estimated_charge_nano' => 1,
            'max_charge_nano' => 1,
        ]);
    }

    private function runJob(SubmitGeneration $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_creates_the_remote_generation_adopting_its_id(): void
    {
        $g = $this->queuedGeneration();
        Http::fake(['*/v1/generations' => Http::response([
            'id' => 'gen_ABC123', 'object' => 'generation', 'status' => 'queued',
            'metadata' => ['generation_id' => $g->id], 'rates' => self::RATES,
        ], 202)]);

        $this->runJob(new SubmitGeneration($g->id));

        $g->refresh();
        $this->assertSame('gen_ABC123', $g->pixelforge_id);
        $this->assertSame('queued', $g->status);
        $this->assertNull($g->charge_nano); // nothing settled yet

        Http::assertSent(function ($request) {
            return $request->hasHeader('Idempotency-Key')
                && $request['metadata']['generation_id'] !== null;
        });
    }

    public function test_retryable_error_releases_with_retry_after_plus_jitter(): void
    {
        $g = $this->queuedGeneration();
        Http::fake(['*/v1/generations' => Http::response([
            'error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many.'],
        ], 429, ['Retry-After' => '5'])]);
        $job = $this->releasingJob($g);

        $this->runJob($job);

        $this->assertCount(1, $job->releasedDelays); // kept trying: released, not failed
        $delay = $job->releasedDelays[0];
        $this->assertGreaterThanOrEqual(5, $delay);  // honours Retry-After
        $this->assertLessThanOrEqual(8, $delay);     // + jitter(0..3)

        $g->refresh();
        $this->assertSame('queued', $g->status);
        $this->assertNull($g->pixelforge_id);
        $this->assertNull($g->error_code);
    }

    public function test_connection_error_releases_and_keeps_the_row_unchanged(): void
    {
        $g = $this->queuedGeneration();
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $job = $this->releasingJob($g);

        $this->runJob($job);

        $this->assertCount(1, $job->releasedDelays);
        $g->refresh();
        $this->assertSame('queued', $g->status);
    }

    public function test_content_policy_violation_fails_permanently_and_free(): void
    {
        $g = $this->queuedGeneration();
        Http::fake(['*/v1/generations' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'The prompt was rejected.'],
        ], 400)]);

        $this->runJob(new SubmitGeneration($g->id));

        $g->refresh();
        $this->assertSame('failed', $g->status);
        $this->assertSame('content_policy_violation', $g->error_code);
        $this->assertSame(0, $g->provider_cost_nano);
        $this->assertSame(0, $g->charge_nano);
        $this->assertNull($g->pixelforge_id); // nothing was created remotely
    }

    public function test_daily_limit_exceeded_is_not_retried(): void
    {
        $g = $this->queuedGeneration();
        Http::fake(['*/v1/generations' => Http::response([
            'error' => ['type' => 'rate_limit_error', 'code' => 'daily_limit_exceeded', 'message' => 'Quota used up.'],
        ], 429)]);
        $job = $this->releasingJob($g);

        $this->runJob($job);

        $this->assertCount(0, $job->releasedDelays); // retrying cannot help
        $g->refresh();
        $this->assertSame('failed', $g->status);
        $this->assertSame('daily_limit_exceeded', $g->error_code);
        $this->assertSame(0, $g->charge_nano);
    }

    public function test_gave_up_job_never_resurrects_a_final_generation(): void
    {
        // Simulate the job's failed() hook firing after the row already settled
        // (e.g. a webhook won the race): it must not overwrite the final state.
        $g = $this->queuedGeneration();
        $g->forceFill(['status' => 'succeeded', 'provider_cost_nano' => 12_500_000, 'charge_nano' => 15_625_000])->save();

        (new SubmitGeneration($g->id))->failed(new \Exception('Max attempts exceeded'));

        $g->refresh();
        $this->assertSame('succeeded', $g->status);
        $this->assertSame(15_625_000, $g->charge_nano);
    }
}
