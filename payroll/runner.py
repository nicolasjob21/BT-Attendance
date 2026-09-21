"""
Runs payroll — but a person always presses the button.

Company salary schedule (fixed): 1st–15th is paid on the 15th and 16th–last
day is paid on the last day of the month. Every morning the scheduler calls
tick(): keep the periods rolling and, on pay day, remind everyone who can run
payroll — once per period. status() is what the payroll page and the dashboard
show; generate() is the manual "Run payroll".
"""

from datetime import timedelta

from django.utils import timezone

from core.models import notify, users_with_permission
from employees.models import Employee

from .calculator import PayrollCalculator
from .models import PayrollPeriod, _cutoff_for


class PayrollRunner:
    def __init__(self):
        self.calculator = PayrollCalculator()

    def tick(self, now=None) -> dict:
        now = now or timezone.now()
        created = self.roll_forward(now)
        reminded = []
        due = PayrollPeriod.objects.filter(generated_at__isnull=True, reminded_at__isnull=True, released_at__isnull=True, period_end__lte=now.date()).order_by("period_start")
        for period in due:
            notify(
                users_with_permission("run payroll"), kind="payroll", title="Pay day today — payroll due",
                message=f"{period.label()} has not been computed. Run payroll, review the lines, then release.",
                url=f"/payroll?period={period.id}",
            )
            period.reminded_at = now
            period.save(update_fields=["reminded_at"])
            reminded.append(period.label())
        return {"periods_created": created, "reminded": reminded}

    def roll_forward(self, now=None) -> int:
        """Create every period from the last known one up to the current cutoff, plus the next. Idempotent."""
        now = now or timezone.now()
        before = PayrollPeriod.objects.count()
        latest = PayrollPeriod.objects.order_by("-period_end").first()
        current = PayrollPeriod.ensure_for(now.date())
        p = latest
        while p is not None and p.period_end < current.period_start:
            p = p.next()
        current.next()
        return PayrollPeriod.objects.count() - before

    def status(self, now=None) -> dict:
        today = (now or timezone.now()).date()

        overdue = PayrollPeriod.objects.filter(generated_at__isnull=True, released_at__isnull=True, period_end__lt=today).order_by("period_start").first()
        if overdue:
            days = (today - overdue.period_end).days
            return {
                "state": "overdue", "period": overdue, "pay_date": overdue.period_end, "days": -days,
                "title": "Payroll overdue",
                "message": f"{overdue.label()} was due on {overdue.period_end:%b %-d} ({days} day{'' if days == 1 else 's'} ago) and has not been computed.",
                "action": "run",
            }

        waiting = PayrollPeriod.objects.filter(generated_at__isnull=False, released_at__isnull=True, period_end__lte=today).order_by("period_start").first()
        if waiting:
            return {
                "state": "computed", "period": waiting, "pay_date": waiting.period_end, "days": 0,
                "title": "Ready to release",
                "message": f"{waiting.label()} is computed — review the lines, then release it so employees get their payslips.",
                "action": "release",
            }

        cutoff = _cutoff_for(today)
        period = PayrollPeriod.objects.filter(period_start=cutoff["start"], period_end=cutoff["end"]).first()
        label = f"{cutoff['start']:%b %-d} – {cutoff['end']:%b %-d, %Y}"
        if cutoff["end"] == today and not (period and period.released_at):
            return {
                "state": "due", "period": period, "pay_date": cutoff["end"], "days": 0,
                "title": "Pay day is today",
                "message": f"{label} has not been computed yet. Run payroll, review the lines, then release.",
                "action": "run",
            }

        nxt = _cutoff_for(cutoff["end"] + timedelta(days=1)) if (period and period.released_at) else cutoff
        days = (nxt["end"] - today).days
        return {
            "state": "upcoming", "period": period, "pay_date": nxt["end"], "days": days,
            "title": f"Next pay day: {nxt['end']:%a, %b %-d}",
            "message": "Today." if days == 0 else f"In {days} day{'' if days == 1 else 's'} · {nxt['start']:%b %-d} – {nxt['end']:%b %-d} cutoff.",
            "action": None,
        }

    def generate(self, period: PayrollPeriod, by: str, force: bool = False) -> int:
        employees = list(Employee.objects.active())
        for e in employees:
            self.calculator.calculate(e, period, force)
        period.status = "processing"
        period.generated_at = timezone.now()
        period.generated_by = str(by)
        period.save(update_fields=["status", "generated_at", "generated_by"])
        return len(employees)
