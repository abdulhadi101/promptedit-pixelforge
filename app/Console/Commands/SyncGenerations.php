<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\GenerationStateApplier;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;

/** Safety net for undelivered webhooks: poll anything still not final. Same applier => no double charge. */
class SyncGenerations extends Command
{
    protected $signature = 'pixelforge:sync';

    protected $description = 'Poll Pixelforge for generations whose webhooks have not settled them';

    public function handle(PixelforgeClient $client, GenerationStateApplier $applier): int
    {
        Generation::query()
            ->whereNotIn('status', ['succeeded', 'failed'])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subSeconds(45))
            ->each(function (Generation $g) use ($client, $applier) {
                $applier->apply($g->id, $client->get($g->pixelforge_id));
            });

        return self::SUCCESS;
    }
}
