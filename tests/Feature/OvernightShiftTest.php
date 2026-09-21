<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A session that crosses midnight (10:15 PM → 12:05 AM) belongs to the day it
 * started and is shown as "10:15 PM – 12:05 AM⁺¹". The next day only shows
 * the punches that started on it, so nothing is duplicated.
 */
class OvernightShiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-09-17 12:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_overnight_session_is_one_row_on_the_day_it_started_with_a_next_day_marker(): void
    {
        $hr = User::where('username', 'brite-hr')->firstOrFail();
        $tech = Employee::where('email', 'tech@brite-tsi.com')->firstOrFail();
        foreach ([['time_in', '2026-09-15 22:15'], ['time_out', '2026-09-16 00:05'], ['time_in', '2026-09-16 09:00'], ['time_out', '2026-09-16 17:30']] as [$type, $at]) {
            $tech->attendanceLogs()->create(['log_type' => $type, 'logged_at' => Carbon::parse($at)]);
        }

        // Sep 15: 10:15 PM in, 12:05 AM out marked as the next day, 1:50 worked.
        $sep15 = $this->actingAs($hr)->get(route('attendance.monitor', ['date' => '2026-09-15']))->assertOk();
        $sep15->assertSee('10:15 PM')->assertSee('12:05 AM')->assertSee('>+1</sup>', false)->assertSee('1h 50m');

        // Sep 16: only the day shift; the 12:05 AM time out is not shown again.
        $sep16 = $this->actingAs($hr)->get(route('attendance.monitor', ['date' => '2026-09-16']))->assertOk();
        $sep16->assertSee('9:00 AM')->assertSee('5:30 PM')->assertDontSee('12:05 AM')->assertDontSee('>+1</sup>', false);

        // The monthly timesheet follows the same rule.
        $sheet = $this->actingAs($hr)->get(route('attendance.timesheet', ['employee' => $tech, 'month' => '2026-09']))->assertOk();
        $sheet->assertSee('10:15 PM')->assertSee('12:05 AM')->assertSee('>+1</sup>', false);
    }
}
