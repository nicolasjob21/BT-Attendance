from datetime import timedelta

from django.urls import reverse
from django.utils import timezone

from attendance.models import AttendanceLog
from core.models import Notification

from .base import FAR_AWAY, HQ, AppTestCase, data_url


class ClockTest(AppTestCase):
    def punch(self, log_type, coords=HQ, **extra):
        data = {"log_type": log_type, "latitude": coords[0], "longitude": coords[1], "gps_accuracy": 12, "photo": data_url(), **extra}
        return self.client.post(reverse("attendance.store"), data)

    def test_clock_in_inside_the_geofence_is_verified(self):
        self.login(self.tech)
        r = self.punch("time_in")
        self.assertRedirects(r, reverse("attendance.index"), fetch_redirect_response=False)
        log = AttendanceLog.objects.get(employee=self.employee)
        self.assertEqual((log.log_type, log.location_status, log.site_id), ("time_in", "verified_location", self.hq.id))
        self.assertTrue(log.photo_path.startswith(f"attendance/{self.employee.id}/"))
        self.assertIsNone(log.location_verification_status)
        r = self.client.get(reverse("attendance.index"))
        self.assertContains(r, "Clocked in at")

    def test_selfie_is_required(self):
        self.login(self.tech)
        r = self.punch("time_in", photo="")
        self.assertRedirects(r, reverse("attendance.create"), fetch_redirect_response=False)
        self.assertEqual(AttendanceLog.objects.count(), 0)
        self.assertContains(self.client.get(reverse("attendance.create")), "Take a selfie")

    def test_outside_the_geofence_needs_a_reason_and_notifies_hr(self):
        self.login(self.tech)
        r = self.punch("time_in", coords=FAR_AWAY)
        self.assertRedirects(r, reverse("attendance.create"), fetch_redirect_response=False)
        self.assertEqual(AttendanceLog.objects.count(), 0)
        r = self.punch("time_in", coords=FAR_AWAY, location_reason="Client visit in Cebu")
        log = AttendanceLog.objects.get()
        self.assertEqual(log.location_status, "outside_authorized_area")
        self.assertEqual(log.location_reason, "Client visit in Cebu")
        self.assertTrue(Notification.objects.filter(user=self.hr, kind="attendance").exists())

    def test_clock_out_and_soft_copy_download(self):
        self.login(self.tech)
        self.punch("time_in")
        self.punch("time_out")
        logs = list(AttendanceLog.objects.order_by("id"))
        self.assertEqual([l.log_type for l in logs], ["time_in", "time_out"])
        r = self.client.get(reverse("attendance.softcopy", args=[logs[1].id, "out"]))
        self.assertEqual(r.status_code, 200)
        self.assertEqual(r["Content-Type"], "image/png")
        self.assertTrue(r.content.startswith(b"\x89PNG"))

    def test_another_employee_cannot_download_my_soft_copy(self):
        self.login(self.tech)
        self.punch("time_in")
        log = AttendanceLog.objects.get()
        self.login(self.dev)
        # developer holds "view team reports" so may open it; strip to an employee-only user
        self.dev.set_role("employee")
        self.assertEqual(self.client.get(reverse("attendance.softcopy", args=[log.id, "in"])).status_code, 403)


class MonitorTest(AppTestCase):
    def setUp(self):
        now = timezone.now()
        AttendanceLog.objects.create(employee=self.employee, log_type="time_in", logged_at=now.replace(hour=8, minute=25), latitude=HQ[0], longitude=HQ[1], location_status="outside_authorized_area", location_verification_status="pending", location_reason="GPS drift", photo_path="x.jpg")
        AttendanceLog.objects.create(employee=self.employee, log_type="time_out", logged_at=now.replace(hour=22, minute=0), latitude=HQ[0], longitude=HQ[1], location_status="verified_location", site=self.hq, photo_path="x.jpg")

    def test_monitor_lists_present_employees(self):
        self.login(self.hr)
        r = self.client.get(reverse("attendance.monitor"))
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, self.employee.full_name)
        self.assertContains(r, "Location pending")

    def test_hr_approves_a_location_exception(self):
        self.login(self.hr)
        log = AttendanceLog.objects.get(log_type="time_in")
        r = self.client.post(reverse("attendance.verify-location", args=[log.id]), {"decision": "approved", "remarks": "OK, confirmed with TL"})
        self.assertEqual(r.status_code, 302)
        log.refresh_from_db()
        self.assertEqual(log.location_verification_status, "approved")
        self.assertTrue(Notification.objects.filter(user=self.tech, kind="approved").exists())

    def test_long_day_needs_ot_verification(self):
        self.login(self.hr)
        out = AttendanceLog.objects.get(log_type="time_out")
        r = self.client.post(reverse("attendance.verify", args=[out.id]), {"decision": "rejected", "remarks": "Forgot to clock out"})
        self.assertEqual(r.status_code, 302)
        out.refresh_from_db()
        self.assertEqual(out.ot_verification_status, "rejected")

    def test_timesheet_page(self):
        self.login(self.hr)
        r = self.client.get(reverse("attendance.timesheet", args=[self.employee.id]))
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, self.employee.full_name)

    def test_employee_cannot_open_monitor(self):
        self.login(self.tech)
        self.assertEqual(self.client.get(reverse("attendance.monitor")).status_code, 403)


class MonitorHtmxTest(AppTestCase):
    """The Attendance Log answers htmx with fragments; the same views, same permission checks."""

    HX = {"HTTP_HX_REQUEST": "true"}

    def setUp(self):
        now = timezone.now()
        self.day = now.date().isoformat()
        self.time_in = AttendanceLog.objects.create(employee=self.employee, log_type="time_in", logged_at=now.replace(hour=8, minute=25), latitude=HQ[0], longitude=HQ[1], location_status="outside_authorized_area", location_verification_status="pending", location_reason="GPS drift", photo_path="x.jpg")
        self.time_out = AttendanceLog.objects.create(employee=self.employee, log_type="time_out", logged_at=now.replace(hour=22, minute=0), latitude=HQ[0], longitude=HQ[1], location_status="verified_location", site=self.hq, photo_path="x.jpg")

    def test_filter_request_gets_the_results_fragment_and_the_stepper_out_of_band(self):
        self.login(self.hr)
        r = self.client.get(reverse("attendance.monitor"), {"date": self.day, "search": self.employee.first_name}, **self.HX)
        self.assertEqual(r.status_code, 200)
        self.assertNotContains(r, "<html")
        self.assertNotContains(r, "monitor-filters")  # the form itself is never re-sent
        self.assertContains(r, f'id="monitor-row-{self.employee.id}"')
        self.assertContains(r, 'id="monitor-stepper" hx-swap-oob="true"')
        self.assertContains(r, 'id="monitor-stats"')
        self.assertContains(r, 'id="flash" hx-swap-oob="true"')

    def test_history_restore_gets_the_whole_page(self):
        self.login(self.hr)
        r = self.client.get(reverse("attendance.monitor"), **self.HX, HTTP_HX_HISTORY_RESTORE_REQUEST="true")
        self.assertContains(r, "<html")
        self.assertContains(r, 'id="monitor-filters"')

    def test_full_page_never_marks_the_stepper_out_of_band(self):
        self.login(self.hr)
        r = self.client.get(reverse("attendance.monitor"))
        self.assertContains(r, 'id="monitor-stepper"')
        self.assertNotContains(r, 'hx-swap-oob')

    def test_location_decision_swaps_the_row_and_the_counts(self):
        self.login(self.hr)
        r = self.client.post(reverse("attendance.verify-location", args=[self.time_in.id]), {"decision": "approved", "remarks": "Confirmed with TL", "date": self.day, "search": ""}, **self.HX)
        self.assertEqual(r.status_code, 200)
        self.assertNotContains(r, "<html")
        self.assertContains(r, f'<tr id="monitor-row-{self.employee.id}"')
        self.assertContains(r, "Outside area · approved")
        self.assertContains(r, "Confirmed with TL")
        self.assertContains(r, 'id="monitor-stats" hx-swap-oob="true"')
        self.assertContains(r, "Location approved for")  # flash rides along
        self.time_in.refresh_from_db()
        self.assertEqual(self.time_in.location_verification_status, "approved")
        self.assertTrue(Notification.objects.filter(user=self.tech, kind="approved").exists())

    def test_ot_decision_swaps_the_row(self):
        self.login(self.hr)
        r = self.client.post(reverse("attendance.verify", args=[self.time_out.id]), {"decision": "rejected", "remarks": "Forgot to clock out", "date": self.day, "search": ""}, **self.HX)
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "OT rejected")
        self.assertContains(r, "Forgot to clock out")
        self.assertContains(r, "Overtime rejected for")
        self.time_out.refresh_from_db()
        self.assertEqual(self.time_out.ot_verification_status, "rejected")

    def test_invalid_decision_keeps_the_form_open_with_the_error_inline(self):
        self.login(self.hr)
        r = self.client.post(reverse("attendance.verify", args=[self.time_out.id]), {"decision": "approved", "remarks": "", "date": self.day, "search": ""}, **self.HX)
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "Choose a decision and give a reason.")
        self.assertContains(r, f'<tr id="monitor-row-{self.employee.id}"')
        self.assertContains(r, 'name="remarks"')
        self.time_out.refresh_from_db()
        self.assertIsNone(self.time_out.ot_verification_status)

    def test_row_is_rebuilt_even_when_the_search_no_longer_matches(self):
        self.login(self.hr)
        r = self.client.post(reverse("attendance.verify-location", args=[self.time_in.id]), {"decision": "rejected", "date": self.day, "search": "zzz-nobody"}, **self.HX)
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, f'<tr id="monitor-row-{self.employee.id}"')
        self.assertContains(r, "Outside area · rejected")

    def test_fragment_paths_keep_the_permission_checks(self):
        self.login(self.tech)  # employee: no "view team reports", no "approve requests"
        self.assertEqual(self.client.get(reverse("attendance.monitor"), **self.HX).status_code, 403)
        r = self.client.post(reverse("attendance.verify-location", args=[self.time_in.id]), {"decision": "approved", "date": self.day}, **self.HX)
        self.assertEqual(r.status_code, 403)
        self.time_in.refresh_from_db()
        self.assertEqual(self.time_in.location_verification_status, "pending")

    def test_expired_session_becomes_a_client_side_redirect(self):
        r = self.client.get(reverse("attendance.monitor"), **self.HX)
        self.assertEqual(r.status_code, 200)
        self.assertTrue(r["HX-Redirect"].startswith(reverse("login")))
        self.assertEqual(r.content, b"")
