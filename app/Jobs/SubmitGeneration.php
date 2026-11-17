<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationStateApplier;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 0; // bounded by retryUntil() instead, because release() for rate limits also counts as an attempt

    public function __construct(public string $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(PixelforgeClient $client, GenerationStateApplier $applier): void
    {
        $g = Generation::find($this->generationId);
        if (! $g || $g->pixelforge_id || $g->isFinal()) {
            return;
        }

        try {
            // Idempotency-Key = our generation id: any retry returns the same generation, never a second bill.
            $pf = $client->create([
                'prompt' => $g->prompt,
                'size' => $g->size,
                'quality' => $g->quality,
                'reference_images' => $g->reference_images,
                'metadata' => ['generation_id' => $g->id],
            ], $g->id);
        } catch (PixelforgeException $e) {
            if ($e->isRetryable()) {
                $this->release(max($e->retryAfter ?? 2, 2) + random_int(0, 3)); // honour Retry-After, add jitter
            } else {
                // blocked prompt, daily quota, validation... nothing was created or billed.
                $applier->failLocally($g->id, $e->errorCode, $e->getMessage());
            }

            return;
        } catch (ConnectionException) {
            $this->release(5 + random_int(0, 5));

            return;
        }

        $applier->apply($g->id, $pf);
    }

    public function failed(Throwable $e): void
    {
        app(GenerationStateApplier::class)->failLocally(
            $this->generationId, 'submit_failed', 'We could not reach the image provider. You were not charged.'
        );
    }
}
