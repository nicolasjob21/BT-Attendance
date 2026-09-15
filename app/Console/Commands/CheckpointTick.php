<?php

namespace App\Console\Commands;

use App\Services\Checkpoint\CheckpointDispatcher;
use Illuminate\Console\Command;

class CheckpointTick extends Command
{
    protected $signature = 'checkpoints:tick';

    protected $description = 'Start scheduled checkpoints and expire those past their shared deadline';

    public function handle(CheckpointDispatcher $dispatcher): int
    {
        $r = $dispatcher->tick();
        $this->info(sprintf('started %d · expired %d · marked missed %d', $r['started'], $r['expired'], $r['missed']));

        return self::SUCCESS;
    }
}
