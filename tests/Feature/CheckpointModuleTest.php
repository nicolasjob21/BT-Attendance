<?php

namespace Tests\Feature;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Notifications\CheckpointExceptionFlagged;
use App\Notifications\CheckpointOpened;
use App\Notifications\CheckpointReviewed;
use App\Services\Checkpoint\CheckpointDispatcher;
use App\Services\Checkpoint\CheckpointScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Check Point module: campaign lifecycle, server-side random scheduling,
 * opening/expiry via the dispatcher, employee submission validation,
 * exception review, permissions and evidence retention.
 */
class CheckpointModuleTest extends TestCase
{
    use RefreshDatabase;

    private const PHOTO = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

    private User $hr;

    private User $techUser;

    private Employee $tech;

    private Site $siteA;

    private Site $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
        Storage::fake('local');

        // Tuesday 9:00 AM so campaigns activated "now" get a full working day.
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00'));

        $this->hr = User::where('email', 'hr@brite-tsi.com')->firstOrFail();
        $this->techUser = User::where('email', 'tech@brite-tsi.com')->firstOrFail();
        $this->tech = $this->techUser->employee;
        $this->siteA = Site::where('type', 'project_site')->firstOrFail();
        $this->office = Site::where('type', 'office')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Site A — Afternoon Presence Verification',
            'project_site_id' => $this->siteA->id,
            'reason' => 'Reports indicate employees leave the site mid-day.',
            'employees' => [$this->tech->id],
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-17',
            'working_start_time' => '08:30',
            'working_end_time' => '17:30',
            'checkpoints_per_day' => 3,
            'minimum_interval_minutes' => 45,
            'maximum_interval_minutes' => 180,
            'response_window_minutes' => 10,
            'photo_instructions' => ['Capture the project entrance.', 'Capture the site office.'],
        ], $overrides);
    }

    private function createAndActivate(array $overrides = []): CheckpointCampaign
    {
        $this->actingAs($this->hr)->post(route('checkpoints.store'), $this->payload($overrides))->assertRedirect();
        $campaign = CheckpointCampaign::latest('id')->firstOrFail();
        $this->assertSame(CheckpointCampaign::DRAFT, $campaign->status);

        $this->actingAs($this->hr)->post(route('checkpoints.activate', $campaign))->assertRedirect();

        return $campaign->refresh();
    }

    /** Open the first scheduled checkpoint for the tech by moving the clock to its time. */
    private function openFirst(CheckpointCampaign $campaign): Checkpoint
    {
        $cp = $campaign->checkpoints()->where('employee_id', $this->tech->id)->orderBy('scheduled_at')->firstOrFail();
        Carbon::setTestNow($cp->scheduled_at->copy()->addSeconds(5));
        app(CheckpointDispatcher::class)->tick();

        return $cp->refresh();
    }

    private function submit(Checkpoint $cp, ?float $lat, ?float $lng, array $extra = [])
    {
        return $this->actingAs($this->techUser)->post(route('my-checkpoints.submit', $cp), array_merge([
            'latitude' => $lat, 'longitude' => $lng, 'gps_accuracy' => 12, 'photo' => self::PHOTO,
        ], $extra));
    }

    // ── permissions ─────────────────────────────────────────────────

    public function test_employees_cannot_open_the_management_module(): void
    {
        $this->actingAs($this->techUser)->get(route('checkpoints.index'))->assertForbidden();
        $this->actingAs($this->techUser)->post(route('checkpoints.store'), $this->payload())->assertForbidden();
        $this->actingAs($this->hr)->get(route('checkpoints.index'))->assertOk()->assertSee('Check Point');
    }

    public function test_sidebar_shows_check_point_only_to_authorized_roles(): void
    {
        $this->actingAs($this->hr)->get(route('dashboard'))->assertSee(route('checkpoints.index'));
        $this->actingAs($this->techUser)->get(route('dashboard'))->assertDontSee(route('checkpoints.index'))->assertSee(route('my-checkpoints.index'));
    }

    // ── campaign lifecycle ──────────────────────────────────────────

    public function test_activating_a_campaign_generates_secret_random_checkpoints_for_today(): void
    {
        $campaign = $this->createAndActivate();

        $this->assertSame(CheckpointCampaign::ACTIVE, $campaign->status);
        $this->assertSame($this->hr->id, $campaign->activated_by);

        $cps = $campaign->checkpoints()->orderBy('scheduled_at')->get();
        $this->assertCount(3, $cps);
        $this->assertTrue($cps->every(fn ($c) => $c->verification_status === Checkpoint::SCHEDULED));

        // All after activation, inside the window, spaced by the configured gaps.
        $latestOpen = Carbon::parse('2026-09-15 17:20:00');
        foreach ($cps as $i => $cp) {
            $this->assertTrue($cp->scheduled_at->gt(Carbon::now()), 'checkpoint must be in the future');
            $this->assertTrue($cp->scheduled_at->lte($latestOpen), 'checkpoint must leave room for the response window');
            if ($i > 0) {
                $gap = $cps[$i - 1]->scheduled_at->diffInMinutes($cp->scheduled_at);
                $this->assertGreaterThanOrEqual(45, $gap);
                $this->assertLessThanOrEqual(180, $gap);
            }
        }

        // Campaign page never lists upcoming times; it only counts them.
        $this->actingAs($this->hr)->get(route('checkpoints.show', $campaign))
            ->assertOk()->assertSee('3 checkpoint(s) still to open')
            ->assertDontSee($cps[0]->scheduled_at->format('g:i A'));

        $this->assertDatabaseHas('checkpoint_audit_logs', ['campaign_id' => $campaign->id, 'action' => 'activated', 'user_id' => $this->hr->id]);
    }

    public function test_future_start_date_schedules_instead_of_activating_and_auto_starts_on_the_day(): void
    {
        $campaign = $this->createAndActivate(['start_date' => '2026-09-16', 'end_date' => '2026-09-16']);
        $this->assertSame(CheckpointCampaign::SCHEDULED, $campaign->status);
        $this->assertSame(0, $campaign->checkpoints()->count());

        Carbon::setTestNow('2026-09-16 08:00:00');
        app(CheckpointDispatcher::class)->tick();
        $this->assertSame(CheckpointCampaign::ACTIVE, $campaign->refresh()->status);
        $this->assertSame(3, $campaign->checkpoints()->count());

        // Past the end date → completed automatically, leftover scheduled ones cancelled.
        Carbon::setTestNow('2026-09-17 00:01:00');
        app(CheckpointDispatcher::class)->tick();
        $this->assertSame(CheckpointCampaign::COMPLETED, $campaign->refresh()->status);
        $this->assertSame(0, $campaign->checkpoints()->status(Checkpoint::SCHEDULED)->count());
    }

    public function test_infeasible_schedule_is_rejected(): void
    {
        $this->actingAs($this->hr)->post(route('checkpoints.store'), $this->payload([
            'checkpoints_per_day' => 12, 'minimum_interval_minutes' => 120,
        ]))->assertSessionHasErrors('checkpoints_per_day');
    }

    public function test_pause_skips_missed_times_and_resume_continues(): void
    {
        $campaign = $this->createAndActivate();
        $first = $campaign->checkpoints()->orderBy('scheduled_at')->first();

        $this->actingAs($this->hr)->post(route('checkpoints.pause', $campaign), ['reason' => 'Site meeting'])->assertRedirect();
        $this->assertSame(CheckpointCampaign::PAUSED, $campaign->refresh()->status);

        // Time passes over the first checkpoint while paused: it must NOT open.
        Carbon::setTestNow($first->scheduled_at->copy()->addMinute());
        app(CheckpointDispatcher::class)->tick();
        $this->assertSame(Checkpoint::SCHEDULED, $first->refresh()->verification_status);
        Notification::assertNothingSent();

        $this->actingAs($this->hr)->post(route('checkpoints.resume', $campaign))->assertRedirect();
        $this->assertSame(CheckpointCampaign::ACTIVE, $campaign->refresh()->status);
        $this->assertSame(Checkpoint::CANCELLED, $first->refresh()->verification_status);
        $this->assertSame('campaign_paused', $first->failure_reason);
        $this->assertSame(2, $campaign->checkpoints()->status(Checkpoint::SCHEDULED)->count());
    }

    public function test_ending_early_keeps_evidence_and_cancels_pending_checkpoints(): void
    {
        $campaign = $this->createAndActivate();
        $cp = $this->openFirst($campaign);
        $this->submit($cp, (float) $this->siteA->latitude, (float) $this->siteA->longitude)->assertRedirect();
        $this->assertSame(Checkpoint::VERIFIED, $cp->refresh()->verification_status);

        $this->actingAs($this->hr)->post(route('checkpoints.end', $campaign), ['reason' => 'Concern resolved'])->assertRedirect();
        $campaign->refresh();
        $this->assertSame(CheckpointCampaign::COMPLETED, $campaign->status);
        $this->assertSame($this->hr->id, $campaign->closed_by);
        $this->assertNotNull($campaign->closed_at);

        // Verified evidence survives; the queued ones are cancelled, not deleted.
        $this->assertSame(Checkpoint::VERIFIED, $cp->refresh()->verification_status);
        $this->assertNotNull($cp->photo_path);
        Storage::disk('local')->assertExists($cp->photo_path);
        $this->assertSame(3, $campaign->checkpoints()->count());
        $this->assertSame(2, $campaign->checkpoints()->status(Checkpoint::CANCELLED)->count());

        $this->actingAs($this->hr)->get(route('checkpoints.history'))->assertOk()->assertSee($campaign->name);
        $this->actingAs($this->hr)->get(route('checkpoints.export', $campaign))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    // ── opening & notifications ─────────────────────────────────────

    public function test_dispatcher_opens_due_checkpoint_and_notifies_only_the_employee(): void
    {
        $campaign = $this->createAndActivate();
        $cp = $this->openFirst($campaign);

        $this->assertSame(Checkpoint::OPEN, $cp->verification_status);
        $this->assertNotNull($cp->opened_at);
        $this->assertEquals($cp->scheduled_at->copy()->addMinutes(10), $cp->expires_at);

        Notification::assertSentTo($this->techUser, CheckpointOpened::class, function (CheckpointOpened $n) use ($cp) {
            $data = $n->toArray($this->techUser);

            return $data['checkpoint_id'] === $cp->id
                && str_contains($data['message'], 'within 10 minutes')
                && $data['url'] === route('my-checkpoints.show', $cp);
        });
        Notification::assertNotSentTo($this->hr, CheckpointOpened::class);

        // The employee can now see it — with countdown, site and instruction.
        $this->actingAs($this->techUser)->get(route('my-checkpoints.show', $cp))
            ->assertOk()->assertSee($this->siteA->name)->assertSee($cp->photo_instruction)->assertSee('Submit Checkpoint');

        // But upcoming ones are still hidden.
        $next = $campaign->checkpoints()->status(Checkpoint::SCHEDULED)->first();
        $this->actingAs($this->techUser)->get(route('my-checkpoints.show', $next))->assertNotFound();
    }

    public function test_unanswered_checkpoint_is_marked_missed_and_flagged_for_review(): void
    {
        $campaign = $this->createAndActivate();
        $cp = $this->openFirst($campaign);

        Carbon::setTestNow($cp->expires_at->copy()->addMinute());
        app(CheckpointDispatcher::class)->tick();

        $cp->refresh();
        $this->assertSame(Checkpoint::MISSED, $cp->verification_status);
        $this->assertSame('pending', $cp->review_status);
        Notification::assertSentTo($this->hr, CheckpointExceptionFlagged::class);

        // Employee may add an explanation; late submission is refused.
        $this->submit($cp, (float) $this->siteA->latitude, (float) $this->siteA->longitude)->assertSessionHasErrors('checkpoint');
        $this->actingAs($this->techUser)->post(route('my-checkpoints.explain', $cp), ['employee_explanation' => 'Phone had no signal in the basement.'])->assertRedirect();
        $this->assertSame('Phone had no signal in the basement.', $cp->refresh()->employee_explanation);
    }

    // ── submission validation ───────────────────────────────────────

    public function test_submission_inside_the_project_site_is_verified(): void
    {
        $campaign = $this->createAndActivate();
        $cp = $this->openFirst($campaign);

        $this->submit($cp, (float) $this->siteA->latitude + 0.0003, (float) $this->siteA->longitude)->assertRedirect(route('my-checkpoints.show', $cp));

        $cp->refresh();
        $this->assertSame(Checkpoint::VERIFIED, $cp->verification_status);
        $this->assertNull($cp->failure_reason);
        $this->assertTrue($cp->within_geofence);
        $this->assertNull($cp->review_status);
        $this->assertNotNull($cp->submitted_at);
        $this->assertEquals(Carbon::now(), $cp->server_timestamp);
        $this->assertLessThan(200, (float) $cp->distance_from_site_meters);
        $this->assertNotNull($cp->photo_path);
        Storage::disk('local')->assertExists($cp->photo_path);
        Notification::assertNotSentTo($this->hr, CheckpointExceptionFlagged::class);

        // Duplicate submissions are refused and the stored evidence is untouched.
        $before = $cp->only(['latitude', 'longitude', 'photo_path', 'submitted_at']);
        $this->submit($cp, 0.0, 0.0)->assertSessionHasErrors('checkpoint');
        $this->assertEquals($before, $cp->refresh()->only(['latitude', 'longitude', 'photo_path', 'submitted_at']));
    }

    public function test_submission_outside_every_geofence_fails_and_creates_an_exception(): void
    {
        $campaign = $this->createAndActivate();
        $cp = $this->openFirst($campaign);

        $this->submit($cp, 14.40, 121.20)->assertRedirect();

        $cp->refresh();
        $this->assertSame(Checkpoint::FAILED, $cp->verification_status);
        $this->assertSame('outside_geofence', $cp->failure_reason);
        $this->assertFalse($cp->within_geofence);
        $this->assertSame('pending', $cp->review_status);
        Notification::assertSentTo($this->hr, CheckpointExceptionFlagged::class);
    }

    public function test_submission_at_the_office_or_with_weak_gps_goes_to_pending_review(): void
    {
        $campaign = $this->createAndActivate();
        $cp = $this->openFirst($campaign);
        $this->submit($cp, (float) $this->office->latitude, (float) $this->office->longitude)->assertRedirect();
        $cp->refresh();
        $this->assertSame(Checkpoint::PENDING_REVIEW, $cp->verification_status);
        $this->assertSame($this->office->id, $cp->matched_site_id);

        // Low accuracy, even inside the fence, is only pending — not verified.
        $second = $campaign->checkpoints()->status(Checkpoint::SCHEDULED)->orderBy('scheduled_at')->first();
        Carbon::setTestNow($second->scheduled_at->copy()->addSeconds(5));
        app(CheckpointDispatcher::class)->tick();
        $this->submit($second->refresh(), (float) $this->siteA->latitude, (float) $this->siteA->longitude, ['gps_accuracy' => 900])->assertRedirect();
        $this->assertSame('low_gps_accuracy', $second->refresh()->failure_reason);
        $this->assertSame(Checkpoint::PENDING_REVIEW, $second->verification_status);

        // No GPS at all.
        $third = $campaign->checkpoints()->status(Checkpoint::SCHEDULED)->orderBy('scheduled_at')->first();
        Carbon::setTestNow($third->scheduled_at->copy()->addSeconds(5));
        app(CheckpointDispatcher::class)->tick();
        $this->submit($third->refresh(), null, null)->assertRedirect();
        $this->assertSame('gps_unavailable', $third->refresh()->failure_reason);
    }

    public function test_photo_is_mandatory_and_must_be_a_live_capture_data_url(): void
    {
        $campaign = $this->createAndActivate();
        $cp = $this->openFirst($campaign);

        $this->submit($cp, (float) $this->siteA->latitude, (float) $this->siteA->longitude, ['photo' => ''])->assertSessionHasErrors('photo');
        $this->submit($cp, (float) $this->siteA->latitude, (float) $this->siteA->longitude, ['photo' => 'https://example.com/gallery.jpg'])->assertSessionHasErrors('photo');
        $this->assertSame(Checkpoint::OPEN, $cp->refresh()->verification_status);
    }

    public function test_only_the_owner_can_submit_their_checkpoint(): void
    {
        $campaign = $this->createAndActivate();
        $cp = $this->openFirst($campaign);

        $this->actingAs($this->hr)->post(route('my-checkpoints.submit', $cp), ['photo' => self::PHOTO])->assertForbidden();
        $this->actingAs($this->hr)->get(route('my-checkpoints.show', $cp))->assertForbidden();
    }

    // ── review ──────────────────────────────────────────────────────

    public function test_hr_reviews_an_exception_and_the_employee_is_notified(): void
    {
        $campaign = $this->createAndActivate();
        $cp = $this->openFirst($campaign);
        $this->submit($cp, 14.40, 121.20);

        $this->actingAs($this->hr)->get(route('checkpoints.results.show', $cp))
            ->assertOk()->assertSee('Exception review')->assertSee('No approved leave-site record');

        $this->actingAs($this->hr)->post(route('checkpoints.results.review', $cp), [
            'review_result' => 'approved_official_errand', 'review_remarks' => 'Sent to supplier by the PM.',
        ])->assertRedirect();

        $cp->refresh();
        $this->assertSame('reviewed', $cp->review_status);
        $this->assertSame('approved_official_errand', $cp->review_result);
        $this->assertSame($this->hr->id, $cp->reviewed_by);
        $this->assertSame(Checkpoint::FAILED, $cp->verification_status, 'review classifies; it does not rewrite the evidence');
        Notification::assertSentTo($this->techUser, CheckpointReviewed::class);
        $this->assertDatabaseHas('checkpoint_audit_logs', ['checkpoint_id' => $cp->id, 'action' => 'reviewed', 'user_id' => $this->hr->id]);

        $this->actingAs($this->techUser)->post(route('checkpoints.results.review', $cp), ['review_result' => 'valid_reason'])->assertForbidden();
    }

    public function test_approved_early_leave_is_shown_during_review(): void
    {
        $campaign = $this->createAndActivate(['working_start_time' => '08:30', 'working_end_time' => '17:30', 'checkpoints_per_day' => 1, 'minimum_interval_minutes' => 5, 'maximum_interval_minutes' => 30]);
        $cp = $this->openFirst($campaign);

        $this->tech->leaveRequests()->create([
            'date_from' => '2026-09-15', 'date_to' => '2026-09-15', 'days' => 0.5, 'day_portion' => 'half_pm',
            'is_early_leave' => true, 'requested_time_out' => '09:00:00', 'reason' => 'Sick', 'status' => 'approved',
        ]);

        Carbon::setTestNow($cp->expires_at->copy()->addMinute());
        app(CheckpointDispatcher::class)->tick();

        $this->actingAs($this->hr)->get(route('checkpoints.results.show', $cp))
            ->assertOk()->assertSee('Approved early leave covers this checkpoint');
    }

    // ── photo access ────────────────────────────────────────────────

    public function test_checkpoint_photos_are_private(): void
    {
        $campaign = $this->createAndActivate();
        $cp = $this->openFirst($campaign);
        $this->submit($cp, (float) $this->siteA->latitude, (float) $this->siteA->longitude);

        $other = User::factory()->create();
        $this->actingAs($other)->get(route('checkpoints.photo', $cp))->assertForbidden();
        $this->actingAs($this->techUser)->get(route('checkpoints.photo', $cp))->assertOk();
        $this->actingAs($this->hr)->get(route('checkpoints.photo', $cp))->assertOk();
    }

    public function test_every_module_page_renders(): void
    {
        $campaign = $this->createAndActivate();
        $cp = $this->openFirst($campaign);
        $this->submit($cp, 14.40, 121.20);

        $this->actingAs($this->hr)->get(route('checkpoints.create'))->assertOk()->assertSee('Create Checkpoint Campaign');
        $this->actingAs($this->hr)->get(route('checkpoints.results.index', ['review' => 'pending']))->assertOk()->assertSee($cp->reference());
        $this->actingAs($this->hr)->get(route('checkpoints.settings'))->assertOk();
        $this->actingAs($this->hr)->put(route('checkpoints.settings.update'), [
            'checkpoints_per_day' => 2, 'minimum_interval_minutes' => 30, 'maximum_interval_minutes' => 120,
            'response_window_minutes' => 15, 'photo_instructions' => "Capture the gate.\nCapture the crane.",
        ])->assertRedirect();
        $this->actingAs($this->hr)->get(route('checkpoints.create'))->assertOk()->assertSee('Capture the crane.');
        $this->actingAs($this->hr)->get(route('checkpoints.index'))->assertOk()->assertSee('Exceptions requiring review');

        $this->actingAs($this->techUser)->get(route('my-checkpoints.index'))->assertOk()->assertSee($cp->reference());
        $this->actingAs($this->techUser)->get(route('my-checkpoints.show', $cp))->assertOk()->assertSee('Explain to HR');

        // Draft edit form.
        $this->actingAs($this->hr)->post(route('checkpoints.store'), $this->payload(['name' => 'Draft one']));
        $draft = CheckpointCampaign::where('name', 'Draft one')->firstOrFail();
        $this->actingAs($this->hr)->get(route('checkpoints.edit', $draft))->assertOk()->assertSee('Edit Checkpoint Campaign');
        $this->actingAs($this->hr)->post(route('checkpoints.cancel', $draft))->assertRedirect(route('checkpoints.index'));
        $this->assertSame(CheckpointCampaign::CANCELLED, $draft->refresh()->status);
    }

    // ── scheduler math ──────────────────────────────────────────────

    public function test_random_times_respect_window_and_gaps(): void
    {
        $s = new CheckpointScheduler;
        $from = Carbon::parse('2026-09-15 08:30');
        $to = Carbon::parse('2026-09-15 17:20');

        for ($run = 0; $run < 50; $run++) {
            $times = $s->randomTimes($from, $to, 3, 45, 180);
            $this->assertCount(3, $times);
            $this->assertTrue($times[0]->gte($from) && $times[0]->lte($from->copy()->addMinutes(180)));
            foreach ($times as $i => $t) {
                $this->assertTrue($t->lte($to));
                if ($i > 0) {
                    $gap = $times[$i - 1]->diffInMinutes($t);
                    $this->assertGreaterThanOrEqual(45, $gap);
                    $this->assertLessThanOrEqual(180, $gap);
                }
            }
        }

        // Too small a window shrinks the count rather than failing.
        $this->assertCount(1, $s->randomTimes($from, $from->copy()->addMinutes(30), 3, 45, 180));
        $this->assertNotNull($s->validate(60, 3, 45, 180, 10));
        $this->assertNull($s->validate(540, 3, 45, 180, 10));
    }
}
