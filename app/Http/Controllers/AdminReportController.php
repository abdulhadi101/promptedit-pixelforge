<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stretch goal (a): credit spend, provider cost and profit per UTC day and per user.
 * The provider-cost column is grouped by UTC completion day, the same way GET /v1/usage
 * bills, and the page pulls /v1/usage live so a mismatch is impossible to miss.
 */
class AdminReportController extends Controller
{
    public function index(Request $request, PixelforgeClient $client): Response
    {
        $this->authorizeAdmin($request);

        $since = now('UTC')->subDays(29)->startOfDay();

        // Per user: everything is summed over the same window.
        $perUser = Generation::query()
            ->selectRaw('users.name as name, users.email as email')
            ->selectRaw('SUM(charge_nano) as charge_nano')
            ->selectRaw('SUM(provider_cost_nano) as provider_cost_nano')
            ->selectRaw('SUM(charge_nano) - SUM(provider_cost_nano) as profit_nano')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', 'succeeded')
            ->where('generations.completed_at', '>=', $since)
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderBy('charge_nano', 'desc')
            ->get();

        $days = Generation::query()
            ->selectRaw('DATE(completed_at) as day')
            ->selectRaw('SUM(charge_nano) as charge_nano')
            ->selectRaw('SUM(provider_cost_nano) as provider_cost_nano')
            ->selectRaw('SUM(charge_nano) - SUM(provider_cost_nano) as profit_nano')
            ->where('status', 'succeeded')
            ->where('completed_at', '>=', $since)
            ->groupBy('day')
            ->orderBy('day', 'desc')
            ->get();

        // What Pixelforge says it charged us, per UTC day, live from the source of truth.
        $providerByDay = collect($client->usage($since->toDateString(), now('UTC')->toDateString())['data'] ?? [])
            ->mapWithKeys(fn (array $day) => [$day['date'] => Money::parseDecimal($day['cost_usd'], 9)]);
        $localByDay = $days->keyBy('day');
        $dates = $localByDay->keys()->merge($providerByDay->keys())->unique()->sortDesc()->values();

        $perDay = $dates->map(function (string $day) use ($localByDay, $providerByDay): array {
            $row = $localByDay->get($day);

            return [
                'day' => $day,
                'charge_nano' => (int) ($row->charge_nano ?? 0),
                'provider_cost_nano' => (int) ($row->provider_cost_nano ?? 0),
                'profit_nano' => (int) ($row->profit_nano ?? 0),
                // Union of local/provider dates catches missing records in either direction.
                'pixelforge_nano' => (int) ($providerByDay[$day] ?? 0),
            ];
        });

        return Inertia::render('Admin/Report', [
            'perDay' => $perDay,
            'perUser' => $perUser->map(fn ($row) => [
                'name' => $row->name,
                'email' => $row->email,
                'charge_nano' => (int) $row->charge_nano,
                'provider_cost_nano' => (int) $row->provider_cost_nano,
                'profit_nano' => (int) $row->profit_nano,
            ]),
            'daysWindow' => 30,
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            in_array($request->user()->email, config('services.pixelforge.admin_emails', []), true),
            403,
            'Admins only.',
        );
    }
}
