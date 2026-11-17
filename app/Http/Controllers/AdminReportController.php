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
        $usage = collect($client->usage($since->toDateString(), now('UTC')->toDateString())['data'] ?? [])
            ->pluck('cost_usd', 'date');

        $perDay = $days->map(fn ($row) => [
            'day' => $row->day,
            'charge_nano' => (int) $row->charge_nano,
            'provider_cost_nano' => (int) $row->provider_cost_nano,
            'profit_nano' => (int) $row->profit_nano,
            // Reported in nanodollars; a differing pair renders as a visible MISMATCH row.
            'pixelforge_nano' => isset($usage[$row->day])
                ? Money::parseDecimal($usage[$row->day], 9)
                : null,
        ]);

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
