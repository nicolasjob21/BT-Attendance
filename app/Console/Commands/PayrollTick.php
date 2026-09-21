<?php

namespace App\Console\Commands;

use App\Services\Payroll\PayrollRunner;
use Illuminate\Console\Command;

class PayrollTick extends Command
{
    protected $signature = 'payroll:tick';

    protected $description = 'Keep the current/next payroll periods ready and, on pay day, remind everyone who runs payroll.';

    public function handle(PayrollRunner $runner): int
    {
        $r = $runner->tick();
        $this->info("Periods created: {$r['periods_created']} · Reminded: ".($r['reminded'] ? implode(', ', $r['reminded']) : 'none'));

        return self::SUCCESS;
    }
}
