<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Notifications\PayrollDue;
use App\Services\PayrollCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Runs payroll — but a person always presses the button.
 *
 * Company salary schedule (fixed): 1st–15th is paid on the 15th and 16th–last
 * day is paid on the last day of the month, so each cutoff is computed,
 * reviewed and released on its own last day.
 *
 * Every morning the scheduler calls tick() (routes/console.php), which
 *  1. keeps the semi-monthly periods rolling (rollForward) so nobody has to
 *     create one by hand — the payroll page and dashboard do the same on
 *     load, so this also works without a cron;
 *  2. on pay day, reminds everyone who can run payroll that the cutoff is
 *     due — once per period.
 *
 * status() is what the payroll page and the dashboard show: whether payroll
 * is due today, overdue, computed and waiting for release, or simply upcoming.
 * generate() is the manual "Run payroll" for a period.
 */
class PayrollRunner
{
    public function __construct(private PayrollCalculator $calculator) {}

    /** @return array{periods_created:int, reminded:list<string>} */
    public function tick(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $created = $this->rollForward($now);

        $reminded = [];
        $due = PayrollPeriod::whereNull('generated_at')
            ->whereNull('reminded_at')
            ->whereNull('released_at')
            ->whereDate('period_end', '<=', $now->toDateString())
            ->orderBy('period_start')
            ->get();

        foreach ($due as $period) {
            Notification::send(User::permission('run payroll')->get(), new PayrollDue($period));
            $period->update(['reminded_at' => $now]);
            $reminded[] = $period->label();
        }

        return ['periods_created' => $created, 'reminded' => $reminded];
    }

    /**
     * Create every period from the last known one up to the current cutoff,
     * plus the next one — what the daily tick would have done had it run.
     * Idempotent; a fresh install starts at the current cutoff.
     */
    public function rollForward(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $before = PayrollPeriod::count();

        $latest = PayrollPeriod::latest('period_end')->first();
        $current = PayrollPeriod::ensureFor($now);
        for ($p = $latest; $p && $p->period_end->lt($current->period_start); $p = $p->next()) {
            // each next() creates the following cutoff if missing
        }
        $current->next();

        return PayrollPeriod::count() - $before;
    }

    /**
     * What the person running payroll should do right now.
     *
     * @return array{state:'overdue'|'due'|'computed'|'upcoming', period:?PayrollPeriod, pay_date:Carbon, days:int, title:string, message:string, action:?string}
     */
    public function status(?Carbon $now = null): array
    {
        $today = ($now ?? Carbon::now())->copy()->startOfDay();

        // A cutoff whose pay day has passed and that was never computed or released.
        $overdue = PayrollPeriod::whereNull('generated_at')->whereNull('released_at')
            ->whereDate('period_end', '<', $today->toDateString())
            ->orderBy('period_start')->first();
        if ($overdue) {
            $days = (int) $overdue->period_end->diffInDays($today);

            return [
                'state' => 'overdue', 'period' => $overdue, 'pay_date' => $overdue->period_end, 'days' => -$days,
                'title' => 'Payroll overdue',
                'message' => $overdue->label().' was due on '.$overdue->period_end->format('M j').' ('.$days.' day'.($days === 1 ? '' : 's').' ago) and has not been computed.',
                'action' => 'run',
            ];
        }

        // A computed cutoff that hasn't gone out yet.
        $waiting = PayrollPeriod::whereNotNull('generated_at')->whereNull('released_at')
            ->whereDate('period_end', '<=', $today->toDateString())
            ->orderBy('period_start')->first();
        if ($waiting) {
            return [
                'state' => 'computed', 'period' => $waiting, 'pay_date' => $waiting->period_end, 'days' => 0,
                'title' => 'Ready to release',
                'message' => $waiting->label().' is computed — review the lines, then release it so employees get their payslips.',
                'action' => 'release',
            ];
        }

        $cutoff = PayrollPeriod::cutoffFor($today);
        $period = PayrollPeriod::whereDate('period_start', $cutoff['start']->toDateString())
            ->whereDate('period_end', $cutoff['end']->toDateString())->first();
        $label = $cutoff['start']->format('M j').' – '.$cutoff['end']->format('M j, Y');

        if ($cutoff['end']->isSameDay($today) && ! $period?->released_at) {
            return [
                'state' => 'due', 'period' => $period, 'pay_date' => $cutoff['end'], 'days' => 0,
                'title' => 'Pay day is today',
                'message' => $label.' has not been computed yet. Run payroll, review the lines, then release.',
                'action' => 'run',
            ];
        }

        // Nothing to do yet: say when the next pay day is.
        $next = $period?->released_at ? PayrollPeriod::cutoffFor($cutoff['end']->copy()->addDay()) : $cutoff;
        $days = (int) $today->diffInDays($next['end']);

        return [
            'state' => 'upcoming', 'period' => $period, 'pay_date' => $next['end'], 'days' => $days,
            'title' => 'Next pay day: '.$next['end']->format('D, M j'),
            'message' => $days === 0 ? 'Today.' : ('In '.$days.' day'.($days === 1 ? '' : 's').' · '.$next['start']->format('M j').' – '.$next['end']->format('M j').' cutoff.'),
            'action' => null,
        ];
    }

    /**
     * Compute every active employee's line for the period. $by is the user id
     * who pressed the button. Returns the number of lines computed (adjusted
     * lines are skipped unless $force).
     */
    public function generate(PayrollPeriod $period, string $by, bool $force = false): int
    {
        $employees = Employee::where('status', 'active')->get();
        foreach ($employees as $employee) {
            $this->calculator->calculate($employee, $period, $force);
        }

        $period->update([
            'status' => 'processing',
            'generated_at' => now(),
            'generated_by' => $by,
        ]);

        return $employees->count();
    }
}
