<?php

namespace Database\Seeders;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Models\CheckpointReview;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\Site;
use App\Models\User;
use App\Notifications\CheckpointActivated;
use App\Services\Checkpoint\CheckpointAudit;
use App\Services\Checkpoint\CheckpointPhoto;
use App\Support\Geo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

/**
 * Sample Check Point data for demos:
 *   • an ACTIVE checkpoint running right now (mixed responses),
 *   • an EXPIRED checkpoint from earlier today with follow-up cases,
 *   • a scheduled DRAFT,
 *   • a COMPLETED checkpoint from yesterday.
 *
 * Idempotent: `php artisan db:seed --class=CheckpointDemoSeeder` re-creates the demo set.
 */
class CheckpointDemoSeeder extends Seeder
{
    private const DEMO_PEOPLE = [
        // [first, last, email]
        ['Ramon', 'Dela Cruz', 'ramon.delacruz@brite-tsi.com'],
        ['Liza', 'Santos', 'liza.santos@brite-tsi.com'],
        ['Marco', 'Villanueva', 'marco.villanueva@brite-tsi.com'],
        ['Joy', 'Reyes', 'joy.reyes@brite-tsi.com'],
        ['Paolo', 'Garcia', 'paolo.garcia@brite-tsi.com'],
        ['Andrea', 'Lim', 'andrea.lim@brite-tsi.com'],
    ];

    private CheckpointAudit $audit;

    public function run(): void
    {
        $this->audit = app(CheckpointAudit::class);
        $hr = User::where('email', 'hr@brite-tsi.com')->firstOrFail();
        $site = Site::where('type', 'project_site')->orderBy('id')->firstOrFail();
        $office = Site::where('type', 'office')->firstOrFail();

        // Start clean so the seeder can be re-run.
        CheckpointCampaign::where('name', 'like', '[Demo]%')->get()->each(fn ($c) => $c->delete());
        Storage::disk(CheckpointPhoto::DISK)->deleteDirectory('checkpoints/demo');

        $people = $this->demoEmployees($site);
        [$a, $b, $c, $d, $e, $f] = $people;
        $tech = Employee::where('email', 'tech@brite-tsi.com')->first();
        $everyone = $tech ? array_merge($people, [$tech]) : $people;

        $this->completedYesterday($hr, $site, $everyone);
        $this->expiredThisMorning($hr, $site, $office, $everyone, $a, $b, $c, $d, $e, $f);
        $this->activeNow($hr, $site, $everyone, $a, $b, $c, $d, $e, $f);
        $this->scheduledDraft($hr, $site, $everyone);

        $this->command?->info('Demo checkpoints created: 1 completed (yesterday), 1 expired (this morning), 1 active (now), 1 scheduled draft.');
        $this->command?->info('Demo employee logins: '.implode(', ', array_column(self::DEMO_PEOPLE, 2)).' — password "password".');
    }

    // ── scenarios ───────────────────────────────────────────────────

    private function completedYesterday(User $hr, Site $site, array $people): void
    {
        $start = Carbon::yesterday()->setTime(10, 15);
        $campaign = $this->campaign($hr, $site, '[Demo] Site A — Morning presence check', 'Capture the project signboard.', $start, 10, CheckpointCampaign::COMPLETED, 'Routine check after the toolbox meeting.');
        $campaign->employees()->sync(collect($people)->pluck('id'));
        $campaign->update(['closed_by' => $hr->id, 'closed_at' => $start->copy()->addHours(2)]);

        foreach ($people as $i => $emp) {
            $cp = $this->row($campaign, $emp, $start);
            $this->complete($cp, $site, $start->copy()->addSeconds(40 + $i * 55), $i === 3 ? 180 : 15);
        }
        $this->audit->campaign($campaign, 'completed', $hr);
    }

    private function expiredThisMorning(User $hr, Site $site, Site $office, array $people, Employee $a, Employee $b, Employee $c, Employee $d, Employee $e, Employee $f): void
    {
        $start = Carbon::today()->setTime(9, 40);
        if ($start->gt(Carbon::now())) {
            $start = Carbon::now()->subHours(2)->startOfMinute();
        }
        $campaign = $this->campaign($hr, $site, '[Demo] Site A — After-break presence check', 'Capture the current work area.', $start, 10, CheckpointCampaign::EXPIRED, 'Reports that some staff leave the site after the morning break.');
        $campaign->employees()->sync(collect($people)->pluck('id'));

        $rows = collect($people)->mapWithKeys(fn ($emp) => [$emp->id => $this->row($campaign, $emp, $start)]);

        // A, B: completed.
        $this->complete($rows[$a->id], $site, $start->copy()->addSeconds(75), 10);
        $this->complete($rows[$b->id], $site, $start->copy()->addMinutes(4)->addSeconds(12), 22);

        // C: outside the geofence, twice — explanation recorded, escalated, still open.
        $this->failedAttempt($rows[$c->id], $site, $start->copy()->addMinutes(3), Checkpoint::OUTSIDE_GEOFENCE, 'outside_geofence', 14.6690, 121.0480, 18, 2);
        $rows[$c->id]->update(['employee_explanation' => 'Went to the hardware store for the foreman; back by 10:30.']);
        $this->review($rows[$c->id], $hr, 'explanation_recorded', 'work_related', 'Went to the hardware store for the foreman; back by 10:30.', null);
        $this->review($rows[$c->id], $hr, 'escalated', null, null, 'No purchase request on file — for the PM to confirm.');
        $rows[$c->id]->update(['hr_reason' => 'work_related', 'hr_note' => 'No purchase request on file — for the PM to confirm.', 'escalated_at' => $start->copy()->addMinutes(45)]);

        // D: missed → HR approved (no internet).
        $this->missed($rows[$d->id], $start);
        $rows[$d->id]->update(['employee_explanation' => 'No mobile signal at the basement level until 10:15.', 'issue_reported' => 'no_internet']);
        $this->review($rows[$d->id], $hr, 'explanation_recorded', 'no_internet', 'No mobile signal at the basement level until 10:15.', null);
        $this->review($rows[$d->id], $hr, 'approved', 'no_internet', null, 'Confirmed by the site engineer.');
        $rows[$d->id]->update([
            'status' => Checkpoint::APPROVED_EXCEPTION, 'verification_result' => Checkpoint::COMPLETED_AFTER_REVIEW,
            'hr_reason' => 'no_internet', 'hr_note' => 'Confirmed by the site engineer.',
            'reviewed_by' => $hr->id, 'reviewed_at' => $start->copy()->addMinutes(50),
        ]);

        // E: missed → rejected (ignored).
        $this->missed($rows[$e->id], $start);
        $this->review($rows[$e->id], $hr, 'note_added', null, null, 'Seen on site by the supervisor at 9:45; did not respond.');
        $this->review($rows[$e->id], $hr, 'rejected', 'ignored', null, 'Second ignored checkpoint this month.');
        $rows[$e->id]->update([
            'status' => Checkpoint::REJECTED_EXCEPTION, 'hr_reason' => 'ignored',
            'hr_note' => 'Second ignored checkpoint this month.', 'reviewed_by' => $hr->id, 'reviewed_at' => $start->copy()->addMinutes(55),
        ]);

        // F: submitted from the head office → pending HR review, employee explained.
        $this->failedAttempt($rows[$f->id], $site, $start->copy()->addMinutes(6), Checkpoint::PENDING_REVIEW, 'alternate_location', (float) $office->latitude, (float) $office->longitude, 9, 1, $office->id);
        $rows[$f->id]->update(['employee_explanation' => 'Called to the head office for the permit renewal this morning.']);

        // Anyone else (e.g. the seeded tech): missed, untouched.
        foreach ($rows as $empId => $row) {
            if (! in_array($empId, [$a->id, $b->id, $c->id, $d->id, $e->id, $f->id], true)) {
                $this->missed($row, $start);
            }
        }

        $this->audit->campaign($campaign, 'expired', null, ['missed' => 2, 'non_compliant' => 4]);
    }

    private function activeNow(User $hr, Site $site, array $people, Employee $a, Employee $b, Employee $c, Employee $d, Employee $e, Employee $f): void
    {
        $start = Carbon::now()->subMinutes(3)->startOfSecond();
        $campaign = $this->campaign($hr, $site, '[Demo] Site A — Afternoon presence verification', 'Capture the project entrance.', $start, 10, CheckpointCampaign::ACTIVE, 'Spot check requested by the project manager.');
        $campaign->employees()->sync(collect($people)->pluck('id'));

        foreach ($people as $emp) {
            $cp = $this->row($campaign, $emp, $start);
            $emp->user?->notify(new CheckpointActivated($cp));
            match ($emp->id) {
                $a->id => $this->complete($cp, $site, $start->copy()->addSeconds(48), 8),
                $b->id => $this->complete($cp, $site, $start->copy()->addMinutes(2)->addSeconds(5), 160),
                $c->id => $this->failedAttempt($cp, $site, $start->copy()->addMinutes(1)->addSeconds(30), Checkpoint::OUTSIDE_GEOFENCE, 'outside_geofence', 14.6705, 121.0470, 14, 1),
                $d->id => $cp->update(['seen_at' => $start->copy()->addSeconds(20)]),
                $e->id => $cp->update(['status' => Checkpoint::CAMERA_PERMISSION_DENIED, 'failure_reason' => 'photo_missing', 'issue_reported' => 'camera_denied', 'seen_at' => $start->copy()->addSeconds(35)]),
                default => null,
            };
        }
    }

    private function scheduledDraft(User $hr, Site $site, array $people): void
    {
        $campaign = CheckpointCampaign::create([
            'name' => '[Demo] Site A — End-of-day presence check',
            'project_site_id' => $site->id,
            'instruction' => 'Capture the equipment or materials area.',
            'reason' => 'Verify everyone is still on site before Time Out.',
            'response_window_minutes' => 15,
            'scheduled_start_at' => Carbon::today()->setTime(16, 45)->gt(Carbon::now()) ? Carbon::today()->setTime(16, 45) : Carbon::tomorrow()->setTime(16, 45),
            'status' => CheckpointCampaign::DRAFT,
            'created_by' => $hr->id,
        ]);
        $campaign->employees()->sync(collect($people)->pluck('id'));
        $this->audit->campaign($campaign, 'created', $hr, ['participants' => count($people)]);
        $this->audit->campaign($campaign, 'scheduled', $hr, ['scheduled_start_at' => $campaign->scheduled_start_at->toDateTimeString()]);
    }

    // ── builders ────────────────────────────────────────────────────

    /** @return list<Employee> */
    private function demoEmployees(Site $site): array
    {
        $schedule = Schedule::where('is_flexible', true)->first() ?? Schedule::first();
        $n = (int) (Employee::max('id') ?? 0) + 100;
        $out = [];
        foreach (self::DEMO_PEOPLE as [$first, $last, $email]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => "$first $last", 'password' => Hash::make('password'), 'email_verified_at' => now(),
            ]);
            if (! $user->hasRole('employee')) {
                $user->assignRole('employee');
            }
            $employee = Employee::firstOrCreate(['email' => $email], [
                'user_id' => $user->id, 'employee_no' => 'EMP-'.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT),
                'first_name' => $first, 'last_name' => $last, 'employee_type' => 'technical', 'schedule_id' => $schedule?->id,
                'monthly_salary' => 22000, 'daily_rate' => 1000, 'date_hired' => '2025-03-03', 'status' => 'active',
            ]);
            if (! $employee->projectAssignments()->activeOn()->exists()) {
                $employee->projectAssignments()->create(['site_id' => $site->id, 'start_date' => Carbon::today()->subMonth()->toDateString(), 'status' => 'active']);
            }
            $out[] = $employee;
        }

        return $out;
    }

    private function campaign(User $hr, Site $site, string $name, string $instruction, Carbon $start, int $window, string $status, string $reason): CheckpointCampaign
    {
        $c = CheckpointCampaign::create([
            'name' => $name, 'project_site_id' => $site->id, 'instruction' => $instruction, 'reason' => $reason,
            'response_window_minutes' => $window, 'starts_at' => $start, 'expires_at' => $start->copy()->addMinutes($window),
            'status' => $status, 'created_by' => $hr->id, 'activated_by' => $hr->id, 'activated_at' => $start,
            'created_at' => $start->copy()->subMinutes(12), 'updated_at' => $start,
        ]);
        $this->audit->campaign($c, 'created', $hr);
        $this->audit->campaign($c, 'activated', $hr, ['starts_at' => $start->toDateTimeString(), 'expires_at' => $c->expires_at->toDateTimeString()]);

        return $c;
    }

    private function row(CheckpointCampaign $campaign, Employee $emp, Carbon $start): Checkpoint
    {
        return Checkpoint::create([
            'campaign_id' => $campaign->id, 'employee_id' => $emp->id, 'project_site_id' => $campaign->project_site_id,
            'status' => Checkpoint::NOTIFIED, 'notified_at' => $start,
        ]);
    }

    private function complete(Checkpoint $cp, Site $site, Carbon $at, float $accuracy): void
    {
        // Jitter the fix a little so distances differ but stay inside the fence.
        $lat = (float) $site->latitude + (mt_rand(-40, 40) / 100000);
        $lng = (float) $site->longitude + (mt_rand(-40, 40) / 100000);
        $dist = round(Geo::distanceMeters($lat, $lng, (float) $site->latitude, (float) $site->longitude), 2);
        $low = $accuracy > config('attendance.min_gps_accuracy_m', 100);

        $cp->update([
            'status' => Checkpoint::RESPONDED,
            'verification_result' => $low ? Checkpoint::COMPLETED_LOW_ACCURACY : Checkpoint::COMPLETED,
            'validation_message' => $low
                ? "Completed inside the {$site->name} geofence (".number_format($dist).' m from centre), but GPS accuracy was ±'.number_format($accuracy).' m.'
                : "Verified presence — inside the {$site->name} geofence (".number_format($dist).' m from centre).',
            'seen_at' => $at->copy()->subSeconds(25), 'submission_attempts' => 1, 'last_attempt_at' => $at,
            'last_attempt_result' => $low ? 'low_gps_accuracy' : 'verified_presence',
            'submitted_at' => $at, 'server_timestamp' => $at, 'client_timestamp' => $at->copy()->addSeconds(2),
            'latitude' => $lat, 'longitude' => $lng, 'gps_accuracy_meters' => $accuracy, 'distance_from_site_meters' => $dist,
            'matched_site_id' => $site->id, 'within_geofence' => true, 'network_status' => 'online',
            'photo_path' => $this->photo($cp, $low ? 'Low accuracy ±'.number_format($accuracy).' m' : 'Verified · '.number_format($dist).' m', $at),
        ]);
        $this->audit->checkpoint($cp, 'submitted', $cp->employee->user, ['result' => $low ? 'low_gps_accuracy' : 'verified_presence', 'distance_m' => $dist, 'attempt' => 1]);
    }

    private function failedAttempt(Checkpoint $cp, Site $site, Carbon $at, string $status, string $reason, float $lat, float $lng, float $accuracy, int $attempts, ?int $matchedSiteId = null): void
    {
        $dist = round(Geo::distanceMeters($lat, $lng, (float) $site->latitude, (float) $site->longitude), 2);
        $message = $reason === 'alternate_location'
            ? 'At Brite TSI — Head Office (an authorized location), '.number_format($dist)." m from {$site->name}. Needs HR review."
            : "Outside the {$site->name} geofence — about ".number_format($dist).' m from the site centre (radius '.(int) $site->geofence_radius_m.' m).';

        $cp->update([
            'status' => $status, 'failure_reason' => $reason, 'validation_message' => $message,
            'seen_at' => $at->copy()->subSeconds(40), 'submission_attempts' => $attempts, 'last_attempt_at' => $at, 'last_attempt_result' => $reason,
            'server_timestamp' => $at, 'client_timestamp' => $at->copy()->addSeconds(1),
            'latitude' => $lat, 'longitude' => $lng, 'gps_accuracy_meters' => $accuracy, 'distance_from_site_meters' => $dist,
            'matched_site_id' => $matchedSiteId, 'within_geofence' => false, 'network_status' => 'online',
            'photo_path' => $this->photo($cp, ($matchedSiteId ? 'Alt. site' : 'Outside').' · '.number_format($dist).' m', $at),
        ]);
        $this->audit->checkpoint($cp, 'attempt_failed', $cp->employee->user, ['result' => $reason, 'distance_m' => $dist, 'attempt' => $attempts]);
    }

    private function missed(Checkpoint $cp, Carbon $start): void
    {
        $cp->update([
            'status' => Checkpoint::MISSED, 'failure_reason' => 'no_response',
            'validation_message' => 'No submission was received before the checkpoint deadline at '.$start->copy()->addMinutes(10)->format('g:i A').'.',
            'server_timestamp' => $start->copy()->addMinutes(10),
        ]);
        $this->audit->checkpoint($cp, 'deadline_passed', null, ['status' => Checkpoint::MISSED]);
    }

    private function review(Checkpoint $cp, User $hr, string $action, ?string $reason, ?string $explanation, ?string $note): void
    {
        CheckpointReview::create(array_filter([
            'checkpoint_id' => $cp->id, 'reviewer_id' => $hr->id, 'action' => $action,
            'reason' => $reason, 'explanation' => $explanation, 'note' => $note,
        ], fn ($v) => $v !== null));
        $this->audit->checkpoint($cp, $action, $hr, array_filter(['reason' => $reason, 'note' => $note]));
    }

    /** Generate a placeholder "site photo" with the same caption strip real submissions get. */
    private function photo(Checkpoint $cp, string $gpsLabel, Carbon $at): string
    {
        $cp->loadMissing(['employee', 'site', 'campaign']);
        $w = 720;
        $h = 960;
        $img = (new ImageManager(new Driver))->createImage($w, $h)->fill('#6b7f8a');
        // Sky / ground blocks + a "signboard" so it reads as a site photo, not a blank.
        $img->drawRectangle(function ($r) use ($w) {
            $r->at(0, 0);
            $r->size($w, 380);
            $r->background('#9fc3d6');
        });
        $img->drawRectangle(function ($r) use ($w) {
            $r->at(0, 380);
            $r->size($w, 580);
            $r->background('#8a7a63');
        });
        $img->drawRectangle(function ($r) {
            $r->at(120, 250);
            $r->size(480, 200);
            $r->background('#f4f1ea');
            $r->border('#2b3a42', 6);
        });
        $font = resource_path('fonts/Lato-Regular.ttf');
        $img->text($cp->site?->name ?? 'Project site', $w / 2, 335, function (FontFactory $f) use ($font) {
            $f->filename($font);
            $f->size(30);
            $f->color('#2b3a42');
            $f->align('center', 'center');
        });
        $img->text('DEMO PHOTO', $w / 2, 395, function (FontFactory $f) use ($font) {
            $f->filename($font);
            $f->size(20);
            $f->color('#2b3a42');
            $f->align('center', 'center');
        });
        $lines = [
            ($cp->employee?->full_name ?? 'Employee').' · '.($cp->site?->name ?? 'Site'),
            $at->format('D, M j, Y g:i:s A').' · '.$cp->reference(),
            'GPS: '.$gpsLabel.' · '.($cp->campaign?->instruction ?? ''),
        ];
        $lineH = 26;
        $stripH = $lineH * count($lines) + $lineH;
        $img->drawRectangle(function ($r) use ($w, $h, $stripH) {
            $r->at(0, $h - $stripH);
            $r->size($w, $stripH);
            $r->background('rgba(0,0,0,0.6)');
        });
        $y = $h - $stripH + 14;
        foreach ($lines as $line) {
            $img->text($line, 14, $y, function (FontFactory $f) use ($font) {
                $f->filename($font);
                $f->size(17);
                $f->color('#ffffff');
                $f->align('left', 'top');
            });
            $y += $lineH;
        }
        $path = "checkpoints/demo/{$cp->campaign_id}/{$cp->employee_id}_{$cp->id}.jpg";
        Storage::disk(CheckpointPhoto::DISK)->put($path, (string) $img->encode(new JpegEncoder(80)));

        return $path;
    }
}
