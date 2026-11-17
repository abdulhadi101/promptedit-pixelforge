<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\GenerationStateApplier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController
{
    public function __construct(private GenerationStateApplier $applier) {}

    public function __invoke(Request $request): Response
    {
        // Verify against the RAW body, before any decoding.
        $payload = $request->getContent();
        $parts = [];
        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $parts[$k] = $v;
        }

        $timestamp = (int) ($parts['t'] ?? 0);
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", (string) config('services.pixelforge.webhook_secret'));

        if (! hash_equals($expected, $parts['v1'] ?? '') || abs(time() - $timestamp) > 300) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);

        if (($event['type'] ?? null) === 'ping') {
            return response()->noContent();
        }

        $pf = $event['data']['object'] ?? null;
        if (! is_array($pf) || ! isset($event['id'], $pf['id'], $pf['status'])) {
            return response('Malformed event', 400);
        }

        // The row exists before we call Pixelforge, so a webhook that beats our POST's response still matches.
        $generationId = $pf['metadata']['generation_id'] ?? Generation::where('pixelforge_id', $pf['id'])->value('id');

        if (! $generationId || ! Generation::whereKey($generationId)->exists()) {
            Log::warning('Pixelforge webhook for unknown generation', ['event' => $event['id'], 'pf' => $pf['id']]);

            return response()->noContent(); // 2xx so Pixelforge stops retrying something we can't use
        }

        // Event-id insert and state change commit together: a failure rolls both back so a retry is processed.
        DB::transaction(function () use ($event, $generationId, $pf) {
            $inserted = DB::table('webhook_events')->insertOrIgnore([
                'id' => $event['id'],
                'type' => $event['type'],
                'generation_id' => $generationId,
                'received_at' => now(),
            ]);

            if ($inserted === 1) { // 0 = duplicate delivery, already handled
                $this->applier->apply($generationId, $pf);
            }
        });

        return response()->noContent();
    }
}
