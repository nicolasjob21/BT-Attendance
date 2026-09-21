"""
The role & permission matrix — the code twin of BT-Attendance-Role-Permissions.xlsx.

Permissions are Django auth Permissions on the `accounts.AppPermission` content
type (codename = name with spaces replaced by underscores); roles are Groups.
`sync_defaults()` builds them from this file. Changing a role's permissions in
the app does not touch this file — it is the starting point, not a lock.
"""

SUPERADMIN = "superadmin"
DEVELOPER = "developer"
ADMIN = "admin"
EMPLOYEE = "employee"

ROLES = [SUPERADMIN, DEVELOPER, ADMIN, EMPLOYEE]

ROLE_LABELS = {
    SUPERADMIN: "Super Admin (CEO)",
    DEVELOPER: "Developer",
    ADMIN: "Admin",
    EMPLOYEE: "Employee",
}

ROLE_DESCRIPTIONS = {
    SUPERADMIN: "Owner. Every management module, including accounts and roles. Management only — no clock in/out, leave, OT or checkpoints.",
    DEVELOPER: "Software / IT. Accounts, settings, locations, checkpoint configuration, reports. Kept out of money and HR decisions.",
    ADMIN: "HR officer / office admin. Employees, attendance log, approvals, payroll, Check Point — plus their own attendance.",
    EMPLOYEE: "Everyone else. Self-service only.",
}

# Which roles each role may hand out. Nobody can assign a role above their own.
ASSIGNABLE = {
    SUPERADMIN: [SUPERADMIN, DEVELOPER, ADMIN, EMPLOYEE],
    DEVELOPER: [DEVELOPER, ADMIN, EMPLOYEE],
    ADMIN: [EMPLOYEE],
    EMPLOYEE: [],
}

# Must stay on the superadmin role so nobody can lock every administrator out.
LOCKED_SUPERADMIN = ["manage users"]

# permission => (module, what it unlocks, reserved?)
PERMISSIONS = {
    "clock attendance": ("Self-service", "Clock In / Out (selfie + GPS, geofence check) · My Attendance and own monthly timesheet · own time in/out softcopies · My Checkpoints — only while assigned to a project site that has a checkpoint they are part of · dashboard clock tile, recent punches and next pay day", False),
    "request leave": ("Self-service", "Leave page: file leave and early-leave requests", False),
    "request overtime": ("Self-service", "Overtime page: file OT requests", False),
    "view own payslip": ("Self-service", "Open own payslip", False),
    "approve requests": ("Approvals", "Approve / deny leave, early-leave and OT requests · verify an unusually long (13h+) day · approve or reject a punch made outside every geofence or without GPS", False),
    "view team reports": ("Attendance", "Attendance Log — everyone's time in/out by date with search, worked / still clocked in / day off / needs-check counts, overnight shifts shown on the day they started (+1) · any employee's monthly timesheet · anyone's time in/out softcopies", False),
    "manage employees": ("Employees", "Employees list · add / edit · Excel import · activate / deactivate · project-site assignment", False),
    "export employees": ("Employees", "Download the employee list as Excel", False),
    "run payroll": ("Payroll", "Payroll page · generate payroll · salary history", False),
    "view all payslips": ("Payroll", "Open anyone's payslip without generating payroll", False),
    "manage settings": ("Settings", "Umbrella for every settings page (sites, schedules, rates)", False),
    "manage sites": ("Settings", "Locations / geofences only", False),
    "manage schedules": ("Settings", "Work schedules only", True),
    "manage payroll rates": ("Settings", "Payroll Rates page (basic %, allowance %, OT premiums, SSS / PhilHealth / Pag-IBIG brackets, tax %) · edit an employee's payroll line · release payroll — Super Admin only", False),
    "manage users": ("Users", "User Management: accounts, roles, passwords, disable / delete / restore, live status", False),
    "view audit log": ("System", "Every sensitive action with user, time and IP", True),
    "view system health": ("System", "Scheduler, queue, storage, failed jobs", True),
    "view checkpoint module": ("Check Point", "Check Point sidebar entry · dashboard · campaigns · history · daily view", False),
    "create checkpoint campaign": ("Check Point", "Create / edit draft campaigns", False),
    "activate checkpoint campaign": ("Check Point", "Activate now / schedule a campaign", False),
    "pause checkpoint campaign": ("Check Point", "Pause / resume an active campaign", False),
    "end checkpoint campaign": ("Check Point", "End early · cancel · close", False),
    "view checkpoint results": ("Check Point", "Monitoring page · checkpoint detail · stamped photos", False),
    "review checkpoint exceptions": ("Check Point", "Classify missed / failed / pending checkpoints, mark reviewed", False),
    "export checkpoint reports": ("Check Point", "Export campaign results to CSV", False),
    "manage checkpoint settings": ("Check Point", "Defaults and the photo-instruction library", False),
}

DEFAULTS = {
    SUPERADMIN: [
        "approve requests", "view team reports",
        "manage employees", "export employees",
        "run payroll", "view all payslips",
        "manage settings", "manage sites", "manage schedules", "manage payroll rates",
        "manage users",
        "view audit log", "view system health",
        "view checkpoint module", "create checkpoint campaign", "activate checkpoint campaign",
        "pause checkpoint campaign", "end checkpoint campaign", "view checkpoint results",
        "review checkpoint exceptions", "export checkpoint reports", "manage checkpoint settings",
    ],
    DEVELOPER: [
        "clock attendance", "request leave", "request overtime", "view own payslip",
        "view team reports",
        "manage employees", "export employees",
        "manage settings", "manage sites", "manage schedules",
        "manage users",
        "view audit log", "view system health",
        "view checkpoint module", "create checkpoint campaign",
        "pause checkpoint campaign", "end checkpoint campaign", "view checkpoint results",
        "export checkpoint reports", "manage checkpoint settings",
    ],
    ADMIN: [
        "clock attendance", "request leave", "request overtime", "view own payslip",
        "approve requests", "view team reports",
        "manage employees", "export employees",
        "run payroll", "view all payslips",
        "manage settings", "manage sites", "manage schedules",
        "view checkpoint module", "create checkpoint campaign", "activate checkpoint campaign",
        "pause checkpoint campaign", "end checkpoint campaign", "view checkpoint results",
        "review checkpoint exceptions", "export checkpoint reports", "manage checkpoint settings",
    ],
    EMPLOYEE: ["clock attendance", "request leave", "request overtime", "view own payslip"],
}


def codename(name: str) -> str:
    """'run payroll' -> 'run_payroll'."""
    return name.strip().lower().replace(" ", "_")


def perm_label(name: str) -> str:
    """Full Django permission label: 'accounts.run_payroll'."""
    return f"accounts.{codename(name)}"


def by_module():
    out = {}
    for name, (module, desc, reserved) in PERMISSIONS.items():
        out.setdefault(module, {})[name] = (module, desc, reserved)
    return out


def is_reserved(name: str) -> bool:
    return PERMISSIONS.get(name, (None, None, False))[2]


def assignable_by(role):
    return ASSIGNABLE.get(role, [])


def sync_defaults():
    """Create the permissions and roles and reset every role to its defaults."""
    from django.contrib.auth.models import Group, Permission
    from django.contrib.contenttypes.models import ContentType

    from .models import AppPermission

    ct = ContentType.objects.get_for_model(AppPermission)
    perms = {}
    for name in PERMISSIONS:
        perms[name], _ = Permission.objects.get_or_create(
            content_type=ct, codename=codename(name), defaults={"name": name}
        )
    for role in ROLES:
        group, _ = Group.objects.get_or_create(name=role)
        group.permissions.set([perms[p] for p in DEFAULTS[role]])
