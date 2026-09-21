from datetime import date, timedelta

from django.urls import reverse
from django.utils import timezone

from core.models import Notification
from leaveot.models import LeaveRequest, LeaveType, OvertimeRequest

from .base import AppTestCase


def next_weekday(days_ahead=7):
    d = timezone.now().date() + timedelta(days=days_ahead)
    while d.weekday() >= 5:
        d += timedelta(days=1)
    return d


class LeaveTest(AppTestCase):
    def file(self, **over):
        d = next_weekday()
        data = {"leave_type_id": LeaveType.objects.get(code="SIL").id, "day_portion": "full", "date_from": d.isoformat(), "date_to": (d + timedelta(days=1)).isoformat(), "reason": "Family matter"}
        data.update(over)
        return self.client.post(reverse("leave.store"), data)

    def test_employee_files_leave_and_hr_is_notified(self):
        self.login(self.tech)
        r = self.file()
        self.assertRedirects(r, reverse("leave.index"), fetch_redirect_response=False)
        leave = LeaveRequest.objects.get()
        self.assertEqual((leave.status, leave.days), ("pending", 2))
        self.assertTrue(Notification.objects.filter(user=self.hr, kind="request").exists())

    def test_half_day_counts_half(self):
        self.login(self.tech)
        d = next_weekday()
        self.file(day_portion="half_am", date_to=d.isoformat(), date_from=d.isoformat())
        self.assertEqual(float(LeaveRequest.objects.get().days), 0.5)

    def test_validation_errors_are_redisplayed(self):
        self.login(self.tech)
        r = self.file(date_to="2020-01-01")
        self.assertRedirects(r, reverse("leave.create"), fetch_redirect_response=False)
        self.assertEqual(LeaveRequest.objects.count(), 0)
        r = self.client.get(reverse("leave.create"))
        self.assertContains(r, "Family matter")

    def test_hr_approves_then_employee_sees_it(self):
        self.login(self.tech)
        self.file()
        leave = LeaveRequest.objects.get()
        self.login(self.hr)
        r = self.client.post(reverse("leave.approve", args=[leave.id]), {"remarks": "Enjoy"})
        self.assertEqual(r.status_code, 302)
        leave.refresh_from_db()
        self.assertEqual((leave.status, leave.approved_by_id), ("approved", self.hr.id))
        self.assertTrue(Notification.objects.filter(user=self.tech, kind="approved").exists())
        self.login(self.tech)
        self.assertContains(self.client.get(reverse("leave.index")), "Approved")

    def test_employee_cannot_approve(self):
        self.login(self.tech)
        self.file()
        leave = LeaveRequest.objects.get()
        self.assertEqual(self.client.post(reverse("leave.approve", args=[leave.id])).status_code, 403)

    def test_early_out_request(self):
        self.login(self.tech)
        r = self.client.post(reverse("leave.early.store"), {"requested_time_out": "15:00", "reason": "Not feeling well"})
        self.assertRedirects(r, reverse("leave.index"), fetch_redirect_response=False)
        leave = LeaveRequest.objects.get()
        self.assertTrue(leave.is_early_leave)
        self.assertEqual(leave.day_portion, "half_pm")
        self.assertEqual(leave.date_from, timezone.now().date())


class OvertimeTest(AppTestCase):
    def file(self, **over):
        data = {"ot_date": next_weekday().isoformat(), "planned_start": "18:00", "planned_end": "20:00", "reason": "Finish the pull-out at Site A"}
        data.update(over)
        return self.client.post(reverse("overtime.store"), data)

    def test_employee_files_overtime(self):
        self.login(self.tech)
        r = self.file()
        self.assertRedirects(r, reverse("overtime.index"), fetch_redirect_response=False)
        ot = OvertimeRequest.objects.get()
        self.assertEqual((ot.status, float(ot.requested_hours), ot.ot_type), ("pending", 2.0, "regular"))

    def test_weekend_is_rest_day_ot(self):
        self.login(self.tech)
        d = timezone.now().date() + timedelta(days=1)
        while d.weekday() != 6:
            d += timedelta(days=1)
        self.file(ot_date=d.isoformat())
        self.assertEqual(OvertimeRequest.objects.get().ot_type, "rest_day")

    def test_reason_is_required(self):
        self.login(self.tech)
        r = self.file(reason="")
        self.assertRedirects(r, reverse("overtime.create"), fetch_redirect_response=False)
        self.assertEqual(OvertimeRequest.objects.count(), 0)

    def test_late_filing_is_refused(self):
        self.login(self.tech)
        old = timezone.now().date() - timedelta(days=10)
        r = self.file(ot_date=old.isoformat())
        self.assertRedirects(r, reverse("overtime.create"), fetch_redirect_response=False)
        self.assertEqual(OvertimeRequest.objects.count(), 0)

    def test_hr_approves_overtime(self):
        self.login(self.tech)
        self.file()
        ot = OvertimeRequest.objects.get()
        self.login(self.hr)
        self.client.post(reverse("overtime.approve", args=[ot.id]), {"remarks": "Approved"})
        ot.refresh_from_db()
        self.assertEqual(ot.status, "approved")
        self.login(self.tech)
        self.assertContains(self.client.get(reverse("overtime.index")), "Approved")

    def test_hr_denies_overtime(self):
        self.login(self.tech)
        self.file()
        ot = OvertimeRequest.objects.get()
        self.login(self.hr)
        self.client.post(reverse("overtime.deny", args=[ot.id]), {"remarks": "Not needed"})
        ot.refresh_from_db()
        self.assertEqual(ot.status, "denied")
        self.assertTrue(Notification.objects.filter(user=self.tech, kind="rejected").exists())
