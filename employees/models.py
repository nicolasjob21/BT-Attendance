import re
from datetime import date

from django.conf import settings
from django.db import models
from django.db.models import Q
from django.utils import timezone


class Schedule(models.Model):
    name = models.CharField(max_length=255)
    time_in = models.TimeField(null=True, blank=True)
    time_out = models.TimeField(null=True, blank=True)
    grace_minutes = models.PositiveSmallIntegerField(default=0)
    is_flexible = models.BooleanField(default=False)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "schedules"

    def __str__(self):
        return self.name


class SiteQuerySet(models.QuerySet):
    def active_on(self, on=None):
        day = on or timezone.now().date()
        return (
            self.filter(status="active")
            .filter(Q(active_from__isnull=True) | Q(active_from__lte=day))
            .filter(Q(active_until__isnull=True) | Q(active_until__gte=day))
        )


class Site(models.Model):
    TYPES = {
        "office": "Main office",
        "project_site": "Project site",
        "temporary": "Temporary / alternate site",
    }
    STATUSES = {"active": "Active", "inactive": "Inactive", "completed": "Completed"}

    name = models.CharField(max_length=255)
    client_name = models.CharField(max_length=255, null=True, blank=True)
    address = models.CharField(max_length=255, null=True, blank=True)
    latitude = models.DecimalField(max_digits=10, decimal_places=7)
    longitude = models.DecimalField(max_digits=10, decimal_places=7)
    geofence_radius_m = models.PositiveIntegerField(default=150)
    is_headquarters = models.BooleanField(default=False)
    type = models.CharField(max_length=30, default="project_site")
    status = models.CharField(max_length=20, default="active")
    active_from = models.DateField(null=True, blank=True)
    active_until = models.DateField(null=True, blank=True)
    created_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="created_by")
    updated_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="updated_by")
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    objects = SiteQuerySet.as_manager()

    class Meta:
        db_table = "sites"
        ordering = ["name"]

    def __str__(self):
        return self.name

    @property
    def is_office(self):
        return self.type == "office"

    @property
    def type_label(self):
        return self.TYPES.get(self.type, (self.type or "").replace("_", " ").capitalize())

    def is_active_on(self, on=None):
        day = on or timezone.now().date()
        return (
            self.status == "active"
            and (self.active_from is None or self.active_from <= day)
            and (self.active_until is None or self.active_until >= day)
        )


class EmployeeQuerySet(models.QuerySet):
    def search(self, term):
        """Every word must match name / employee no. / email / username."""
        qs = self
        for word in re.split(r"\s+", (term or "").strip()):
            if not word:
                continue
            qs = qs.filter(
                Q(first_name__icontains=word)
                | Q(last_name__icontains=word)
                | Q(employee_no__icontains=word)
                | Q(email__icontains=word)
                | Q(user__username__icontains=word)
                | Q(user__name__icontains=word)
            )
        return qs

    def active(self):
        return self.filter(status="active")


class Employee(models.Model):
    PAYOUT_METHODS = {"card": "Card (direct to card)", "cash": "Cash (payslip in envelope)"}
    STATUSES = {"active": "Active", "inactive": "Inactive", "on_leave": "On leave"}

    user = models.OneToOneField(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="employee")
    employee_no = models.CharField(max_length=30, null=True, blank=True, unique=True)
    first_name = models.CharField(max_length=100)
    last_name = models.CharField(max_length=100, blank=True)
    email = models.EmailField(max_length=255)
    phone = models.CharField(max_length=40, null=True, blank=True)
    employee_type = models.CharField(max_length=30, default="admin")
    schedule = models.ForeignKey(Schedule, null=True, blank=True, on_delete=models.SET_NULL, related_name="employees")
    monthly_salary = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    daily_rate = models.DecimalField(max_digits=12, decimal_places=2, default=0)
    date_hired = models.DateField(null=True, blank=True)
    status = models.CharField(max_length=20, default="active")
    payout_method = models.CharField(max_length=10, default="cash")
    bank_account_no = models.CharField(max_length=60, null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    objects = EmployeeQuerySet.as_manager()

    class Meta:
        db_table = "employees"
        ordering = ["last_name", "first_name"]

    def __str__(self):
        return self.full_name

    @property
    def full_name(self):
        return f"{self.first_name} {self.last_name}".strip()

    @property
    def pays_by_card(self):
        return self.payout_method == "card"

    @property
    def active_assignment(self):
        """The project assignment in force today, if any (cached per instance)."""
        if not hasattr(self, "_active_assignment"):
            self._active_assignment = self.project_assignments.active_on().order_by("-start_date", "-id").first()
        return self._active_assignment

    def assigned_site(self):
        a = self.active_assignment
        return a.site if a else None

    def has_checkpoint_access(self) -> bool:
        """Only employees deployed to a site with a campaign they are part of see Check Point."""
        site = self.assigned_site()
        if not site:
            return False
        from checkpoints.models import CheckpointCampaign

        return CheckpointCampaign.objects.filter(project_site=site, participants__employee=self).exists()

    def assign_employee_no(self):
        if not self.employee_no:
            self.employee_no = f"EMP-{self.pk:04d}"
            Employee.objects.filter(pk=self.pk).update(employee_no=self.employee_no)


class AssignmentQuerySet(models.QuerySet):
    def active_on(self, on=None):
        day = on or timezone.now().date()
        return self.filter(status="active", start_date__lte=day).filter(Q(end_date__isnull=True) | Q(end_date__gte=day))


class EmployeeProjectAssignment(models.Model):
    employee = models.ForeignKey(Employee, on_delete=models.CASCADE, related_name="project_assignments")
    site = models.ForeignKey(Site, on_delete=models.CASCADE, related_name="assignments")
    start_date = models.DateField()
    end_date = models.DateField(null=True, blank=True)
    status = models.CharField(max_length=20, default="active")
    assignment_notes = models.TextField(null=True, blank=True)
    created_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="created_by")
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    objects = AssignmentQuerySet.as_manager()

    class Meta:
        db_table = "employee_project_assignments"
        ordering = ["-start_date", "-id"]

    def end(self, on=None, status="ended"):
        self.status = status
        self.end_date = on or timezone.now().date()
        self.save(update_fields=["status", "end_date"])
