<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Console\Command;

/** Compare our recorded provider costs per UTC completion day with GET /v1/usage. */
class ReconcileUsage extends Command
{
    protected $signature = 'pixelforge:reconcile {--days=30}';

    protected $description = 'Compare local provider costs with Pixelforge usage';

    public function handle(PixelforgeClient $client): int
    {
        $end = now('UTC')->toDateString();
        $start = now('UTC')->subDays((int) $this->option('days') - 1)->toDateString();
        $usage = $client->usage($start, $end);

        $local = Generation::query()->where('status', 'succeeded')
            ->whereBetween('completed_at', ["{$start} 00:00:00", "{$end} 23:59:59"])
            ->get()
            ->groupBy(fn ($g) => $g->completed_at->utc()->toDateString())
            ->map(fn ($rows) => $rows->sum('provider_cost_nano'));

        $providerByDay = collect($usage['data'])
            ->mapWithKeys(fn (array $day) => [$day['date'] => Money::parseDecimal($day['cost_usd'], 9)]);
        $dates = $providerByDay->keys()->merge($local->keys())->unique()->sort()->values();

        $mismatch = false;
        $rows = [];
        foreach ($dates as $date) {
            $theirs = (int) ($providerByDay[$date] ?? 0);
            $ours = (int) ($local[$date] ?? 0);
            $mismatch |= $theirs !== $ours;
            $rows[] = [$date, Money::toDecimal($theirs), Money::toDecimal($ours), $theirs === $ours ? 'ok' : 'MISMATCH'];
        }

        $this->table(['date (UTC)', 'pixelforge', 'ours', ''], $rows);

        return $mismatch ? self::FAILURE : self::SUCCESS;
    }
}
