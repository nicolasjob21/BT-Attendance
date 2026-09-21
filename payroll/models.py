import calendar
import math
from datetime import date, datetime, timedelta
from decimal import Decimal

from django.conf import settings
from django.db import models
from django.db.models import Sum
from django.utils import timezone

from core.support import dec
from employees.models import Employee, Site


def _cutoff_for(d: date) -> dict:
    """
    Company salary schedule (fixed): 1st–15th paid on the 15th, 16th–last day
    paid on the last day of the month.
    """
    if d.day <= 15:
        return {"start": d.replace(day=1), "end": d.replace(day=15), "cutoff_type": "first_half"}
    last = calendar.monthrange(d.year, d.month)[1]
    return {"start": d.replace(day=16), "end": d.replace(day=last), "cutoff_type": "second_half"}


class PayrollPeriod(models.Model):
    period_start = models.DateField()
    period_end = models.DateField()
    pay_date = models.DateField(null=True, blank=True)
    cutoff_type = models.CharField(max_length=20, null=True, blank=True)
    status = models.CharField(max_length=20, default="open")  # open | processing | closed
    generated_at = models.DateTimeField(null=True, blank=True)
    generated_by = models.CharField(max_length=40, null=True, blank=True)  # user id
    reminded_at = models.DateTimeField(null=True, blank=True)
    closed_at = models.DateTimeField(null=True, blank=True)
    closed_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="closed_by")
    released_at = models.DateTimeField(null=True, blank=True)
    released_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="released_by")
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "payroll_periods"
        ordering = ["-period_start"]

    def __str__(self):
        return self.label()

    # ---- schedule ----
    cutoff_for = staticmethod(_cutoff_for)

    @classmethod
    def ensure_for(cls, d: date):
        c = _cutoff_for(d)
        existing = cls.objects.filter(period_start=c["start"], period_end=c["end"]).first()
        if existing:
            return existing
        return cls.objects.create(period_start=c["start"], period_end=c["end"], cutoff_type=c["cutoff_type"], pay_date=c["end"], status="open")

    def next(self):
        return PayrollPeriod.ensure_for(self.period_end + timedelta(days=1))

    def label(self):
        return f"{self.period_start:%b %-d} – {self.period_end:%b %-d, %Y}"

    @property
    def short_label(self):
        return f"{self.period_start:%b %-d} – {self.period_end:%b %-d}"

    @property
    def is_first_half(self):
        return self.cutoff_type == "first_half" or (self.cutoff_type is None and self.period_start.day <= 15)

    # ---- state ----
    @property
    def is_closed(self):
        return self.status == "closed"

    @property
    def is_released(self):
        return self.released_at is not None

    def release_timing(self):
        if not self.released_at or not self.pay_date:
            return None
        diff = (self.released_at.date() - self.pay_date).days
        if diff < 0:
            return f"{abs(diff)} day(s) early"
        if diff > 0:
            return f"{diff} day(s) late"
        return "on time"

    def overtime_window(self):
        """OT is paid one cutoff in arrears: the window this period pays for."""
        if self.is_first_half:
            prev_month_last = self.period_start - timedelta(days=1)
            return prev_month_last.replace(day=16), prev_month_last
        return self.period_start.replace(day=1), self.period_start.replace(day=15)

    def overtime_window_label(self):
        a, b = self.overtime_window()
        return f"{a:%b %-d} – {b:%b %-d}"


class PayrollItem(models.Model):
    EDITABLE = [
        "basic_pay", "overtime_pay", "night_diff_pay", "holiday_pay", "allowances",
        "late_undertime_deduction", "absences_deduction", "half_day_deduction",
        "sss_deduction", "philhealth_deduction", "pagibig_deduction", "withholding_tax", "other_deductions",
    ]

    employee = models.ForeignKey(Employee, on_delete=models.CASCADE, related_name="payroll_items")
    payroll_period = models.ForeignKey(PayrollPeriod, on_delete=models.CASCADE, related_name="items")
    basic_pay = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    overtime_pay = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    night_diff_pay = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    holiday_pay = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    allowances = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    gross_pay = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    late_undertime_deduction = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    absences_deduction = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    half_day_deduction = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    sss_deduction = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    philhealth_deduction = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    pagibig_deduction = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    withholding_tax = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    other_deductions = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    loan_deduction = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    missing_item_deduction = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    total_deductions = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    net_pay = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    remarks = models.CharField(max_length=255, null=True, blank=True)
    adjusted_at = models.DateTimeField(null=True, blank=True)
    adjusted_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="adjusted_by")
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "payroll_items"
        unique_together = [("employee", "payroll_period")]

    @property
    def is_adjusted(self):
        return self.adjusted_at is not None

    def recompute_totals(self):
        self.gross_pay = dec(Decimal(self.basic_pay) + Decimal(self.overtime_pay) + Decimal(self.night_diff_pay) + Decimal(self.holiday_pay) + Decimal(self.allowances))
        self.total_deductions = dec(
            Decimal(self.late_undertime_deduction) + Decimal(self.absences_deduction) + Decimal(self.half_day_deduction)
            + Decimal(self.sss_deduction) + Decimal(self.philhealth_deduction) + Decimal(self.pagibig_deduction)
            + Decimal(self.withholding_tax) + Decimal(self.other_deductions)
            + Decimal(self.loan_deduction) + Decimal(self.missing_item_deduction)
        )
        self.net_pay = dec(self.gross_pay - self.total_deductions)
        return self


class Payslip(models.Model):
    payroll_item = models.OneToOneField(PayrollItem, on_delete=models.CASCADE, related_name="payslip")
    file_path = models.CharField(max_length=2048, null=True, blank=True)
    generated_at = models.DateTimeField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "payslips"


class ContributionRate(models.Model):
    contribution_type = models.CharField(max_length=20)  # sss | philhealth | pagibig
    min_salary = models.DecimalField(max_digits=12, decimal_places=2)
    max_salary = models.DecimalField(max_digits=12, decimal_places=2, null=True, blank=True)
    employee_rate = models.DecimalField(max_digits=8, decimal_places=4)
    employer_rate = models.DecimalField(max_digits=8, decimal_places=4)
    ec_amount = models.DecimalField(max_digits=10, decimal_places=2, default=0)
    effective_year = models.PositiveIntegerField()
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "contribution_rates"
        ordering = ["contribution_type", "min_salary"]

    @classmethod
    def for_salary(cls, kind: str, salary: float, year: int):
        brackets = list(cls.objects.filter(contribution_type=kind, effective_year=year).order_by("min_salary"))
        if not brackets:
            return None
        for b in brackets:
            if salary >= float(b.min_salary) and (b.max_salary is None or salary <= float(b.max_salary)):
                return b
        # Above the highest ceiling -> top bracket; below the lowest floor -> bottom bracket.
        return brackets[-1] if salary > float(brackets[-1].min_salary) else brackets[0]


class DeductionQuerySet(models.QuerySet):
    def due_for(self, period: PayrollPeriod):
        return self.filter(status=PayrollDeduction.ACTIVE, balance__gt=0, starts_on__lte=period.period_end)


class PayrollDeduction(models.Model):
    LOAN = "loan"
    MISSING_ITEM = "missing_item"
    TYPES = {LOAN: "Loan", MISSING_ITEM: "Missing item"}
    ACTIVE, PAID, CANCELLED = "active", "paid", "cancelled"

    employee = models.ForeignKey(Employee, on_delete=models.CASCADE, related_name="payroll_deductions")
    type = models.CharField(max_length=20)
    site = models.ForeignKey(Site, null=True, blank=True, on_delete=models.SET_NULL, related_name="+")
    group_id = models.CharField(max_length=36, null=True, blank=True)
    description = models.CharField(max_length=255)
    incident_date = models.DateField(null=True, blank=True)
    total_amount = models.DecimalField(max_digits=12, decimal_places=2)
    installment_amount = models.DecimalField(max_digits=12, decimal_places=2)
    balance = models.DecimalField(max_digits=12, decimal_places=2)
    starts_on = models.DateField()
    status = models.CharField(max_length=20, default="active")
    remarks = models.TextField(null=True, blank=True)
    created_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="created_by")
    cancelled_at = models.DateTimeField(null=True, blank=True)
    cancelled_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="cancelled_by")
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    objects = DeductionQuerySet.as_manager()

    class Meta:
        db_table = "payroll_deductions"
        ordering = ["-id"]

    @property
    def is_loan(self):
        return self.type == self.LOAN

    @property
    def is_active(self):
        return self.status == self.ACTIVE

    @property
    def type_label(self):
        return self.TYPES.get(self.type, self.type)

    def paid(self) -> Decimal:
        return dec(Decimal(self.total_amount) - Decimal(self.balance))

    def next_installment(self) -> Decimal:
        return dec(min(Decimal(self.installment_amount), Decimal(self.balance)))

    def cutoffs_left(self) -> int:
        return math.ceil(float(self.balance) / float(self.installment_amount)) if float(self.installment_amount) > 0 else 0

    @property
    def paid_percent(self) -> int:
        return int(round(float(self.paid()) / float(self.total_amount) * 100)) if float(self.total_amount) > 0 else 0

    def refund(self, amount):
        self.balance = dec(Decimal(self.balance) + Decimal(str(amount)))
        if self.status == self.PAID and self.balance > 0:
            self.status = self.ACTIVE
        self.save()

    def collect(self, amount):
        self.balance = dec(max(Decimal(0), Decimal(self.balance) - Decimal(str(amount))))
        if self.balance <= 0:
            self.status = self.PAID
        self.save()


class PayrollDeductionPayment(models.Model):
    deduction = models.ForeignKey(PayrollDeduction, on_delete=models.CASCADE, related_name="payments", db_column="payroll_deduction_id")
    payroll_item = models.ForeignKey(PayrollItem, on_delete=models.CASCADE, related_name="deduction_payments")
    payroll_period = models.ForeignKey(PayrollPeriod, on_delete=models.CASCADE, related_name="+")
    amount = models.DecimalField(max_digits=12, decimal_places=2)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "payroll_deduction_payments"

    def balance_after(self) -> Decimal:
        taken = PayrollDeductionPayment.objects.filter(deduction_id=self.deduction_id, payroll_period_id__lte=self.payroll_period_id).aggregate(s=Sum("amount"))["s"] or 0
        return dec(max(Decimal(0), Decimal(self.deduction.total_amount) - Decimal(taken)))
