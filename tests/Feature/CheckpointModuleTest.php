<?php

namespace Tests\Feature;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Models\CheckpointReview;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Notifications\CheckpointActivated;
use App\Notifications\CheckpointExceptionFlagged;
use App\Notifications\CheckpointReviewed;
use App\Services\Checkpoint\CheckpointDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Shared live presence checkpoint: one server start/deadline for every
 * selected employee, server-side validation, automatic expiry, HR follow-up
 * with separate review records, permissions and evidence retention.
 */
class CheckpointModuleTest extends TestCase
{
    use RefreshDatabase;

    private const PHOTO = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

    private User $hr;

    private User $techUser;

    private Employee $tech;

    private Employee $second;

    private User $secondUser;

    private Site $siteA;

    private Site $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-09-15 14:30:00'));

        $this->hr = User::where('email', 'hr@brite-tsi.com')->firstOrFail();
        $this->techUser = User::where('email', 'tech@brite-tsi.com')->firstOrFail();
        $this->tech = $this->techUser->employee;
        $this->siteA = Site::where('type', 'project_site')->firstOrFail();
        $this->office = Site::where('type', 'office')->firstOrFail();

        // A second employee so "same deadline for everyone" is actually tested.
        $this->secondUser = User::factory()->create(['name' => 'Second Tech']);
        $this->secondUser->assignRole('employee');
        $this->second = Employee::create([
            'user_id' => $this->secondUser->id, 'employee_no' => 'EMP-0099', 'first_name' => 'Second', 'last_name' => 'Tech',
            'email' => $this->secondUser->email, 'employee_type' => 'technical', 'schedule_id' => $this->tech->schedule_id,
            'monthly_salary' => 20000, 'daily_rate' => 909.09, 'date_hired' => '2025-01-06', 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Site A — Afternoon presence check',
            'project_site_id' => $this->siteA->id,
            'instruction' => 'Capture the project entrance.',
            'employees' => [$this->tech->id, $this->second->id],
            'response_window_minutes' => 10,
        ], $overrides);
    }

    private function createDraft(array $overrides = []): CheckpointCampaign
    {
        $this->actingAs($this->hr)->post(route('checkpoints.store'), $this->payload($overrides))->assertRedirect();
        $campaign = CheckpointCampaign::latest('id')->firstOrFail();
        $this->assertSame(CheckpointCampaign::DRAFT, $campaign->status);

        return $campaign;
    }

    private function activate(array $overrides = []): CheckpointCampaign
    {
        $campaign = $this->createDraft($overrides);
        $this->actingAs($this->hr)->post(route('checkpoints.activate', $campaign))->assertRedirect();

        return $campaign->refresh();
    }

    private function responseOf(CheckpointCampaign $campaign, Employee $employee): Checkpoint
    {
        return $campaign->checkpoints()->where('employee_id', $employee->id)->firstOrFail();
    }

    private function submit(Checkpoint $cp, User $as, ?float $lat, ?float $lng, array $extra = [])
    {
        return $this->actingAs($as)->post(route('my-checkpoints.submit', $cp), array_merge([
            'latitude' => $lat, 'longitude' => $lng, 'gps_accuracy' => 12, 'photo' => self::PHOTO,
        ], $extra));
    }

    // ── permissions & navigation ────────────────────────────────────

    public function test_employees_cannot_open_the_management_module(): void
    {
        $this->actingAs($this->techUser)->get(route('checkpoints.index'))->assertForbidden();
        $this->actingAs($this->techUser)->post(route('checkpoints.store'), $this->payload())->assertForbidden();
        $this->actingAs($this->hr)->get(route('checkpoints.index'))->assertOk()->assertSee('Check Point');
    }

    public function test_sidebar_shows_check_point_only_to_authorized_roles(): void
    {
        $this->actingAs($this->hr)->get(route('dashboard'))->assertSee(route('checkpoints.index'));

        // Not deployed to any project yet: the employee-facing module is not offered at all.
        $this->actingAs($this->techUser)->get(route('dashboard'))
            ->assertDontSee(route('checkpoints.index'))
            ->assertDontSee(route('my-checkpoints.index'));
    }

    /** The module is per-project: only employees deployed to (and picked for) that project see it. */
    public function test_my_checkpoints_link_only_shows_for_employees_assigned_to_the_checkpointed_project(): void
    {
        $otherSite = Site::create([
            'name' => 'Project Site B — Makati', 'type' => 'project_site', 'client_name' => 'Other Client',
            'address' => 'Makati, Philippines', 'latitude' => 14.5547, 'longitude' => 121.0244,
            'geofence_radius_m' => 200, 'status' => 'active',
        ]);

        // Deployed to a different project entirely: no link, campaign or not.
        $this->tech->projectAssignments()->create([
            'site_id' => $otherSite->id, 'start_date' => '2026-09-01', 'status' => 'active', 'created_by' => $this->hr->id,
        ]);
        $this->createDraft(); // campaign is for siteA, tech + second as participants
        // actingAs() pins the exact object passed; fetch it fresh each time so it
        // is not serving a stale, pre-mutation "activeAssignment" from its cache.
        $this->actingAs($this->techUser->fresh())->get(route('dashboard'))->assertDontSee(route('my-checkpoints.index'));

        // Deployed to Site A (the checkpointed project) and picked as a participant: link appears.
        $this->tech->fresh()->activeAssignment->end(Carbon::parse('2026-09-14'));
        $this->tech->projectAssignments()->create([
            'site_id' => $this->siteA->id, 'start_date' => '2026-09-15', 'status' => 'active', 'created_by' => $this->hr->id,
        ]);
        $this->actingAs($this->techUser->fresh())->get(route('dashboard'))->assertSee(route('my-checkpoints.index'));

        // A third employee also deployed to Site A but never picked for the campaign: still hidden.
        $thirdUser = User::factory()->create(['name' => 'Third Tech']);
        $thirdUser->assignRole('employee');
        $third = Employee::create([
            'user_id' => $thirdUser->id, 'employee_no' => 'EMP-0098', 'first_name' => 'Third', 'last_name' => 'Tech',
            'email' => $thirdUser->email, 'employee_type' => 'technical', 'schedule_id' => $this->tech->schedule_id,
            'monthly_salary' => 20000, 'daily_rate' => 909.09, 'date_hired' => '2025-01-06', 'status' => 'active',
        ]);
        $third->projectAssignments()->create([
            'site_id' => $this->siteA->id, 'start_date' => '2026-09-15', 'status' => 'active', 'created_by' => $this->hr->id,
        ]);
        $this->actingAs($thirdUser->fresh())->get(route('dashboard'))->assertDontSee(route('my-checkpoints.index'));
    }

    // ── activation: one shared start and deadline ───────────────────

    public function test_activation_stamps_one_server_start_and_deadline_for_every_employee(): void
    {
        $campaign = $this->activate();

        $this->assertSame(CheckpointCampaign::ACTIVE, $campaign->status);
        $this->assertEquals(Carbon::parse('2026-09-15 14:30:00'), $campaign->starts_at);
        $this->assertEquals(Carbon::parse('2026-09-15 14:40:00'), $campaign->expires_at);
        $this->assertSame($this->hr->id, $campaign->activated_by);

        // One response row per employee — all pointing at the same campaign window.
        $rows = $campaign->checkpoints()->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($cp) => $cp->status === Checkpoint::NOTIFIED && $cp->notified_at !== null));
        $this->assertSame(1, $rows->pluck('campaign_id')->unique()->count());

        // Everyone notified with the same deadline and the required wording.
        foreach ([$this->techUser, $this->secondUser] as $u) {
            Notification::assertSentTo($u, CheckpointActivated::class, function (CheckpointActivated $n) use ($u) {
                $d = $n->toArray($u);

                return str_contains($d['message'], 'Live presence checkpoint active. Please complete your verification before 2:40 PM.')
                    && $d['expires_at'] === Carbon::parse('2026-09-15 14:40:00')->toIso8601String();
            });
        }
        Notification::assertNotSentTo($this->hr, CheckpointActivated::class);

        $this->assertDatabaseHas('checkpoint_audit_logs', ['campaign_id' => $campaign->id, 'action' => 'activated', 'user_id' => $this->hr->id]);

        // Monitoring page shows the shared times and one row per employee, all still waiting.
        $this->actingAs($this->hr)->get(route('checkpoints.show', $campaign))->assertOk()
            ->assertSee('2:30 PM')->assertSee('2:40 PM')->assertSee('of 2 completed')->assertSee('2 waiting')->assertSee($this->tech->full_name)->assertSee($this->second->full_name);
    }

    public function test_scheduled_start_is_activated_by_the_server_at_that_time(): void
    {
        $campaign = $this->createDraft();
        $this->actingAs($this->hr)->post(route('checkpoints.schedule', $campaign), ['scheduled_start_at' => '2026-09-15 15:00'])->assertRedirect();
        $this->assertTrue($campaign->refresh()->isScheduled());
        $this->assertSame(0, $campaign->checkpoints()->count());

        Carbon::setTestNow('2026-09-15 14:59:00');
        app(CheckpointDispatcher::class)->tick();
        $this->assertSame(CheckpointCampaign::DRAFT, $campaign->refresh()->status);

        Carbon::setTestNow('2026-09-15 15:00:10');
        app(CheckpointDispatcher::class)->tick();
        $campaign->refresh();
        $this->assertSame(CheckpointCampaign::ACTIVE, $campaign->status);
        $this->assertEquals(Carbon::parse('2026-09-15 15:00:10'), $campaign->starts_at);
        $this->assertEquals(Carbon::parse('2026-09-15 15:10:10'), $campaign->expires_at);
        $this->assertSame(2, $campaign->checkpoints()->count());
        Notification::assertSentTo($this->techUser, CheckpointActivated::class);

        // Past-time scheduling is refused.
        $draft = $this->createDraft(['name' => 'Another']);
        $this->actingAs($this->hr)->post(route('checkpoints.schedule', $draft), ['scheduled_start_at' => '2026-09-15 10:00'])->assertSessionHasErrors('scheduled_start_at');
    }

    public function test_system_can_draw_a_random_start_time_the_admin_can_see(): void
    {
        // Test clock is 2026-09-15 14:30 (see setUp); window 13:00–17:00 with a 10-min response window, so the draw lands in 14:31–16:50.
        $campaign = $this->createDraft();
        $this->actingAs($this->hr)
            ->post(route('checkpoints.schedule-random', $campaign), ['date' => '2026-09-15', 'window_start' => '13:00', 'window_end' => '17:00'])
            ->assertRedirect()->assertSessionHas('status');

        $campaign->refresh();
        $this->assertTrue($campaign->isScheduled());
        $this->assertTrue($campaign->isRandomlyScheduled());
        $this->assertSame('1:00 PM – 5:00 PM', $campaign->randomWindowLabel());
        $this->assertTrue($campaign->scheduled_start_at->between(Carbon::parse('2026-09-15 13:00'), Carbon::parse('2026-09-15 16:50')));
        $this->assertSame(0, $campaign->checkpoints()->count(), 'Nothing opens until the drawn time');

        // The admin can see the drawn time on the campaign page; the employee's notification has not been sent.
        $this->actingAs($this->hr)->get(route('checkpoints.show', $campaign))
            ->assertOk()->assertSee('Fires at')->assertSee($campaign->scheduled_start_at->format('g:i A'))->assertSee('System-generated');
        Notification::assertNothingSent();

        // The server fires it at that time like any scheduled campaign.
        Carbon::setTestNow($campaign->scheduled_start_at->copy()->addSeconds(5));
        app(CheckpointDispatcher::class)->tick();
        $this->assertSame(CheckpointCampaign::ACTIVE, $campaign->refresh()->status);
        Notification::assertSentTo($this->techUser, CheckpointActivated::class);

        // A window that cannot fit the response window is refused.
        $draft = $this->createDraft(['name' => 'Too short']);
        $this->actingAs($this->hr)
            ->post(route('checkpoints.schedule-random', $draft), ['date' => '2026-09-15', 'window_start' => '13:00', 'window_end' => '13:05'])
            ->assertSessionHasErrors('random_window');
        // A window already in the past is refused too.
        $this->actingAs($this->hr)
            ->post(route('checkpoints.schedule-random', $draft), ['date' => '2026-09-15', 'window_start' => '08:00', 'window_end' => '09:00'])
            ->assertSessionHasErrors('random_window');
    }

    public function test_active_endpoint_reports_the_shared_checkpoint_to_the_employee(): void
    {
        $campaign = $this->activate();
        $cp = $this->responseOf($campaign, $this->tech);

        $this->actingAs($this->techUser)->getJson(route('my-checkpoints.active'))
            ->assertOk()
            ->assertJsonPath('active.id', $cp->id)
            ->assertJsonPath('active.instruction', 'Capture the project entrance.')
            ->assertJsonPath('active.expires_at', $campaign->expires_at->toIso8601String());

        $this->actingAs($this->hr)->getJson(route('my-checkpoints.active'))->assertOk()->assertJsonPath('active', null);
    }

    // ── submissions ─────────────────────────────────────────────────

    public function test_employees_submit_at_different_times_against_the_same_deadline(): void
    {
        $campaign = $this->activate();
        $a = $this->responseOf($campaign, $this->tech);
        $b = $this->responseOf($campaign, $this->second);

        Carbon::setTestNow('2026-09-15 14:31:00');
        $this->submit($a, $this->techUser, (float) $this->siteA->latitude + 0.0003, (float) $this->siteA->longitude)->assertRedirect(route('my-checkpoints.show', $a));
        Carbon::setTestNow('2026-09-15 14:38:00');
        $this->submit($b, $this->secondUser, (float) $this->siteA->latitude, (float) $this->siteA->longitude)->assertRedirect();

        $a->refresh();
        $b->refresh();
        $this->assertSame(Checkpoint::RESPONDED, $a->status);
        $this->assertSame(Checkpoint::COMPLETED, $a->verification_result);
        $this->assertEquals(Carbon::parse('2026-09-15 14:31:00'), $a->submitted_at);
        $this->assertSame(60, $a->setRelation('campaign', $campaign)->responseSeconds());
        $this->assertTrue($a->within_geofence);
        $this->assertNotNull($a->photo_path);
        Storage::disk('local')->assertExists($a->photo_path);

        $this->assertSame(Checkpoint::RESPONDED, $b->status);
        $this->assertEquals(Carbon::parse('2026-09-15 14:38:00'), $b->submitted_at);

        // The deadline did not move for anyone.
        $this->assertEquals(Carbon::parse('2026-09-15 14:40:00'), $campaign->refresh()->expires_at);

        // A second successful submission is refused; evidence untouched.
        $before = $a->only(['latitude', 'longitude', 'photo_path', 'submitted_at']);
        $this->submit($a, $this->techUser, 0.0, 0.0)->assertSessionHasErrors('checkpoint');
        $this->assertEquals($before, $a->refresh()->only(['latitude', 'longitude', 'photo_path', 'submitted_at']));
    }

    public function test_submission_after_the_deadline_is_refused_and_employee_is_missed(): void
    {
        $campaign = $this->activate();
        $cp = $this->responseOf($campaign, $this->tech);

        Carbon::setTestNow('2026-09-15 14:41:00');
        $this->submit($cp, $this->techUser, (float) $this->siteA->latitude, (float) $this->siteA->longitude)->assertSessionHasErrors('checkpoint');
        $cp->refresh();
        $this->assertNull($cp->submitted_at);
        $this->assertSame('checkpoint_expired', $cp->last_attempt_result);

        app(CheckpointDispatcher::class)->tick();
        $campaign->refresh();
        $cp->refresh();
        $this->assertSame(CheckpointCampaign::EXPIRED, $campaign->status);
        $this->assertSame(Checkpoint::MISSED, $cp->status);
        $this->assertSame(Checkpoint::MISSED, $this->responseOf($campaign, $this->second)->status);
        Notification::assertSentTo($this->hr, CheckpointExceptionFlagged::class);

        // After reconnecting the employee sees the miss and can explain it.
        $this->actingAs($this->techUser)->get(route('my-checkpoints.show', $cp))->assertOk()->assertSee('Explain to HR');
        $this->actingAs($this->techUser)->post(route('my-checkpoints.explain', $cp), [
            'employee_explanation' => 'No signal in the basement.', 'issue' => 'no_internet',
        ])->assertRedirect();
        $cp->refresh();
        $this->assertSame('No signal in the basement.', $cp->employee_explanation);
        $this->assertSame('No internet reported', $cp->status_label);
    }

    public function test_outside_geofence_can_be_retried_and_is_flagged_if_never_fixed(): void
    {
        $campaign = $this->activate();
        $cp = $this->responseOf($campaign, $this->tech);

        Carbon::setTestNow('2026-09-15 14:36:00');
        $this->submit($cp, $this->techUser, 14.40, 121.20)->assertSessionHasErrors('attempt');
        $cp->refresh();
        $this->assertSame(Checkpoint::OUTSIDE_GEOFENCE, $cp->status);
        $this->assertSame(1, $cp->submission_attempts);
        $this->assertNull($cp->submitted_at);

        // Still inside the window: a retry from inside the fence completes it.
        Carbon::setTestNow('2026-09-15 14:38:00');
        $this->submit($cp, $this->techUser, (float) $this->siteA->latitude, (float) $this->siteA->longitude)->assertRedirect();
        $cp->refresh();
        $this->assertSame(Checkpoint::RESPONDED, $cp->status);
        $this->assertSame(2, $cp->submission_attempts);

        // The other employee stays outside → remains OUTSIDE_GEOFENCE after expiry (not MISSED).
        $other = $this->responseOf($campaign, $this->second);
        $this->submit($other, $this->secondUser, 14.40, 121.20)->assertSessionHasErrors('attempt');
        Carbon::setTestNow('2026-09-15 14:41:00');
        app(CheckpointDispatcher::class)->tick();
        $this->assertSame(Checkpoint::OUTSIDE_GEOFENCE, $other->refresh()->status);
    }

    public function test_weak_gps_inside_fence_completes_with_low_accuracy_and_office_goes_to_review(): void
    {
        $campaign = $this->activate();
        $a = $this->responseOf($campaign, $this->tech);
        $b = $this->responseOf($campaign, $this->second);

        $this->submit($a, $this->techUser, (float) $this->siteA->latitude, (float) $this->siteA->longitude, ['gps_accuracy' => 900])->assertRedirect();
        $a->refresh();
        $this->assertSame(Checkpoint::RESPONDED, $a->status);
        $this->assertSame(Checkpoint::COMPLETED_LOW_ACCURACY, $a->verification_result);

        $this->submit($b, $this->secondUser, (float) $this->office->latitude, (float) $this->office->longitude)->assertSessionHasErrors('attempt');
        $b->refresh();
        $this->assertSame(Checkpoint::PENDING_REVIEW, $b->status);
        $this->assertSame($this->office->id, $b->matched_site_id);

        // No GPS at all.
        $c2 = $this->activate(['name' => 'Second check']);
        $x = $this->responseOf($c2, $this->tech);
        $this->submit($x, $this->techUser, null, null)->assertSessionHasErrors('attempt');
        $this->assertSame(Checkpoint::GPS_UNAVAILABLE, $x->refresh()->status);
    }

    public function test_photo_is_mandatory_and_must_be_a_live_capture_data_url(): void
    {
        $campaign = $this->activate();
        $cp = $this->responseOf($campaign, $this->tech);

        $this->submit($cp, $this->techUser, (float) $this->siteA->latitude, (float) $this->siteA->longitude, ['photo' => ''])->assertSessionHasErrors('photo');
        $this->submit($cp, $this->techUser, (float) $this->siteA->latitude, (float) $this->siteA->longitude, ['photo' => 'https://example.com/gallery.jpg'])->assertSessionHasErrors('photo');
        $this->assertSame(Checkpoint::NOTIFIED, $cp->refresh()->status);
        $this->assertSame(0, $cp->submission_attempts);
    }

    public function test_only_the_owner_can_submit_and_report_issues(): void
    {
        $campaign = $this->activate();
        $cp = $this->responseOf($campaign, $this->tech);

        $this->actingAs($this->secondUser)->post(route('my-checkpoints.submit', $cp), ['photo' => self::PHOTO])->assertForbidden();
        $this->actingAs($this->secondUser)->get(route('my-checkpoints.show', $cp))->assertForbidden();

        $this->actingAs($this->techUser)->postJson(route('my-checkpoints.issue', $cp), ['issue' => 'camera_denied'])->assertOk();
        $this->assertSame(Checkpoint::CAMERA_PERMISSION_DENIED, $cp->refresh()->status);
    }

    // ── pause / resume / end / cancel / complete ────────────────────

    public function test_pause_freezes_submissions_and_resume_extends_the_shared_deadline(): void
    {
        $campaign = $this->activate();
        $cp = $this->responseOf($campaign, $this->tech);

        Carbon::setTestNow('2026-09-15 14:33:00');
        $this->actingAs($this->hr)->post(route('checkpoints.pause', $campaign), ['reason' => 'Site alarm'])->assertRedirect();
        $this->assertSame(CheckpointCampaign::PAUSED, $campaign->refresh()->status);
        $this->submit($cp, $this->techUser, (float) $this->siteA->latitude, (float) $this->siteA->longitude)->assertSessionHasErrors('checkpoint');

        // Paused past the original deadline: it must NOT expire.
        Carbon::setTestNow('2026-09-15 14:45:00');
        app(CheckpointDispatcher::class)->tick();
        $this->assertSame(CheckpointCampaign::PAUSED, $campaign->refresh()->status);

        $this->actingAs($this->hr)->post(route('checkpoints.resume', $campaign))->assertRedirect();
        $campaign->refresh();
        $this->assertSame(CheckpointCampaign::ACTIVE, $campaign->status);
        $this->assertEquals(Carbon::parse('2026-09-15 14:52:00'), $campaign->expires_at, '12 paused minutes are added for everyone');

        $this->submit($cp, $this->techUser, (float) $this->siteA->latitude, (float) $this->siteA->longitude)->assertRedirect();
        $this->assertSame(Checkpoint::RESPONDED, $cp->refresh()->status);
    }

    public function test_end_now_cancel_and_complete_keep_evidence(): void
    {
        $campaign = $this->activate();
        $a = $this->responseOf($campaign, $this->tech);
        $this->submit($a, $this->techUser, (float) $this->siteA->latitude, (float) $this->siteA->longitude);

        $this->actingAs($this->hr)->post(route('checkpoints.end', $campaign))->assertRedirect();
        $campaign->refresh();
        $this->assertSame(CheckpointCampaign::EXPIRED, $campaign->status);
        $this->assertSame(Checkpoint::RESPONDED, $a->refresh()->status);
        $this->assertSame(Checkpoint::MISSED, $this->responseOf($campaign, $this->second)->status);
        Storage::disk('local')->assertExists($a->photo_path);

        $this->actingAs($this->hr)->post(route('checkpoints.complete', $campaign))->assertRedirect();
        $campaign->refresh();
        $this->assertSame(CheckpointCampaign::COMPLETED, $campaign->status);
        $this->assertSame($this->hr->id, $campaign->closed_by);

        $draft = $this->createDraft(['name' => 'Draft one']);
        $this->actingAs($this->hr)->post(route('checkpoints.cancel', $draft), ['reason' => 'Not needed'])->assertRedirect(route('checkpoints.index'));
        $this->assertSame(CheckpointCampaign::CANCELLED, $draft->refresh()->status);

        $this->actingAs($this->hr)->get(route('checkpoints.history'))->assertOk()->assertSee($campaign->name)->assertSee('Draft one');
        $this->actingAs($this->hr)->get(route('checkpoints.export', $campaign))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    // ── HR follow-up ────────────────────────────────────────────────

    public function test_hr_follow_up_creates_review_records_and_never_edits_the_official_times(): void
    {
        $campaign = $this->activate();
        $cp = $this->responseOf($campaign, $this->tech);
        Carbon::setTestNow('2026-09-15 14:41:00');
        app(CheckpointDispatcher::class)->tick();
        $this->assertSame(Checkpoint::MISSED, $cp->refresh()->status);
        $original = [$campaign->refresh()->starts_at, $campaign->expires_at, $cp->server_timestamp];

        $this->actingAs($this->hr)->get(route('checkpoints.show', $campaign))->assertOk()->assertSee('Not completed')->assertSee('2 not completed');
        $this->actingAs($this->hr)->get(route('checkpoints.results.show', $cp))->assertOk()->assertSee('HR follow-up')->assertSee('No approved leave record');

        $url = route('checkpoints.results.follow-up', $cp);
        $this->actingAs($this->hr)->post($url, ['action' => 'explanation', 'explanation' => 'Phone died.', 'reason' => 'device_problem'])->assertRedirect();
        $this->actingAs($this->hr)->post($url, ['action' => 'note', 'note' => 'Second time this month.'])->assertRedirect();
        $this->actingAs($this->hr)->post($url, ['action' => 'mark_review'])->assertRedirect();
        $this->assertSame(Checkpoint::PENDING_REVIEW, $cp->refresh()->status);
        $this->actingAs($this->hr)->post($url, ['action' => 'escalate', 'note' => 'For the PM.'])->assertRedirect();
        $this->assertNotNull($cp->refresh()->escalated_at);
        $this->actingAs($this->hr)->post($url, ['action' => 'approve'])->assertSessionHasErrors('reason');
        $this->actingAs($this->hr)->post($url, ['action' => 'approve', 'reason' => 'work_related', 'note' => 'Sent to supplier by PM.'])->assertRedirect();

        $cp->refresh();
        $this->assertSame(Checkpoint::APPROVED_EXCEPTION, $cp->status);
        $this->assertSame(Checkpoint::COMPLETED_AFTER_REVIEW, $cp->verification_result);
        $this->assertTrue($cp->isCompleted());
        $this->assertSame($this->hr->id, $cp->reviewed_by);
        $this->assertSame('Phone died.', $cp->employee_explanation);
        Notification::assertSentTo($this->techUser, CheckpointReviewed::class);

        // Separate review records, one per action, in order.
        $this->assertSame(
            ['approved', 'escalated', 'marked_for_review', 'note_added', 'explanation_recorded'],
            CheckpointReview::where('checkpoint_id', $cp->id)->orderByDesc('id')->pluck('action')->all(),
        );
        // Official times untouched.
        $this->assertEquals($original, [$campaign->refresh()->starts_at, $campaign->expires_at, $cp->server_timestamp]);

        // An approved exception counts as completed on the monitoring page.
        $this->actingAs($this->hr)->get(route('checkpoints.show', $campaign))->assertOk()->assertSeeInOrder(['>1</span>', 'of 2 completed', '1 not completed', 'Completed'], false);

        // Rejection path + permission gate.
        $other = $this->responseOf($campaign, $this->second);
        $this->actingAs($this->hr)->post(route('checkpoints.results.follow-up', $other), ['action' => 'reject', 'reason' => 'ignored'])->assertRedirect();
        $this->assertSame(Checkpoint::REJECTED_EXCEPTION, $other->refresh()->status);
        $this->actingAs($this->techUser)->post(route('checkpoints.results.follow-up', $other), ['action' => 'approve', 'reason' => 'other'])->assertForbidden();
    }

    public function test_approved_early_leave_is_shown_during_follow_up(): void
    {
        $this->tech->leaveRequests()->create([
            'date_from' => '2026-09-15', 'date_to' => '2026-09-15', 'days' => 0.5, 'day_portion' => 'half_pm',
            'is_early_leave' => true, 'requested_time_out' => '14:00:00', 'reason' => 'Sick', 'status' => 'approved',
        ]);
        $campaign = $this->activate();
        $cp = $this->responseOf($campaign, $this->tech);
        Carbon::setTestNow('2026-09-15 14:41:00');
        app(CheckpointDispatcher::class)->tick();

        $this->actingAs($this->hr)->get(route('checkpoints.results.show', $cp))->assertOk()->assertSee('Approved early leave covers this checkpoint');
    }

    // ── photo access & page rendering ───────────────────────────────

    public function test_checkpoint_photos_are_private(): void
    {
        $campaign = $this->activate();
        $cp = $this->responseOf($campaign, $this->tech);
        $this->submit($cp, $this->techUser, (float) $this->siteA->latitude, (float) $this->siteA->longitude);

        $this->actingAs($this->secondUser)->get(route('checkpoints.photo', $cp))->assertForbidden();
        $this->actingAs($this->techUser)->get(route('checkpoints.photo', $cp))->assertOk();
        $this->actingAs($this->hr)->get(route('checkpoints.photo', $cp))->assertOk();
    }

    public function test_every_module_page_renders(): void
    {
        $campaign = $this->activate();
        $cp = $this->responseOf($campaign, $this->tech);

        $this->actingAs($this->techUser)->get(route('my-checkpoints.index'))->assertOk()->assertSee('Live presence checkpoint active');
        $this->actingAs($this->techUser)->get(route('my-checkpoints.show', $cp))->assertOk()->assertSee('Submit Checkpoint')->assertSee('Capture the project entrance.');
        $this->assertNotNull($cp->refresh()->seen_at);

        $this->actingAs($this->hr)->get(route('checkpoints.create'))->assertOk()->assertSee('Create Checkpoint');
        $this->actingAs($this->hr)->get(route('checkpoints.results.index', ['follow' => 'open']))->assertOk();
        $this->actingAs($this->hr)->get(route('checkpoints.settings'))->assertOk();
        $this->actingAs($this->hr)->put(route('checkpoints.settings.update'), ['response_window_minutes' => 15, 'instructions' => "Capture the gate.\nCapture the crane."])->assertRedirect();
        $this->actingAs($this->hr)->get(route('checkpoints.create'))->assertOk()->assertSee('Capture the crane.');
        $this->actingAs($this->hr)->getJson(route('checkpoints.status', $campaign))->assertOk()->assertJsonPath('total', 2);

        // Daily monitor: today (with and without the site filter), and an empty day.
        $this->actingAs($this->hr)->get(route('checkpoints.daily'))->assertOk()->assertSee($campaign->name)->assertSee('Employee responses')->assertSee('Second Tech');
        $this->actingAs($this->hr)->get(route('checkpoints.daily', ['date' => '2026-09-15', 'site' => $this->siteA->id]))->assertOk()->assertSee($campaign->name);
        $this->actingAs($this->hr)->get(route('checkpoints.daily', ['date' => '2026-09-15', 'site' => $this->office->id]))->assertOk()->assertDontSee($campaign->name);
        $this->actingAs($this->hr)->get(route('checkpoints.daily', ['date' => '2026-09-14']))->assertOk()->assertSee('No checkpoint ran on this day');
        $this->actingAs($this->techUser)->get(route('checkpoints.daily'))->assertForbidden();
        $this->actingAs($this->hr)->get(route('checkpoints.history', ['status' => 'active', 'site' => $this->siteA->id, 'from' => '2026-09-15', 'to' => '2026-09-15']))->assertOk()->assertSee($campaign->name);

        $draft = $this->createDraft(['name' => 'Draft two']);
        $this->actingAs($this->hr)->get(route('checkpoints.show', $draft))->assertOk()->assertSee('Not started')->assertSee('Activate now');
        $this->actingAs($this->hr)->get(route('checkpoints.edit', $draft))->assertOk()->assertSee('Edit Checkpoint');
        $this->actingAs($this->hr)->get(route('checkpoints.index'))->assertOk()->assertSee('Draft two');
    }
}
