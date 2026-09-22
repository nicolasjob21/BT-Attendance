from datetime import date, datetime
from decimal import Decimal

from django.db import models
from django.utils import timezone

from employees.models import Employee, Site


class LeaveType(models.Model):
    name = models.CharField(max_length=100)
    code = models.CharField(max_length=10, null=True, blank=True)
    default_days_per_year = models.PositiveIntegerField(default=0)
    is_paid = models.BooleanField(default=True)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "leave_types"
        ordering = ["id"]

    def __str__(self):
        return self.name


class LeaveRequest(models.Model):
    PORTION_LABELS = {"full": "Full day", "half_am": "Half day — Morning", "half_pm": "Half day — Afternoon"}

    employee = models.ForeignKey(Employee, on_delete=models.CASCADE, related_name="leave_requests")
    leave_type = models.ForeignKey(LeaveType, null=True, blank=True, on_delete=models.SET_NULL, related_name="requests")
    date_from = models.DateField()
    date_to = models.DateField()
    days = models.DecimalField(max_digits=5, decimal_places=2, default=1)
    day_portion = models.CharField(max_length=10, default="full")
    is_early_leave = models.BooleanField(default=False)
    requested_time_out = models.TimeField(null=True, blank=True)
    reason = models.TextField(null=True, blank=True)
    status = models.CharField(max_length=20, default="pending")
    approved_by = models.ForeignKey(Employee, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="approved_by")
    approved_at = models.DateTimeField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "leave_requests"
        ordering = ["-date_from", "-id"]

    @property
    def is_half_day(self):
        return self.day_portion != "full"

    @property
    def portion_label(self):
        return self.PORTION_LABELS.get(self.day_portion, self.day_portion)

    @property
    def kind_label(self):
        if self.is_early_leave:
            return "Early leave"
        return self.leave_type.name if self.leave_type else "Leave"

    @property
    def when_label(self):
        if self.date_to and self.date_to != self.date_from:
            return f"{self.date_from:%b %-d} – {self.date_to:%b %-d}"
        return f"{self.date_from:%b %-d}"


class OvertimeRequest(models.Model):
    MULTIPLIERS = {"regular": 1.25, "rest_day": 1.30, "holiday": 2.00}
    TYPE_LABELS = {"regular": "Regular OT · 125%", "rest_day": "Rest-day OT · 130%", "holiday": "Holiday OT · 200%"}

    employee = models.ForeignKey(Employee, on_delete=models.CASCADE, related_name="overtime_requests")
    site = models.ForeignKey(Site, null=True, blank=True, on_delete=models.SET_NULL, related_name="+")
    ot_date = models.DateField()
    planned_start = models.TimeField(null=True, blank=True)
    planned_end = models.TimeField(null=True, blank=True)
    requested_hours = models.DecimalField(max_digits=5, decimal_places=2, null=True, blank=True)
    hours = models.DecimalField(max_digits=5, decimal_places=2, null=True, blank=True)
    hours_synced_at = models.DateTimeField(null=True, blank=True)
    ot_type = models.CharField(max_length=20, default="regular")
    reason = models.TextField(null=True, blank=True)
    status = models.CharField(max_length=20, default="pending")
    approved_by = models.ForeignKey(Employee, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="approved_by")
    approved_at = models.DateTimeField(null=True, blank=True)
    admin_remarks = models.TextField(null=True, blank=True)
    cancelled_by = models.ForeignKey(Employee, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="cancelled_by")
    cancelled_at = models.DateTimeField(null=True, blank=True)
    cancel_reason = models.TextField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "overtime_requests"
        ordering = ["-ot_date", "-id"]

    def multiplier(self) -> float:
        from payroll.rates import ot_multiplier

        return ot_multiplier(self.ot_type or "regular")

    @property
    def type_label(self):
        return self.TYPE_LABELS.get(self.ot_type, self.ot_type)

    def payable_hours(self):
        """Actual hours capped at what was approved in advance; None until derived."""
        if self.hours is None:
            return None
        actual = float(self.hours)
        return min(actual, float(self.requested_hours)) if self.requested_hours is not None else actual

    def planned_window(self):
        if not self.planned_start or not self.planned_end:
            return None
        return f"{self.planned_start:%-I:%M %p} – {self.planned_end:%-I:%M %p}"

    def payout_cutoff_label(self):
        d = self.ot_date
        if d.day <= 15:
            from calendar import monthrange

            last = date(d.year, d.month, monthrange(d.year, d.month)[1])
            return f"{d.replace(day=16):%b %-d} – {last:%b %-d} payroll"
        nxt = (d.replace(day=1) + timezone.timedelta(days=32)).replace(day=1)
        return f"{nxt:%b %-d} – {nxt.replace(day=15):%b %-d} payroll"

    def payout_period(self):
        """The payroll period that pays this OT (one cutoff in arrears), if it exists yet."""
        from payroll.models import PayrollPeriod

        d = self.ot_date
        start = d.replace(day=16) if d.day <= 15 else (d.replace(day=1) + timezone.timedelta(days=32)).replace(day=1)
        return PayrollPeriod.objects.filter(period_start=start).first()

    def can_cancel(self):
        """Approved and not yet on a final payslip (the paying period is neither closed nor released)."""
        if self.status != "approved":
            return False
        period = self.payout_period()
        return not (period and (period.is_closed or period.is_released))
