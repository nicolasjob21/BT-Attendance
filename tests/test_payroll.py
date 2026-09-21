import io
from datetime import date, datetime, timedelta
from decimal import Decimal

from django.core.management import call_command
from django.urls import reverse
from django.utils import timezone
from openpyxl import load_workbook

from attendance.models import AttendanceLog
from core.models import Notification
from employees.models import Employee
from payroll import rates
from payroll.models import PayrollDeduction, PayrollItem, PayrollPeriod
from payroll.runner import PayrollRunner

from .base import HQ, AppTestCase


def punch_day(employee, d: date, time_in="08:30", time_out="17:30"):
    for kind, t in (("time_in", time_in), ("time_out", time_out)):
        AttendanceLog.objects.create(employee=employee, log_type=kind, logged_at=datetime.combine(d, datetime.strptime(t, "%H:%M").time()), latitude=HQ[0], longitude=HQ[1], location_status="verified_location", within_geofence=True, photo_path="x.jpg")


class PeriodTest(AppTestCase):
    def test_cutoffs_are_fixed_semi_monthly(self):
        p = PayrollPeriod.ensure_for(date(2026, 9, 3))
        self.assertEqual((p.period_start, p.period_end), (date(2026, 9, 1), date(2026, 9, 15)))
        q = p.next()
        self.assertEqual((q.period_start, q.period_end), (date(2026, 9, 16), date(2026, 9, 30)))
        self.assertEqual(q.next().period_start, date(2026, 10, 1))
        self.assertEqual(PayrollPeriod.ensure_for(date(2026, 2, 20)).period_end, date(2026, 2, 28))

    def test_ensure_for_is_idempotent(self):
        PayrollPeriod.ensure_for(date(2026, 9, 3))
        PayrollPeriod.ensure_for(date(2026, 9, 10))
        self.assertEqual(PayrollPeriod.objects.count(), 1)


class RunnerTest(AppTestCase):
    def test_roll_forward_fills_the_gap_up_to_the_next_cutoff(self):
        PayrollPeriod.ensure_for(date(2026, 7, 1))
        PayrollRunner().roll_forward(datetime(2026, 9, 20, 7, 0))
        ends = list(PayrollPeriod.objects.order_by("period_end").values_list("period_end", flat=True))
        self.assertEqual(ends[0], date(2026, 7, 15))
        self.assertIn(date(2026, 9, 30), ends)
        self.assertIn(date(2026, 8, 31), ends)

    def test_pay_day_reminder_goes_out_once_on_the_morning_of_pay_day(self):
        runner = PayrollRunner()
        pay_day = datetime(2026, 9, 15, 7, 0)
        result = runner.tick(pay_day)
        self.assertEqual(len(result["reminded"]), 1)
        self.assertTrue(Notification.objects.filter(user=self.admin, kind="payroll").exists())
        self.assertTrue(Notification.objects.filter(user=self.hr, kind="payroll").exists())
        self.assertFalse(Notification.objects.filter(user=self.tech, kind="payroll").exists())
        again = runner.tick(pay_day + timedelta(hours=2))
        self.assertEqual(again["reminded"], [])

    def test_status_tells_the_admin_what_payroll_needs_right_now(self):
        runner = PayrollRunner()
        self.assertEqual(runner.status(datetime(2026, 9, 3))["state"], "upcoming")
        runner.roll_forward(datetime(2026, 9, 15))
        self.assertEqual(runner.status(datetime(2026, 9, 15))["state"], "due")
        self.assertEqual(runner.status(datetime(2026, 9, 18))["state"], "overdue")
        period = PayrollPeriod.objects.get(period_end=date(2026, 9, 15))
        runner.generate(period, by="test")
        self.assertEqual(runner.status(datetime(2026, 9, 18))["state"], "computed")
        period.released_at, period.status = timezone.now(), "released"
        period.save()
        s = runner.status(datetime(2026, 9, 18))
        self.assertEqual((s["state"], s["pay_date"]), ("upcoming", date(2026, 9, 30)))

    def test_management_command(self):
        out = io.StringIO()
        call_command("payroll_tick", stdout=out)
        self.assertTrue(PayrollPeriod.objects.exists())


class CalculatorTest(AppTestCase):
    def setUp(self):
        self.period = PayrollPeriod.ensure_for(date(2026, 9, 1))  # Sep 1–15, 2026 (11 working days)

    def run_payroll(self):
        self.login(self.admin)
        return self.client.post(reverse("payroll.generate", args=[self.period.id]))

    def test_hr_can_run_but_not_release_or_edit(self):
        self.login(self.hr)
        self.assertEqual(self.client.post(reverse("payroll.generate", args=[self.period.id])).status_code, 302)
        self.assertEqual(self.client.post(reverse("payroll.release", args=[self.period.id])).status_code, 403)
        line = self.period.items.get(employee=self.employee)
        self.assertEqual(self.client.get(reverse("payroll.lines.edit", args=[line.id])).status_code, 403)

    def test_generate_creates_a_line_per_active_employee(self):
        r = self.run_payroll()
        self.assertRedirects(r, f"{reverse('payroll.index')}?period={self.period.id}", fetch_redirect_response=False)
        self.period.refresh_from_db()
        self.assertEqual(self.period.status, "processing")
        self.assertEqual(self.period.items.count(), Employee.objects.active().count())
        line = self.period.items.get(employee=self.employee)
        self.assertEqual(float(line.basic_pay), 12500.0)   # 25 000 × 50 %
        self.assertEqual(float(line.absences_deduction), 0.0)  # no attendance captured → nothing zeroed
        self.assertGreater(float(line.sss_deduction), 0)
        self.assertEqual(float(line.net_pay), float(line.gross_pay - line.total_deductions))

    def test_absences_are_deducted_once_attendance_exists(self):
        for d in (date(2026, 9, 1), date(2026, 9, 2), date(2026, 9, 3)):
            punch_day(self.employee, d)
        self.run_payroll()
        line = self.period.items.get(employee=self.employee)
        daily = float(self.employee.daily_rate)
        self.assertEqual(float(line.absences_deduction), round((11 - 3) * daily, 2))

    def test_late_arrival_is_deducted_per_minute_after_grace(self):
        punch_day(self.employee, date(2026, 9, 1), time_in="09:00")  # 30 min late, 15 min grace
        self.run_payroll()
        line = self.period.items.get(employee=self.employee)
        self.assertGreater(float(line.late_undertime_deduction), 0)

    def test_hand_edited_line_survives_a_rerun_unless_forced(self):
        self.run_payroll()
        line = self.period.items.get(employee=self.employee)
        data = {f: str(getattr(line, f)) for f in PayrollItem.EDITABLE}
        data["allowances"] = "500"
        data["remarks"] = "Transport allowance"
        r = self.client.post(reverse("payroll.lines.update", args=[line.id]), data)
        self.assertEqual(r.status_code, 302)
        line.refresh_from_db()
        self.assertEqual(float(line.allowances), 500.0)
        self.assertTrue(line.is_adjusted)
        self.client.post(reverse("payroll.generate", args=[self.period.id]))
        line.refresh_from_db()
        self.assertEqual(float(line.allowances), 500.0)
        self.client.post(reverse("payroll.lines.reset", args=[line.id]))
        line.refresh_from_db()
        self.assertEqual(float(line.allowances), 500.0)  # hand-entered allowances survive; attendance figures recompute
        self.assertFalse(line.is_adjusted)

    def test_release_issues_payslips_and_notifies_employees(self):
        self.run_payroll()
        r = self.client.post(reverse("payroll.release", args=[self.period.id]))
        self.assertEqual(r.status_code, 302)
        self.period.refresh_from_db()
        self.assertIsNotNone(self.period.released_at)
        self.assertTrue(self.period.is_closed)  # releasing closes the period: the figures are final
        self.assertTrue(Notification.objects.filter(user=self.tech, kind="payroll").exists())
        self.login(self.tech)
        r = self.client.get(reverse("payroll.mine"))
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "Sep 1")

    def test_close_locks_editing(self):
        self.run_payroll()
        self.client.post(reverse("payroll.release", args=[self.period.id]))
        self.client.post(reverse("payroll.close", args=[self.period.id]))
        self.period.refresh_from_db()
        self.assertTrue(self.period.is_closed)
        line = self.period.items.get(employee=self.employee)
        self.client.post(reverse("payroll.lines.update", args=[line.id]), {f: "1" for f in PayrollItem.EDITABLE})
        line.refresh_from_db()
        self.assertNotEqual(float(line.basic_pay), 1.0)

    def test_export_and_print(self):
        self.run_payroll()
        r = self.client.get(reverse("payroll.export", args=[self.period.id]))
        self.assertEqual(r.status_code, 200)
        wb = load_workbook(io.BytesIO(r.content))
        self.assertGreaterEqual(wb.active.max_row, 2)
        r = self.client.get(reverse("payroll.print", args=[self.period.id]))
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "Payslip")
        item = self.period.items.get(employee=self.employee)
        self.assertEqual(self.client.get(reverse("payroll.show", args=[item.id])).status_code, 200)

    def test_employee_cannot_run_payroll(self):
        self.login(self.tech)
        self.assertEqual(self.client.post(reverse("payroll.generate", args=[self.period.id])).status_code, 403)
        self.assertEqual(self.client.get(reverse("payroll.index")).status_code, 403)


class RatesTest(AppTestCase):
    def test_update_rates(self):
        self.login(self.admin)
        data = {f"rates[{k}]": v for k, v in rates.DEFAULTS.items()}
        data["rates[allowance_percent]"] = "10"
        r = self.client.post(reverse("payroll.rates.update"), data)
        self.assertRedirects(r, reverse("payroll.rates"), fetch_redirect_response=False)
        self.assertEqual(rates.get("allowance_percent"), 10.0)
        period = PayrollPeriod.ensure_for(date(2026, 9, 1))
        PayrollRunner().generate(period, "test")
        line = period.items.get(employee=self.employee)
        self.assertEqual(float(line.allowances), 1250.0)

    def test_out_of_range_rate_is_refused(self):
        self.login(self.admin)
        data = {f"rates[{k}]": v for k, v in rates.DEFAULTS.items()}
        data["rates[ot_regular_percent]"] = "50"
        self.client.post(reverse("payroll.rates.update"), data)
        self.assertEqual(rates.get("ot_regular_percent"), 125.0)


class DeductionTest(AppTestCase):
    def setUp(self):
        self.period = PayrollPeriod.ensure_for(date(2026, 9, 1))
        self.login(self.admin)

    def test_loan_is_collected_across_cutoffs(self):
        r = self.client.post(reverse("payroll.deductions.store"), {"type": "loan", "description": "Cash advance", "total_amount": "3000", "cutoffs": "3", "starts_on": "2026-09-01", "employee_id": self.employee.id})
        self.assertEqual(r.status_code, 302)
        loan = PayrollDeduction.objects.get()
        self.assertEqual((loan.type, float(loan.installment_amount), float(loan.balance)), ("loan", 1000.0, 3000.0))
        PayrollRunner().generate(self.period, "test")
        line = self.period.items.get(employee=self.employee)
        self.assertEqual(float(line.loan_deduction), 1000.0)
        loan.refresh_from_db()
        self.assertEqual(float(loan.balance), 2000.0)
        # a re-run reverses and re-collects instead of double-charging
        PayrollRunner().generate(self.period, "test", force=True)
        loan.refresh_from_db()
        self.assertEqual(float(loan.balance), 2000.0)

    def test_missing_item_is_split_across_the_people_responsible(self):
        other = Employee.objects.get(user=self.dev)
        r = self.client.post(reverse("payroll.deductions.store"), {"type": "missing_item", "description": "Lost drill", "total_amount": "5000", "cutoffs": "2", "starts_on": "2026-09-01", "site_id": self.project.id, "employees": [self.employee.id, other.id]})
        self.assertEqual(r.status_code, 302)
        rows = PayrollDeduction.objects.filter(type="missing_item").order_by("employee_id")
        self.assertEqual(rows.count(), 2)
        self.assertEqual([float(d.total_amount) for d in rows], [2500.0, 2500.0])
        self.assertEqual([float(d.installment_amount) for d in rows], [1250.0, 1250.0])

    def test_validation(self):
        self.client.post(reverse("payroll.deductions.store"), {"type": "loan", "description": "", "total_amount": "0", "cutoffs": "3", "starts_on": "", "employee_id": ""})
        self.assertEqual(PayrollDeduction.objects.count(), 0)
        r = self.client.get(reverse("payroll.deductions"))
        self.assertContains(r, "Describe it")

    def test_cancel_stops_further_collection(self):
        self.client.post(reverse("payroll.deductions.store"), {"type": "loan", "description": "Cash advance", "total_amount": "3000", "cutoffs": "3", "starts_on": "2026-09-01", "employee_id": self.employee.id})
        loan = PayrollDeduction.objects.get()
        r = self.client.post(reverse("payroll.deductions.cancel", args=[loan.id]), {"reason": "Paid in cash"})
        self.assertEqual(r.status_code, 302)
        loan.refresh_from_db()
        self.assertEqual(loan.status, "cancelled")
        PayrollRunner().generate(self.period, "test")
        self.assertEqual(float(self.period.items.get(employee=self.employee).loan_deduction), 0.0)

    def test_salary_history_page(self):
        r = self.client.get(reverse("employees.salary-history", args=[self.employee.id]))
        self.assertEqual(r.status_code, 200)
