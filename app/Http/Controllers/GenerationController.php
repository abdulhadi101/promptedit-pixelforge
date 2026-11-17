<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\CostCalculator;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Generations/Index', [
            'generations' => fn () => $request->user()->generations()->latest()->limit(50)->get()->map(fn (Generation $g) => [
                'id' => $g->id,
                'prompt' => $g->prompt,
                'size' => $g->size,
                'quality' => $g->quality,
                'status' => $g->status,
                'estimated_charge_nano' => $g->estimated_charge_nano,
                'charge_nano' => $g->charge_nano,
                'image_url' => $g->image_url_expires_at?->isFuture() ? $g->image_url : null,
                'error' => $g->error_message,
                'created_at' => $g->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function estimate(Request $request, CostCalculator $calc): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json($calc->estimate($data['prompt'], $data['size'], $data['quality'], count($data['reference_images'] ?? [])));
    }

    public function store(Request $request, CostCalculator $calc): RedirectResponse
    {
        $data = $this->validated($request) + $request->validate(['request_id' => ['required', 'uuid']]);
        $refs = array_values($data['reference_images'] ?? []);
        $estimate = $calc->estimate($data['prompt'], $data['size'], $data['quality'], count($refs));

        $result = DB::transaction(function () use ($request, $data, $refs, $estimate): array {
            // Serialize this user's reservation checks and inserts. Postgres locks
            // this row; SQLite serializes writers via an IMMEDIATE transaction.
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);

            // Idempotent retries return the original row before affordability is checked.
            $existing = $user->generations()
                ->where('request_id', $data['request_id'])
                ->first();

            if ($existing) {
                return ['generation' => $existing, 'created' => false, 'available' => null];
            }

            // Reserve worst-case estimates for unresolved generations.
            $available = $user->balance_nano
                - (int) $user->generations()
                    ->whereNotIn('status', ['succeeded', 'failed'])
                    ->sum('max_charge_nano');

            if ($estimate['max'] > $available) {
                return ['generation' => null, 'created' => false, 'available' => max($available, 0)];
            }

            $generation = $user->generations()->create([
                'request_id' => $data['request_id'],
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $refs,
                'status' => 'queued',
                'estimated_charge_nano' => $estimate['expected'],
                'max_charge_nano' => $estimate['max'],
            ]);

            return ['generation' => $generation, 'created' => true, 'available' => null];
        });

        if ($result['generation'] === null) {
            return back()->withErrors(['balance' => sprintf(
                'Not enough credits: this could cost up to $%s, and you have $%s available. Buy credits to continue.',
                Money::toDecimal($estimate['max']),
                Money::toDecimal($result['available']),
            )]);
        }

        if ($result['created']) {
            SubmitGeneration::dispatch($result['generation']->id)->afterCommit();
        }

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'in:1024x1024,1536x1024,1024x1536'],
            'quality' => ['required', 'in:low,medium,high'],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['url:http,https', 'max:2000'],
        ]);
    }
}
