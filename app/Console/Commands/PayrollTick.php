<?php

namespace App\Console\Commands;

use App\Services\Payroll\PayrollAutomation;
use Illuminate\Console\Command;

class PayrollTick extends Command
{
    protected $signature = 'payroll:tick';

    protected $description = 'Create the current/next payroll periods and, when automation is on, generate payroll for periods that have ended.';

    public function handle(PayrollAutomation $automation): int
    {
        $r = $automation->tick();
        $this->info("Periods created: {$r['periods_created']} · Generated: ".($r['generated'] ? implode(', ', $r['generated']) : 'none'));

        return self::SUCCESS;
    }
}
