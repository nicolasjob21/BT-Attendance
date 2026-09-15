<?php

namespace App\Console\Commands;

use App\Services\Checkpoint\CheckpointDispatcher;
use Illuminate\Console\Command;

class CheckpointTick extends Command
{
    protected $signature = 'checkpoints:tick';

    protected $description = 'Start due campaigns, generate/open random checkpoints, and expire lapsed ones';

    public function handle(CheckpointDispatcher $dispatcher): int
    {
        $r = $dispatcher->tick();
        $this->info(sprintf(
            'started %d · generated %d · opened %d · missed %d · completed %d',
            $r['started'], $r['generated'], $r['opened'], $r['missed'], $r['completed'],
        ));

        return self::SUCCESS;
    }
}
