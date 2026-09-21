from datetime import timedelta

from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse
from django.utils import timezone
from django.views.decorators.http import require_POST

from accounts.models import User
from employees.models import Employee
from leaveot.models import LeaveRequest, OvertimeRequest
from payroll.models import _cutoff_for
from payroll.runner import PayrollRunner

from .models import Notification

ICONS = {
    "clock": '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4l2.5 2"/>',
    "calendar": '<rect x="4" y="5" width="16" height="16" rx="2"/><path stroke-linecap="round" d="M4 9h16M8 3v4M16 3v4"/>',
    "plus": '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 9v6M9 12h6"/>',
    "check": '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    "list": '<path stroke-linecap="round" d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
    "users": '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4h-1m-4 5H2v-1a4 4 0 014-4h4a4 4 0 014 4v1zm-3-11a3 3 0 11-6 0 3 3 0 016 0zm7 1a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/>',
    "cash": '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
    "shield": '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/>',
    "pin": '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6-5.2-6-10a6 6 0 1112 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/>',
    "usercog": '<circle cx="10" cy="8" r="3.5"/><path stroke-linecap="round" d="M3 20v-1a5 5 0 015-5h3"/><circle cx="17.5" cy="16.5" r="2.5"/><path stroke-linecap="round" d="M17.5 12.5v1.5M17.5 19v1.5M13.5 16.5H15M20 16.5h1.5"/>',
    "wifi": '<path stroke-linecap="round" d="M5 12.5a10 10 0 0114 0M8.5 16a5 5 0 017 0"/><circle cx="12" cy="19.5" r="1"/>',
}
CHIP = {
    "brand": "bg-brand-100 text-brand-600 dark:bg-brand-900/40 dark:text-brand-300",
    "accent": "bg-accent-100 text-accent-600 dark:bg-accent-900/30 dark:text-accent-300",
    "violet": "bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300",
    "amber": "bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300",
    "emerald": "bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300",
    "slate": "bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300",
    "rose": "bg-rose-100 text-rose-600 dark:bg-rose-900/40 dark:text-rose-300",
}


def _tile(icon, tone, label, value, href, link, **extra):
    t = {"icon": ICONS[icon], "chip": CHIP[tone], "label": label, "value": value, "href": href, "link": link, "small": False, "sub": None, "badge": None, "value_class": ""}
    t.update(extra)
    return t


def _action(icon, tone, label, hint, href):
    return {"icon": ICONS[icon], "chip": CHIP[tone], "label": label, "hint": hint, "href": href}


@login_required
def dashboard(request):
    user = request.user
    employee = getattr(user, "employee", None)
    today = timezone.now().date()

    today_log = employee.attendance_logs.filter(logged_at__date=today).order_by("-logged_at").first() if employee else None
    is_clocked_in = bool(today_log and today_log.log_type == "time_in")
    my_pending_leave = employee.leave_requests.filter(status="pending").count() if employee else 0
    my_pending_ot = employee.overtime_requests.filter(status="pending").count() if employee else 0

    can_approve, can_manage, can_payroll = user.can("approve requests"), user.can("manage employees"), user.can("run payroll")
    can_clock = user.can("clock attendance")
    pending_approvals = (LeaveRequest.objects.filter(status="pending").count() + OvertimeRequest.objects.filter(status="pending").count()) if can_approve else 0
    active_employees = Employee.objects.active().count() if can_manage else None
    online_now = User.objects.online().count() if user.can("manage users") else None
    pay_day = None
    if can_payroll:
        runner = PayrollRunner()
        runner.roll_forward()
        pay_day = runner.status()

    tiles, actions = [], []
    if can_clock:
        tiles.append(_tile("clock", "emerald" if is_clocked_in else "brand", "Today",
                           "Clocked in" if is_clocked_in else ("Clocked out" if today_log else "Not clocked in"),
                           reverse("attendance.create"), "Clock out" if is_clocked_in else "Clock in",
                           value_class="text-emerald-600 dark:text-emerald-400" if is_clocked_in else ("" if today_log else "text-gray-400 dark:text-slate-500"),
                           sub=(f"since {today_log.logged_at:%-I:%M %p}" if is_clocked_in else (f"at {today_log.logged_at:%-I:%M %p}" if today_log else "No punch yet today"))))
        tiles.append(_tile("calendar", "accent", "My pending leave", my_pending_leave, reverse("leave.index"), "View leave"))
        tiles.append(_tile("plus", "violet", "My pending OT", my_pending_ot, reverse("overtime.index"), "View overtime"))
    if can_approve:
        tiles.append(_tile("check", "amber" if pending_approvals else "slate", "Awaiting your approval", pending_approvals, reverse("leave.index"), "Review requests",
                           value_class="text-amber-600 dark:text-amber-300" if pending_approvals else ""))
    if can_manage and active_employees is not None:
        tiles.append(_tile("users", "brand", "Active employees", active_employees, reverse("employees.index"), "Manage employees"))
    if online_now is not None:
        tiles.append(_tile("wifi", "emerald", "Online now", online_now, reverse("users.index") + "?status=online", "User Management", value_class="text-emerald-600 dark:text-emerald-400"))
    if can_payroll and pay_day:
        st = pay_day["state"]
        tone = {"overdue": "rose", "due": "amber", "computed": "brand", "upcoming": "accent"}[st]
        vclass = {"overdue": "text-rose-600 dark:text-rose-400", "due": "text-amber-600 dark:text-amber-300", "computed": "text-brand-700 dark:text-brand-300", "upcoming": ""}[st]
        if st == "upcoming":
            sub = ("Today" if pay_day["days"] == 0 else f"in {pay_day['days']} day{'' if pay_day['days'] == 1 else 's'}") + f" · {pay_day['pay_date']:%A}"
        else:
            sub = pay_day["message"][:60] + ("…" if len(pay_day["message"]) > 60 else "")
        href = reverse("payroll.index") + (f"?period={pay_day['period'].id}" if pay_day["period"] else "")
        tiles.append(_tile("cash", tone, "Payroll", f"Next pay day {pay_day['pay_date']:%b %-d}" if st == "upcoming" else pay_day["title"], href,
                           {"overdue": "Run payroll", "due": "Run payroll", "computed": "Review & release", "upcoming": "Open payroll"}[st], small=True, sub=sub, value_class=vclass))
    if not can_clock and not can_approve:
        tiles.append(_tile("list", "slate", "My attendance", "—", reverse("attendance.index"), "View history"))
    if len(tiles) < 4 and employee and can_clock:
        nxt = _cutoff_for(today)["end"]
        tiles.append(_tile("cash", "emerald", "Next pay day", f"{nxt:%b %-d}", reverse("payroll.mine"), "My payslips",
                           sub=f"{nxt:%A} · {'1st–15th' if nxt.day == 15 else '16th–end'} cutoff"))
    tiles = tiles[:4]

    if can_clock:
        actions.append(_action("clock", "brand", "Clock in / out", "Selfie + GPS punch", reverse("attendance.create")))
    if user.can("request leave"):
        actions.append(_action("calendar", "accent", "File leave", "Vacation, sick, early leave", reverse("leave.create")))
    if user.can("request overtime"):
        actions.append(_action("plus", "violet", "Request overtime", "Pre-approval for extra hours", reverse("overtime.create")))
    if can_approve:
        actions.append(_action("check", "amber", "Review requests", f"{pending_approvals} pending", reverse("leave.index")))
    if user.can("view team reports"):
        actions.append(_action("list", "slate", "Attendance log", "Everyone's time in / out", reverse("attendance.monitor")))
    if can_manage:
        actions.append(_action("users", "brand", "Add employee", "Creates the login account too", reverse("employees.create")))
    if user.can("view checkpoint module"):
        actions.append(_action("shield", "emerald", "Check Point", "Random presence checks", reverse("checkpoints.index")))
    if user.can_any("manage settings", "manage sites"):
        actions.append(_action("pin", "accent", "Locations", "Sites and geofences", reverse("sites.index")))
    if user.can("manage users"):
        actions.append(_action("usercog", "violet", "User Management", "Accounts, roles, presence", reverse("users.index")))

    recent_logs, recent_requests = [], []
    if employee and can_clock:
        recent_logs = list(employee.attendance_logs.select_related("site").order_by("-logged_at")[:5])
        leave = [{"kind": r.kind_label, "when": r.when_label, "status": r.status, "at": r.created_at, "href": reverse("leave.index")} for r in employee.leave_requests.select_related("leave_type").order_by("-id")[:5]]
        ot = [{"kind": "Overtime", "when": f"{r.ot_date:%b %-d}", "status": r.status, "at": r.created_at, "href": reverse("overtime.index")} for r in employee.overtime_requests.order_by("-id")[:5]]
        recent_requests = sorted(leave + ot, key=lambda r: r["at"] or timezone.now(), reverse=True)[:5]

    return render(request, "dashboard.html", {
        "employee": employee, "is_clocked_in": is_clocked_in, "can_clock": can_clock,
        "tiles": tiles, "actions": actions, "recent_logs": recent_logs, "recent_requests": recent_requests,
        "first_name": (user.name or user.username).split()[0],
    })


@login_required
def notification_open(request, pk):
    note = get_object_or_404(Notification, pk=pk, user=request.user)
    note.mark_read()
    return redirect(note.url or reverse("dashboard"))


@login_required
@require_POST
def notifications_read_all(request):
    request.user.notifications.filter(read_at__isnull=True).update(read_at=timezone.now())
    messages.success(request, "All notifications marked as read.")
    return redirect(request.META.get("HTTP_REFERER") or "dashboard")


def home(request):
    return redirect("dashboard" if request.user.is_authenticated else "login")
