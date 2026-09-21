<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteController;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeProjectAssignment;
use App\Models\Site;
use App\Models\User;
use App\Notifications\LocationExceptionFlagged;
use App\Services\GeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Multi-site geofencing: every active location is a valid place to clock in,
 * the assigned project only changes how the punch is labelled, and anything
 * outside every fence goes through the exception flow per geofence_mode.
 */
class GeofenceTest extends TestCase
{
    use RefreshDatabase;

    private Site $office;

    private Site $siteA;

    private Site $siteB;

    private Employee $tech;

    // A tiny (1x1 px) JPEG data URL so the selfie validation passes.
    private const PHOTO = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();

        $this->office = Site::where('type', 'office')->firstOrFail();
        $this->siteA = Site::where('type', 'project_site')->firstOrFail();
        $this->siteB = Site::create([
            'name' => 'Project Site B — Makati', 'type' => 'project_site', 'status' => 'active',
            'latitude' => 14.5547, 'longitude' => 121.0244, 'geofence_radius_m' => 200,
        ]);
        $this->tech = Employee::where('email', 'tech@brite-tsi.com')->firstOrFail();
    }

    private function assign(Employee $employee, Site $site): EmployeeProjectAssignment
    {
        return $employee->projectAssignments()->create([
            'site_id' => $site->id, 'start_date' => today()->subDay()->toDateString(), 'status' => 'active',
        ]);
    }

    private function at(Site $site): array
    {
        return [(float) $site->latitude, (float) $site->longitude];
    }

    private function punch(Employee $employee, ?float $lat, ?float $lng, array $extra = [])
    {
        $this->actingAs($employee->user);

        return $this->post('/attendance', array_merge([
            'log_type' => 'time_in',
            'latitude' => $lat,
            'longitude' => $lng,
            'gps_accuracy' => 12,
            'photo' => self::PHOTO,
        ], $extra));
    }

    // ---- Rule table from the spec -------------------------------------------

    public function test_unassigned_employee_at_main_office_is_verified(): void
    {
        $result = app(GeofenceService::class)->evaluate($this->tech, ...$this->at($this->office));

        $this->assertSame(GeofenceService::VERIFIED_LOCATION, $result->status);
        $this->assertTrue($result->site->is($this->office));
        $this->assertNull($result->assignedSite);
    }

    public function test_assigned_employee_at_their_project_is_verified(): void
    {
        $this->assign($this->tech, $this->siteA);

        $result = app(GeofenceService::class)->evaluate($this->tech, ...$this->at($this->siteA));

        $this->assertSame(GeofenceService::VERIFIED_LOCATION, $result->status);
        $this->assertTrue($result->assignedSite->is($this->siteA));
    }

    public function test_assigned_employee_at_main_office_is_an_authorized_alternate_location(): void
    {
        $this->assign($this->tech, $this->siteA);

        $result = app(GeofenceService::class)->evaluate($this->tech, ...$this->at($this->office));

        $this->assertSame(GeofenceService::AUTHORIZED_ALTERNATE_LOCATION, $result->status);
        $this->assertTrue($result->site->is($this->office));
        $this->assertTrue($result->assignedSite->is($this->siteA));
        $this->assertFalse($result->isException());
    }

    public function test_assigned_employee_at_another_project_is_an_authorized_alternate_location(): void
    {
        $this->assign($this->tech, $this->siteA);

        $result = app(GeofenceService::class)->evaluate($this->tech, ...$this->at($this->siteB));

        $this->assertSame(GeofenceService::AUTHORIZED_ALTERNATE_LOCATION, $result->status);
        $this->assertTrue($result->site->is($this->siteB));
    }

    public function test_outside_every_location_is_flagged(): void
    {
        // ~5 km east of the office — outside all three fences.
        $result = app(GeofenceService::class)->evaluate($this->tech, 14.5995, 121.03);

        $this->assertSame(GeofenceService::OUTSIDE_AUTHORIZED_AREA, $result->status);
        $this->assertNull($result->site);
        $this->assertNotNull($result->nearest);
        $this->assertTrue($result->isException());
    }

    public function test_missing_coordinates_are_gps_unavailable(): void
    {
        $result = app(GeofenceService::class)->evaluate($this->tech, null, null);

        $this->assertSame(GeofenceService::GPS_UNAVAILABLE, $result->status);
        $this->assertTrue($result->isException());
    }

    public function test_poor_accuracy_is_low_accuracy_even_inside_a_fence(): void
    {
        config(['attendance.min_gps_accuracy_m' => 100]);

        $result = app(GeofenceService::class)->evaluate($this->tech, ...[...$this->at($this->office), 450.0]);

        $this->assertSame(GeofenceService::LOW_ACCURACY, $result->status);
        $this->assertTrue($result->within);
    }

    // ---- Project lifecycle ----------------------------------------------------

    public function test_completed_project_no_longer_matches_but_history_is_kept(): void
    {
        $this->assign($this->tech, $this->siteA);
        $this->punch($this->tech, ...$this->at($this->siteA))->assertRedirect(route('attendance.index'));
        $log = AttendanceLog::latest('id')->first();
        $this->assertTrue($log->site->is($this->siteA));

        $this->actingAs(User::where('email', 'hr@brite-tsi.com')->firstOrFail())
            ->patch(route('sites.status', $this->siteA), ['status' => 'completed'])
            ->assertRedirect();

        $this->assertSame('completed', $this->siteA->fresh()->status);
        $this->assertSame('ended', $this->tech->projectAssignments()->first()->status);
        $this->assertNull($this->tech->fresh()->assignedSite());

        // Historical punch still points at Site A …
        $this->assertSame($this->siteA->id, $log->fresh()->site_id);
        // … but a new punch there is now outside every active area.
        $result = app(GeofenceService::class)->evaluate($this->tech->fresh(), ...$this->at($this->siteA));
        $this->assertSame(GeofenceService::OUTSIDE_AUTHORIZED_AREA, $result->status);

        // Main office stays available throughout.
        $result = app(GeofenceService::class)->evaluate($this->tech->fresh(), ...$this->at($this->office));
        $this->assertSame(GeofenceService::VERIFIED_LOCATION, $result->status);
    }

    public function test_site_with_activation_window_is_only_active_inside_it(): void
    {
        $this->siteB->update(['type' => 'temporary', 'active_from' => today()->addDays(3), 'active_until' => today()->addDays(5)]);

        $this->assertFalse($this->siteB->fresh()->isActiveOn());
        $this->assertTrue($this->siteB->fresh()->isActiveOn(today()->addDays(4)));
        $this->assertFalse(Site::activeOn()->where('id', $this->siteB->id)->exists());
    }

    public function test_reassigning_ends_the_previous_assignment(): void
    {
        $hr = User::where('email', 'hr@brite-tsi.com')->firstOrFail();
        $this->actingAs($hr);

        $this->post(route('employees.assignments.store', $this->tech), [
            'site_id' => $this->siteA->id, 'start_date' => today()->subDays(10)->toDateString(),
        ])->assertRedirect();
        $this->post(route('employees.assignments.store', $this->tech), [
            'site_id' => $this->siteB->id, 'start_date' => today()->toDateString(),
        ])->assertRedirect();

        $this->assertTrue($this->tech->fresh()->assignedSite()->is($this->siteB));
        $this->assertSame(1, $this->tech->projectAssignments()->where('status', 'active')->count());
        $this->assertSame('ended', $this->tech->projectAssignments()->where('site_id', $this->siteA->id)->first()->status);
    }

    public function test_office_cannot_be_used_as_a_project_assignment(): void
    {
        $this->actingAs(User::where('email', 'hr@brite-tsi.com')->firstOrFail());

        $this->post(route('employees.assignments.store', $this->tech), [
            'site_id' => $this->office->id, 'start_date' => today()->toDateString(),
        ])->assertSessionHasErrors('site_id');
    }

    // ---- Clock-in behaviour per geofence_mode ----------------------------------

    public function test_warning_mode_records_out_of_area_punch_with_reason_and_notifies_hr(): void
    {
        config(['attendance.geofence_mode' => 'warning']);

        // No reason → rejected with a validation error, nothing saved.
        $this->punch($this->tech, 14.5995, 121.03)->assertSessionHasErrors('location_reason');
        $this->assertSame(0, AttendanceLog::count());

        $this->punch($this->tech, 14.5995, 121.03, ['location_reason' => 'At client warehouse per engineer'])
            ->assertRedirect(route('attendance.index'));

        $log = AttendanceLog::latest('id')->firstOrFail();
        $this->assertSame(GeofenceService::OUTSIDE_AUTHORIZED_AREA, $log->location_status);
        $this->assertNull($log->site_id);
        $this->assertNull($log->location_verification_status);
        $this->assertSame('At client warehouse per engineer', $log->location_reason);
        $this->assertFalse($log->within_geofence);
        $this->assertEquals(12, (float) $log->gps_accuracy_m);

        Notification::assertSentTo(
            User::where('email', 'hr@brite-tsi.com')->first(),
            LocationExceptionFlagged::class,
        );
    }

    public function test_approval_mode_parks_out_of_area_punch_as_pending(): void
    {
        config(['attendance.geofence_mode' => 'approval']);

        $this->punch($this->tech, 14.5995, 121.03, ['location_reason' => 'GPS drift'])->assertRedirect();

        $this->assertSame('pending', AttendanceLog::latest('id')->firstOrFail()->location_verification_status);
    }

    public function test_strict_mode_rejects_out_of_area_punch(): void
    {
        config(['attendance.geofence_mode' => 'strict']);

        $this->punch($this->tech, 14.5995, 121.03, ['location_reason' => 'anything'])->assertSessionHasErrors('latitude');
        $this->assertSame(0, AttendanceLog::count());

        // Inside the office is still fine.
        $this->punch($this->tech, ...$this->at($this->office))->assertRedirect(route('attendance.index'));
        $this->assertSame(GeofenceService::VERIFIED_LOCATION, AttendanceLog::latest('id')->first()->location_status);
    }

    public function test_verified_punch_records_both_matched_and_assigned_site(): void
    {
        $this->assign($this->tech, $this->siteA);

        $this->punch($this->tech, ...$this->at($this->office))->assertRedirect();

        $log = AttendanceLog::latest('id')->firstOrFail();
        $this->assertSame(GeofenceService::AUTHORIZED_ALTERNATE_LOCATION, $log->location_status);
        $this->assertSame($this->office->id, $log->site_id);
        $this->assertSame($this->siteA->id, $log->assigned_site_id);
        $this->assertTrue($log->within_geofence);
    }

    public function test_hr_can_approve_a_location_exception(): void
    {
        $this->punch($this->tech, 14.5995, 121.03, ['location_reason' => 'Temporary site'])->assertRedirect();
        $log = AttendanceLog::latest('id')->firstOrFail();

        $hr = User::where('email', 'hr@brite-tsi.com')->firstOrFail();
        $this->actingAs($hr)
            ->post(route('attendance.verify-location', $log), ['decision' => 'approved', 'remarks' => 'Confirmed with site engineer'])
            ->assertRedirect();

        $log->refresh();
        $this->assertSame('approved', $log->location_verification_status);
        $this->assertSame($hr->id, $log->location_verified_by);
        $this->assertNotNull($log->location_verified_at);
    }

    public function test_verified_punch_cannot_be_location_reviewed(): void
    {
        $this->punch($this->tech, ...$this->at($this->office))->assertRedirect();
        $log = AttendanceLog::latest('id')->firstOrFail();

        $this->actingAs(User::where('email', 'hr@brite-tsi.com')->firstOrFail())
            ->post(route('attendance.verify-location', $log), ['decision' => 'approved'])
            ->assertStatus(422);
    }

    // ---- Access ------------------------------------------------------------------

    public function test_only_settings_managers_can_manage_sites(): void
    {
        $this->actingAs($this->tech->user);
        $this->get(route('sites.index'))->assertForbidden();

        $hr = User::where('email', 'hr@brite-tsi.com')->firstOrFail();
        $this->actingAs($hr);
        $this->get(route('sites.index'))->assertOk()->assertSee($this->office->name);
        $this->get(route('sites.create'))->assertOk();
        $this->get(route('sites.edit', $this->siteA))->assertOk();

        $this->post(route('sites.store'), [
            'name' => 'Training Venue', 'type' => 'temporary', 'status' => 'active',
            'latitude' => 14.6, 'longitude' => 121.0, 'geofence_radius_m' => 100,
            'active_from' => today()->toDateString(), 'active_until' => today()->addDays(2)->toDateString(),
        ])->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', ['name' => 'Training Venue', 'type' => 'temporary', 'created_by' => $hr->id]);
    }

    public function test_clock_screen_shows_only_active_sites(): void
    {
        $this->siteB->update(['status' => 'inactive']);

        // Site names are embedded via @json(), which escapes non-ASCII (the em
        // dash), so match on the plain-ASCII part of each name.
        $this->actingAs($this->tech->user)
            ->get('/attendance')
            ->assertOk()
            ->assertSee('Brite TSI')
            ->assertSee('Project Site A')
            ->assertDontSee('Project Site B');
    }

    public function test_monitor_and_history_render_a_location_exception(): void
    {
        $this->assign($this->tech, $this->siteA);
        $this->punch($this->tech, 14.5995, 121.03, ['location_reason' => 'Client warehouse today'])->assertRedirect();

        // Employee sees the flag and their own reason on My Attendance.
        $this->actingAs($this->tech->user)
            ->get('/attendance/logs')
            ->assertOk()
            ->assertSee('Outside area');

        // HR sees the exception card with the reason, assigned project and the review form.
        $this->actingAs(User::where('email', 'hr@brite-tsi.com')->firstOrFail())
            ->get(route('attendance.monitor', ['date' => today()->toDateString()]))
            ->assertOk()
            ->assertSee('Location exception')
            ->assertSee('Client warehouse today')
            ->assertSee('Assigned project: Project Site A', false)
            ->assertSee('Approve location');
    }

    public function test_soft_copy_renders_a_png_with_the_location_overlay(): void
    {
        $this->assign($this->tech, $this->siteA);
        $this->punch($this->tech, ...$this->at($this->office))->assertRedirect();
        $log = AttendanceLog::latest('id')->firstOrFail();

        $response = $this->actingAs($this->tech->user)
            ->get(route('attendance.softcopy', ['id' => $log->id, 'type' => 'in']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $png = $response->getContent();
        $this->assertStringStartsWith("\x89PNG", $png);
        [$w, $h] = getimagesizefromstring($png);
        $this->assertGreaterThanOrEqual(1080, min($w, $h));
    }

    public function test_monthly_timesheet_shows_daily_punches_and_totals(): void
    {
        $this->assign($this->tech, $this->siteA);
        $this->punch($this->tech, ...$this->at($this->siteA))->assertRedirect();

        // Own timesheet
        $this->actingAs($this->tech->user)
            ->get(route('attendance.timesheet', ['employee' => $this->tech, 'month' => today()->format('Y-m')]))
            ->assertOk()
            ->assertSee('Monthly Timesheet')
            ->assertSee(today()->format('M j'))
            ->assertSee('Clocked in');

        // Another employee's timesheet needs the reporting permission
        $hrEmp = Employee::where('email', 'hr@brite-tsi.com')->firstOrFail();
        $this->actingAs($this->tech->user)->get(route('attendance.timesheet', $hrEmp))->assertForbidden();
        $this->actingAs($hrEmp->user)->get(route('attendance.timesheet', $this->tech))->assertOk()->assertSee('Project Site A');
    }

    public function test_employee_edit_shows_assignment_panel(): void
    {
        $this->assign($this->tech, $this->siteA);

        $this->actingAs(User::where('email', 'hr@brite-tsi.com')->firstOrFail())
            ->get(route('employees.edit', $this->tech))
            ->assertOk()
            ->assertSee('Project site assignment')
            ->assertSee($this->siteA->name);
    }

    public function test_google_maps_links_resolve_to_coordinates(): void
    {
        $c = SiteController::coordsFromUrl('https://www.google.com/maps/place/X/@14.5352,120.9816,17z/data=!3m1!4b1!4m6!3m5!8m2!3d14.5351818!4d120.9815994');
        $this->assertSame([14.5351818, 120.9815994], $c);
        $this->assertSame([14.6111, 121.0052], SiteController::coordsFromUrl('https://www.google.com/maps?q=14.6111,121.0052'));
        $this->assertSame([14.61, 121.0], SiteController::coordsFromUrl('14.61, 121.00'));
        $this->assertNull(SiteController::coordsFromUrl('https://www.google.com/maps/place/Nowhere'));

        $hr = User::where('email', 'hr@brite-tsi.com')->firstOrFail();
        $this->actingAs($hr)->getJson('/settings/sites/resolve-link?url=https://www.google.com/maps?q=14.6111,121.0052')
            ->assertOk()->assertJson(['ok' => true, 'lat' => 14.6111, 'lng' => 121.0052]);
        $this->actingAs($hr)->getJson('/settings/sites/resolve-link?url=not-a-link')->assertStatus(422);
    }
}
