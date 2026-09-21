"""Seed a fresh database: roles/permissions, schedule, sites, leave types, contribution brackets, demo accounts."""

from django.core.management.base import BaseCommand
from django.db import transaction

from accounts import rbac
from accounts.models import User
from employees.models import Employee, Schedule, Site
from leaveot.models import LeaveType
from payroll.models import ContributionRate


class Command(BaseCommand):
    help = "Seed roles, schedules, sites, leave types, contribution rates and demo accounts."

    def add_arguments(self, parser):
        parser.add_argument("--no-demo", action="store_true", help="Skip the demo accounts")

    @transaction.atomic
    def handle(self, *args, **options):
        rbac.sync_defaults()
        office, _ = Schedule.objects.get_or_create(name="Office (8:30 AM – 5:30 PM)", defaults={"time_in": "08:30", "time_out": "17:30", "grace_minutes": 15, "is_flexible": False})
        Site.objects.get_or_create(name="Brite TSI — Head Office", defaults={
            "type": "office", "address": "2189 G. Tuazon St, cor Santissima St, Sampaloc, Manila, 1008 Metro Manila",
            "latitude": "14.6108000", "longitude": "121.0049000", "geofence_radius_m": 150, "is_headquarters": True, "status": "active",
        })
        Site.objects.get_or_create(name="Project Site A — Quezon City", defaults={
            "type": "project_site", "client_name": "ACME Corp", "address": "Quezon City, Philippines",
            "latitude": "14.6760000", "longitude": "121.0437000", "geofence_radius_m": 200, "status": "active",
        })
        for name, code, days, paid in [
            ("Service Incentive Leave", "SIL", 5, True), ("Vacation Leave", "VL", 0, True), ("Sick Leave", "SL", 0, True),
            ("Maternity Leave", "ML", 105, True), ("Paternity Leave", "PL", 7, True), ("Solo Parent Leave", "SPL", 7, True),
            ("Special Leave for Women", "SLW", 60, True), ("Bereavement Leave", "BL", 3, True),
        ]:
            LeaveType.objects.get_or_create(name=name, defaults={"code": code, "default_days_per_year": days, "is_paid": paid})
        if not ContributionRate.objects.exists():
            year = 2026
            ContributionRate.objects.bulk_create([
                ContributionRate(contribution_type="sss", min_salary=5000, max_salary=35000, employee_rate="0.0500", employer_rate="0.1000", ec_amount=30, effective_year=year),
                ContributionRate(contribution_type="philhealth", min_salary=10000, max_salary=100000, employee_rate="0.0250", employer_rate="0.0250", ec_amount=0, effective_year=year),
                ContributionRate(contribution_type="pagibig", min_salary=0, max_salary=1500, employee_rate="0.0100", employer_rate="0.0200", ec_amount=0, effective_year=year),
                ContributionRate(contribution_type="pagibig", min_salary="1500.01", max_salary=None, employee_rate="0.0200", employer_rate="0.0200", ec_amount=0, effective_year=year),
            ])
        if not options["no_demo"]:
            people = [
                ("CEO / Super Admin", "brite-admin", "admin@brite-tsi.com", rbac.SUPERADMIN, 80000),
                ("Developer", "brite-dev", "dev@brite-tsi.com", rbac.DEVELOPER, 40000),
                ("HR Officer", "brite-hr", "hr@brite-tsi.com", rbac.ADMIN, 35000),
                ("Technical Staff", "brite-tech", "tech@brite-tsi.com", rbac.EMPLOYEE, 25000),
            ]
            for n, (name, username, email, role, salary) in enumerate(people, start=1):
                if User.all_objects.filter(username=username).exists():
                    continue
                user = User.objects.create_user(username=username, email=email, password="password", name=name)
                user.set_role(role)
                first, _, last = name.partition(" ")
                Employee.objects.create(
                    user=user, employee_no=f"EMP-{n:04d}", first_name=first, last_name=last, email=email, employee_type="admin",
                    schedule=office, monthly_salary=salary, daily_rate=round(salary / 22, 2), date_hired="2025-01-06", status="active",
                )
        self.stdout.write(self.style.SUCCESS("Seeded."))
