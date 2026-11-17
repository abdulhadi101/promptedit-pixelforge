<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\CostCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
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

        $generation = $request->user()->generations()->firstOrCreate(
            ['request_id' => $data['request_id']], // a double-click or retry returns the same row
            [
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $refs,
                'status' => 'queued',
                'estimated_charge_nano' => $estimate['expected'],
                'max_charge_nano' => $estimate['max'],
            ],
        );

        if ($generation->wasRecentlyCreated) {
            SubmitGeneration::dispatch($generation->id)->afterCommit();
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
