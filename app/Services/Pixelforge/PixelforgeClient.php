<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.base_url'))
            ->withToken(config('services.pixelforge.api_key'))
            ->acceptJson()
            ->timeout(15);
    }

    /** POST /v1/generations. Always pass an idempotency key so retries can never double-create/bill. */
    public function create(array $params, string $idempotencyKey): array
    {
        return $this->handle(
            $this->http()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('/generations', $params)
        );
    }

    public function get(string $id): array
    {
        return $this->handle($this->http()->get("/generations/{$id}"));
    }

    /** Public, unauthenticated. Rates and token tables; read at runtime, never hardcode. */
    public function pricing(): array
    {
        return $this->handle(Http::baseUrl(config('services.pixelforge.base_url'))->acceptJson()->timeout(10)->get('/pricing'));
    }

    public function usage(string $startDate, string $endDate): array
    {
        return $this->handle($this->http()->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate]));
    }

    private function handle(Response $response): array
    {
        if ($response->successful()) {
            return $response->json();
        }

        $error = $response->json('error') ?? [];

        throw new PixelforgeException(
            status: $response->status(),
            errorCode: $error['code'] ?? 'unknown_error',
            message: $error['message'] ?? 'Pixelforge request failed.',
            retryAfter: $response->header('Retry-After') !== '' ? (int) $response->header('Retry-After') : null,
        );
    }
}
