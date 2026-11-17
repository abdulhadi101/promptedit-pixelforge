<?php

namespace App\Services;

use App\Models\Generation;
use App\Services\Pixelforge\CostCalculator;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY place a generation changes state or gets a price. Webhooks, polling and
 * submit-time failures all funnel through here, so a generation is settled at most once:
 *   - row is locked, so concurrent deliveries serialize
 *   - states only move forward (queued < processing < succeeded|failed); final states never change
 */
class GenerationStateApplier
{
    private const RANK = ['queued' => 0, 'processing' => 1, 'succeeded' => 2, 'failed' => 2];

    /** @param array $pf A Pixelforge generation object (from a webhook or from GET /generations/{id}). */
    public function apply(string $generationId, array $pf): void
    {
        DB::transaction(function () use ($generationId, $pf) {
            $g = Generation::query()->lockForUpdate()->findOrFail($generationId);

            if ($g->pixelforge_id === null) {
                $g->pixelforge_id = $pf['id'];
            } elseif ($g->pixelforge_id !== $pf['id']) {
                Log::warning('Pixelforge id mismatch', ['generation' => $g->id, 'got' => $pf['id']]);

                return;
            }

            $incoming = $pf['status'];

            if (! isset(self::RANK[$incoming])) {
                Log::warning('Pixelforge sent an unknown status', ['generation' => $g->id, 'status' => $incoming]);

                return;
            }

            if (self::RANK[$incoming] > self::RANK[$g->status]) {
                match ($incoming) {
                    'processing' => $this->markProcessing($g, $pf),
                    'succeeded' => $this->markSucceeded($g, $pf),
                    'failed' => $this->markFailed($g, $pf['error']['code'] ?? 'generation_failed', $pf['error']['message'] ?? 'Generation failed.', $pf),
                };
            }

            $g->save();
        });
    }

    /** Failure that happened before/at submission (blocked prompt, quota, retries exhausted). Free. */
    public function failLocally(string $generationId, string $code, string $message): void
    {
        DB::transaction(function () use ($generationId, $code, $message) {
            $g = Generation::query()->lockForUpdate()->findOrFail($generationId);

            if (! $g->isFinal() && $g->pixelforge_id === null) {
                $this->markFailed($g, $code, $message, []);
                $g->save();
            }
        });
    }

    private function markProcessing(Generation $g, array $pf): void
    {
        $g->status = 'processing';
        $g->started_at = $this->time($pf['started_at'] ?? null);
    }

    private function markSucceeded(Generation $g, array $pf): void
    {
        $cost = CostCalculator::providerCostNano($pf['usage'], $pf['rates']);

        $g->fill([
            'status' => 'succeeded',
            'usage' => $pf['usage'],
            'rates' => $pf['rates'],
            'provider_cost_nano' => $cost,
            'charge_nano' => Money::withMarkup($cost),
            'image_url' => $pf['image_url'] ?? null,
            'image_url_expires_at' => $this->time($pf['image_url_expires_at'] ?? null),
            'started_at' => $this->time($pf['started_at'] ?? null) ?? $g->started_at,
            'completed_at' => $this->time($pf['completed_at'] ?? null) ?? now(),
        ]);
    }

    private function markFailed(Generation $g, string $code, string $message, array $pf): void
    {
        $g->fill([
            'status' => 'failed',
            'provider_cost_nano' => 0,
            'charge_nano' => 0,
            'error_code' => $code,
            'error_message' => $message,
            'completed_at' => $this->time($pf['completed_at'] ?? null) ?? now(),
        ]);
    }

    private function time(?string $iso): ?Carbon
    {
        return $iso ? Carbon::parse($iso)->utc() : null;
    }
}
