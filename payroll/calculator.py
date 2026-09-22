"""
One employee's payroll line for a period. The rules live here so the payroll
page, the runner and the tests share one implementation.
"""

from collections import defaultdict
from datetime import datetime, timedelta
from decimal import Decimal

from django.db import transaction
from django.db.models import Sum
from django.utils import timezone

from core.support import dec
from leaveot.models import LeaveRequest, OvertimeRequest
from leaveot.overtime import sync_actual_hours

from . import rates
from .models import ContributionRate, PayrollDeduction, PayrollDeductionPayment, PayrollItem, PayrollPeriod


def _r2(v) -> float:
    return float(dec(v))


class PayrollCalculator:
    def calculate(self, employee, period: PayrollPeriod, force: bool = False) -> PayrollItem:
        # A line HR edited by hand is never silently overwritten by a (re)run.
        existing = PayrollItem.objects.filter(employee=employee, payroll_period=period).first()
        if existing and existing.is_adjusted and not force:
            return existing

        monthly = float(employee.monthly_salary)
        daily = float(employee.daily_rate) or (monthly / max(1, rates.get("working_days_per_month")))
        hourly = daily / max(1, rates.get("hours_per_day"))

        basic_pay = _r2(monthly * rates.get("basic_cutoff_percent") / 100)

        start_dt = datetime.combine(period.period_start, datetime.min.time())
        end_dt = datetime.combine(period.period_end, datetime.max.time())
        logs = list(employee.attendance_logs.filter(logged_at__range=(start_dt, end_dt)).order_by("logged_at"))
        has_attendance = bool(logs)

        # ---- attendance: expected vs worked days ----
        expected_days = self.working_days(period)
        worked_days = len({l.logged_at.date() for l in logs if l.log_type == "time_in"})
        approved_leave_days = float(
            employee.leave_requests.filter(status="approved", day_portion="full", date_from__range=(period.period_start, period.period_end)).aggregate(s=Sum("days"))["s"] or 0
        )
        absences_deduction = 0.0
        if has_attendance:  # never zero a period with no data captured yet
            absent_days = max(0, expected_days - worked_days - approved_leave_days)
            absences_deduction = _r2(absent_days * daily)

        # ---- late / undertime (fixed schedule) ----
        late_undertime = self.late_undertime_deduction(employee, period, hourly, logs) if has_attendance else 0.0

        # ---- half-day leave: each APPROVED half day withholds 0.5 × daily ----
        approved_half_days = float(
            employee.leave_requests.filter(status="approved", day_portion__in=["half_am", "half_pm"], date_from__range=(period.period_start, period.period_end)).aggregate(s=Sum("days"))["s"] or 0
        )
        half_day_deduction = _r2(approved_half_days * daily)

        # ---- overtime: paid one cutoff in arrears, actual hours (from the punches) capped at approved ----
        ot_from, ot_to = period.overtime_window()
        overtime_pay = 0.0
        for ot in employee.overtime_requests.filter(status="approved", ot_date__range=(ot_from, ot_to)):
            sync_actual_hours(ot)  # never rely on someone having opened the Overtime page
            overtime_pay += (ot.payable_hours() or 0) * hourly * ot.multiplier()
        overtime_pay = _r2(overtime_pay)

        # ---- government contributions (monthly, split 50/50 per cutoff) ----
        year = period.period_end.year
        cutoff = period.cutoff_type or ("first_half" if period.period_start.day <= 15 else "second_half")
        sss = self.contribution("sss", monthly, year, cutoff)
        philhealth = self.contribution("philhealth", monthly, year, cutoff)
        pagibig = self.contribution("pagibig", monthly, year, cutoff)

        allowance_pct = rates.get("allowance_percent")
        allowances = _r2(basic_pay * allowance_pct / 100) if allowance_pct > 0 else float(existing.allowances if existing else 0)
        other_deductions = float(existing.other_deductions if existing else 0)

        gross_pay = _r2(basic_pay + overtime_pay + allowances)
        taxable = max(0.0, gross_pay - sss - philhealth - pagibig)
        withholding_tax = _r2(taxable * rates.get("withholding_tax_percent") / 100)

        with transaction.atomic():
            # Give back what this line took last time (a recalculation must never
            # charge twice), then take this cutoff's installment from every balance due.
            if existing:
                self.reverse_deduction_payments(existing)

            item, _ = PayrollItem.objects.update_or_create(
                employee=employee, payroll_period=period,
                defaults=dict(
                    adjusted_at=None, adjusted_by=None,
                    allowances=allowances, other_deductions=other_deductions,
                    basic_pay=basic_pay, overtime_pay=overtime_pay, night_diff_pay=0, holiday_pay=0, gross_pay=gross_pay,
                    late_undertime_deduction=late_undertime, absences_deduction=absences_deduction, half_day_deduction=half_day_deduction,
                    sss_deduction=sss, philhealth_deduction=philhealth, pagibig_deduction=pagibig, withholding_tax=withholding_tax,
                    loan_deduction=0, missing_item_deduction=0,
                ),
            )
            loan, missing = self.collect_deductions(item, employee, period)
            item.loan_deduction = loan
            item.missing_item_deduction = missing
            item.recompute_totals().save()
            return item

    def reverse_deduction_payments(self, item: PayrollItem):
        """Undo the loan / missing-item installments a line took, restoring each balance."""
        for payment in item.deduction_payments.select_related("deduction"):
            payment.deduction.refund(payment.amount)
            payment.delete()

    def collect_deductions(self, item, employee, period):
        loan = missing = Decimal(0)
        due = PayrollDeduction.objects.filter(employee=employee).due_for(period).order_by("starts_on", "id")
        for d in due:
            amount = d.next_installment()
            if amount <= 0:
                continue
            PayrollDeductionPayment.objects.create(deduction=d, payroll_item=item, payroll_period=period, amount=amount)
            d.collect(amount)
            if d.is_loan:
                loan += amount
            else:
                missing += amount
        return dec(loan), dec(missing)

    def contribution(self, kind: str, monthly_salary: float, year: int, cutoff_type: str) -> float:
        """Employee share for a type, 50% on the 15th cutoff and the remainder at month end."""
        rate = ContributionRate.for_salary(kind, monthly_salary, year)
        if not rate:
            return 0.0
        base = max(float(rate.min_salary), monthly_salary)
        if rate.max_salary is not None:
            base = min(float(rate.max_salary), base)
        monthly_share = base * float(rate.employee_rate)
        if kind == "pagibig":
            monthly_share = min(monthly_share, rates.get("pagibig_monthly_cap"))
        first_half = _r2(monthly_share / 2)
        return first_half if cutoff_type == "first_half" else _r2(monthly_share - first_half)

    def late_undertime_deduction(self, employee, period, hourly: float, logs) -> float:
        """Late and undertime minutes × per-minute rate, using the first in and last out of each day."""
        schedule = employee.schedule
        if not schedule or not schedule.time_in or not schedule.time_out:
            return 0.0
        by_day = defaultdict(list)
        for log in logs:
            by_day[log.logged_at.date()].append(log)
        excused_out = set(
            employee.leave_requests.filter(status="approved", date_from__range=(period.period_start, period.period_end))
            .filter(models_q_early_or_half())
            .values_list("date_from", flat=True)
        )
        minutes = 0
        for day, day_logs in by_day.items():
            ins = [l.logged_at for l in day_logs if l.log_type == "time_in"]
            outs = [l.logged_at for l in day_logs if l.log_type == "time_out"]
            if ins:
                first_in = ins[0]
                expected_in = datetime.combine(first_in.date(), schedule.time_in) + timedelta(minutes=int(schedule.grace_minutes or 0))
                if first_in > expected_in:
                    minutes += int((first_in - expected_in).total_seconds() // 60)
            if outs and day not in excused_out:
                last_out = outs[-1]
                expected_out = datetime.combine(last_out.date(), schedule.time_out)
                if last_out < expected_out:
                    minutes += int((expected_out - last_out).total_seconds() // 60)
        return _r2(minutes * (hourly / 60))

    @staticmethod
    def working_days(period) -> int:
        d, n = period.period_start, 0
        while d <= period.period_end:
            if d.weekday() < 5:
                n += 1
            d += timedelta(days=1)
        return n


def models_q_early_or_half():
    from django.db.models import Q

    return Q(is_early_leave=True) | Q(day_portion__in=["half_am", "half_pm"])
