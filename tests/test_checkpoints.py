import io
from datetime import datetime, timedelta

from django.core.management import call_command
from django.urls import reverse
from django.utils import timezone

from checkpoints.models import Checkpoint, CheckpointAuditLog, CheckpointCampaign
from checkpoints.services import CampaignManager, CheckpointDispatcher
from core.models import Notification
from employees.models import Employee

from .base import FAR_AWAY, AppTestCase, data_url

PROJECT = (14.6760, 121.0437)   # seeded Project Site A


class CampaignLifecycleTest(AppTestCase):
    def create_draft(self, **over):
        self.login(self.hr)
        data = {"name": "Morning headcount", "project_site_id": self.project.id, "instruction": "Take a selfie at the site gate", "employees": [self.employee.id], "response_window_minutes": "10"}
        data.update(over)
        return self.client.post(reverse("checkpoints.store"), data)

    def test_store_creates_a_draft_with_participants(self):
        r = self.create_draft()
        c = CheckpointCampaign.objects.get()
        self.assertRedirects(r, reverse("checkpoints.show", args=[c.id]), fetch_redirect_response=False)
        self.assertEqual((c.status, c.participants.count(), c.created_by_id), ("draft", 1, self.hr.id))
        self.assertTrue(CheckpointAuditLog.objects.filter(campaign=c, action="created").exists())

    def test_validation_bounces_back(self):
        r = self.create_draft(employees=[], instruction="")
        self.assertRedirects(r, reverse("checkpoints.create"), fetch_redirect_response=False)
        self.assertEqual(CheckpointCampaign.objects.count(), 0)
        self.assertContains(self.client.get(reverse("checkpoints.create")), "Morning headcount")

    def test_activate_notifies_every_participant(self):
        self.create_draft()
        c = CheckpointCampaign.objects.get()
        r = self.client.post(reverse("checkpoints.activate", args=[c.id]))
        self.assertEqual(r.status_code, 302)
        c.refresh_from_db()
        self.assertEqual(c.status, "active")
        self.assertEqual(c.expires_at - c.starts_at, timedelta(minutes=10))
        cp = Checkpoint.objects.get(campaign=c, employee=self.employee)
        self.assertEqual(cp.status, "notified")
        self.assertTrue(Notification.objects.filter(user=self.tech, kind="checkpoint").exists())

    def test_schedule_then_dispatcher_starts_it(self):
        self.create_draft()
        c = CheckpointCampaign.objects.get()
        at = (timezone.now() + timedelta(hours=1)).replace(second=0, microsecond=0)
        self.client.post(reverse("checkpoints.schedule", args=[c.id]), {"scheduled_start_at": at.strftime("%Y-%m-%dT%H:%M")})
        c.refresh_from_db()
        self.assertEqual((c.status, c.scheduled_start_at, c.schedule_mode), ("draft", at, "manual"))  # stays a draft until the dispatcher starts it
        CheckpointDispatcher().tick(at - timedelta(minutes=1))
        c.refresh_from_db()
        self.assertEqual(c.status, "draft")
        CheckpointDispatcher().tick(at + timedelta(seconds=5))
        c.refresh_from_db()
        self.assertEqual(c.status, "active")
        self.assertTrue(CheckpointAuditLog.objects.filter(campaign=c, action="auto_started").exists())

    def test_schedule_random_picks_a_time_inside_the_window(self):
        self.create_draft()
        c = CheckpointCampaign.objects.get()
        day = (timezone.now() + timedelta(days=1)).date()
        r = self.client.post(reverse("checkpoints.schedule-random", args=[c.id]), {"date": day.isoformat(), "window_start": "09:00", "window_end": "11:00"})
        self.assertEqual(r.status_code, 302)
        c.refresh_from_db()
        self.assertEqual(c.schedule_mode, "random")
        self.assertTrue(datetime.combine(day, datetime.strptime("09:00", "%H:%M").time()) <= c.scheduled_start_at <= datetime.combine(day, datetime.strptime("11:00", "%H:%M").time()))

    def test_pause_resume_and_end(self):
        self.create_draft()
        c = CheckpointCampaign.objects.get()
        self.client.post(reverse("checkpoints.activate", args=[c.id]))
        self.client.post(reverse("checkpoints.pause", args=[c.id]), {"reason": "Site alarm"})
        c.refresh_from_db()
        self.assertEqual(c.status, "paused")
        self.client.post(reverse("checkpoints.resume", args=[c.id]))
        c.refresh_from_db()
        self.assertEqual(c.status, "active")
        self.client.post(reverse("checkpoints.end", args=[c.id]))
        c.refresh_from_db()
        self.assertEqual(c.status, "expired")
        cp = Checkpoint.objects.get(campaign=c)
        self.assertEqual(cp.status, "missed")

    def test_expiry_marks_non_responders_missed(self):
        self.create_draft()
        c = CheckpointCampaign.objects.get()
        self.client.post(reverse("checkpoints.activate", args=[c.id]))
        c.refresh_from_db()
        CheckpointDispatcher().tick(c.expires_at + timedelta(minutes=1))
        cp = Checkpoint.objects.get(campaign=c)
        self.assertEqual(cp.status, "missed")
        self.assertEqual(cp.failure_reason, "no_response")

    def test_cancel_a_draft(self):
        self.create_draft()
        c = CheckpointCampaign.objects.get()
        self.client.post(reverse("checkpoints.cancel", args=[c.id]), {"reason": "Wrong site"})
        c.refresh_from_db()
        self.assertEqual(c.status, "cancelled")

    def test_only_a_draft_can_be_edited(self):
        self.create_draft()
        c = CheckpointCampaign.objects.get()
        self.assertEqual(self.client.get(reverse("checkpoints.edit", args=[c.id])).status_code, 200)
        r = self.client.post(reverse("checkpoints.update", args=[c.id]), {"name": "Renamed", "project_site_id": self.project.id, "instruction": "Selfie", "employees": [self.employee.id], "response_window_minutes": "15"})
        c.refresh_from_db()
        self.assertEqual((c.name, c.response_window_minutes), ("Renamed", 15))
        self.client.post(reverse("checkpoints.activate", args=[c.id]))
        self.assertEqual(self.client.get(reverse("checkpoints.edit", args=[c.id])).status_code, 302)

    def test_employee_cannot_create_campaigns(self):
        self.login(self.tech)
        self.assertEqual(self.client.get(reverse("checkpoints.create")).status_code, 403)

    def test_export_and_management_command(self):
        self.create_draft()
        c = CheckpointCampaign.objects.get()
        self.client.post(reverse("checkpoints.activate", args=[c.id]))
        r = self.client.get(reverse("checkpoints.export", args=[c.id]))
        self.assertEqual(r.status_code, 200)
        self.assertIn("text/csv", r["Content-Type"])
        self.assertIn(self.employee.last_name, r.content.decode())
        call_command("checkpoints_tick", stdout=io.StringIO())


class EmployeeResponseTest(AppTestCase):
    def setUp(self):
        self.campaign = CampaignManager().create({"name": "Headcount", "project_site": self.project, "instruction": "Selfie at gate", "response_window_minutes": 10}, [self.employee.id], self.hr)
        CampaignManager().activate(self.campaign, self.hr)
        self.cp = Checkpoint.objects.get(campaign=self.campaign, employee=self.employee)
        self.login(self.tech)

    def submit(self, coords=PROJECT, accuracy=15, **extra):
        data = {"latitude": coords[0], "longitude": coords[1], "gps_accuracy": accuracy, "photo": data_url(), "client_timestamp": timezone.now().isoformat(), "network_status": "online"}
        data.update(extra)
        return self.client.post(reverse("my-checkpoints.submit", args=[self.cp.id]), data)

    def test_my_checkpoints_lists_the_open_one(self):
        r = self.client.get(reverse("my-checkpoints.index"))
        self.assertContains(r, "Live presence checkpoint active")
        r = self.client.get(reverse("my-checkpoints.show", args=[self.cp.id]))
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "Selfie at gate")

    def test_inside_the_site_geofence_is_verified_presence(self):
        r = self.submit()
        self.assertRedirects(r, reverse("my-checkpoints.show", args=[self.cp.id]), fetch_redirect_response=False)
        self.cp.refresh_from_db()
        self.assertEqual((self.cp.status, self.cp.verification_result), ("responded", "completed"))
        self.assertTrue(self.cp.within_geofence)
        self.assertTrue(self.cp.photo_path)
        self.login(self.hr)
        r = self.client.get(reverse("checkpoints.photo", args=[self.cp.id]))
        self.assertEqual(r.status_code, 200)
        self.assertEqual(r["Content-Type"], "image/jpeg")

    def test_low_accuracy_inside_the_fence_still_completes(self):
        self.submit(accuracy=250)
        self.cp.refresh_from_db()
        self.assertEqual((self.cp.status, self.cp.verification_result), ("responded", "completed_with_low_gps_accuracy"))

    def test_outside_the_fence_is_a_failed_attempt_that_can_be_retried(self):
        self.submit(coords=FAR_AWAY)
        self.cp.refresh_from_db()
        self.assertEqual((self.cp.status, self.cp.failure_reason, self.cp.submission_attempts), ("outside_geofence", "outside_geofence", 1))
        self.submit()
        self.cp.refresh_from_db()
        self.assertEqual((self.cp.status, self.cp.submission_attempts), ("responded", 2))

    def test_duplicate_submission_is_refused(self):
        self.submit()
        self.submit()
        self.cp.refresh_from_db()
        self.assertEqual(self.cp.submission_attempts, 1)
        self.assertTrue(CheckpointAuditLog.objects.filter(checkpoint=self.cp, action="rejected_submission").exists())

    def test_photo_is_required(self):
        self.submit(photo="")
        self.cp.refresh_from_db()
        self.assertEqual(self.cp.status, "notified")

    def test_late_submission_is_recorded_as_expired(self):
        self.campaign.expires_at = timezone.now() - timedelta(minutes=1)
        self.campaign.save()
        self.submit()
        self.cp.refresh_from_db()
        self.assertEqual(self.cp.last_attempt_result, "checkpoint_expired")
        self.assertFalse(self.cp.is_completed)

    def test_other_employee_cannot_submit_for_me(self):
        self.login(self.dev)
        self.assertEqual(self.submit().status_code, 403)

    def test_report_an_issue(self):
        r = self.client.post(reverse("my-checkpoints.issue", args=[self.cp.id]), {"issue": "gps_unavailable"}, HTTP_X_REQUESTED_WITH="XMLHttpRequest")
        self.assertEqual(r.status_code, 200)
        self.cp.refresh_from_db()
        self.assertEqual((self.cp.status, self.cp.issue_reported), ("gps_unavailable", "gps_unavailable"))

    def test_employee_explains_a_missed_checkpoint(self):
        CheckpointDispatcher().expire_campaign(self.campaign, self.campaign.expires_at + timedelta(minutes=1))
        r = self.client.post(reverse("my-checkpoints.explain", args=[self.cp.id]), {"issue": "no_internet", "employee_explanation": "Signal dropped inside the vault"})
        self.assertEqual(r.status_code, 302)
        self.cp.refresh_from_db()
        self.assertEqual(self.cp.employee_explanation, "Signal dropped inside the vault")


class HrReviewTest(AppTestCase):
    def setUp(self):
        self.campaign = CampaignManager().create({"name": "Headcount", "project_site": self.project, "instruction": "Selfie at gate", "response_window_minutes": 10}, [self.employee.id], self.hr)
        CampaignManager().activate(self.campaign, self.hr)
        self.cp = Checkpoint.objects.get(campaign=self.campaign)
        CheckpointDispatcher().expire_campaign(self.campaign, self.campaign.expires_at + timedelta(minutes=1))
        self.cp.refresh_from_db()
        self.login(self.hr)

    def follow_up(self, **data):
        return self.client.post(reverse("checkpoints.results.follow-up", args=[self.cp.id]), data)

    def test_results_pages(self):
        self.assertContains(self.client.get(reverse("checkpoints.results.index")), self.employee.full_name)
        self.assertContains(self.client.get(reverse("checkpoints.results.show", args=[self.cp.id])), "Missed")
        self.assertEqual(self.client.get(reverse("checkpoints.daily")).status_code, 200)
        self.assertEqual(self.client.get(reverse("checkpoints.history")).status_code, 200)

    def test_approve_exception_counts_as_completed_after_review(self):
        r = self.follow_up(action="approve", reason="work_related", note="Was at the client office")
        self.assertEqual(r.status_code, 302)
        self.cp.refresh_from_db()
        self.assertEqual((self.cp.status, self.cp.verification_result, self.cp.hr_reason), ("approved_exception", "completed_after_review", "work_related"))
        self.assertTrue(self.cp.is_completed)
        self.assertTrue(Notification.objects.filter(user=self.tech, kind="approved").exists())

    def test_reject_needs_a_reason(self):
        self.follow_up(action="reject")
        self.cp.refresh_from_db()
        self.assertEqual(self.cp.status, "missed")
        self.follow_up(action="reject", reason="ignored")
        self.cp.refresh_from_db()
        self.assertEqual(self.cp.status, "rejected_exception")

    def test_notes_and_escalation(self):
        self.follow_up(action="note", note="Called the TL")
        self.follow_up(action="escalate", note="Second miss this week")
        self.cp.refresh_from_db()
        self.assertEqual(self.cp.hr_note, "Second miss this week")  # latest note wins; the audit trail keeps every one
        self.assertTrue(CheckpointAuditLog.objects.filter(checkpoint=self.cp, action="note_added").exists())
        self.assertTrue(self.cp.escalated_at)
        self.assertTrue(CheckpointAuditLog.objects.filter(checkpoint=self.cp, action="escalated").exists())

    def test_settings_update(self):
        r = self.client.post(reverse("checkpoints.settings.update"), {"response_window_minutes": "20", "instructions": "Selfie at the gate\nPhoto of the site board"})
        self.assertEqual(r.status_code, 302)
        r = self.client.get(reverse("checkpoints.create"))
        self.assertContains(r, "Photo of the site board")

    def test_employee_cannot_open_results(self):
        self.login(self.tech)
        self.assertEqual(self.client.get(reverse("checkpoints.results.index")).status_code, 403)
