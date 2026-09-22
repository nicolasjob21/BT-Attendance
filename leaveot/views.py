from datetime import date, datetime, timedelta

from django.contrib import messages
from django.core.paginator import Paginator
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse
from django.utils import timezone
from django.views.decorators.http import require_POST

from accounts.decorators import employee_required, permission_required
from core.models import notify, users_with_permission

from .models import LeaveRequest, LeaveType, OvertimeRequest
from .overtime import refresh_payroll_line, sync_actual_hours

LATE_FILING_DAYS = 3


def _notify_approvers(kind, employee_name, summary, url):
    notify(users_with_permission("approve requests"), kind="request", title=f"{kind} request from {employee_name}", message=summary, url=url)


def _fmt_days(v):
    s = f"{float(v or 0):.1f}".rstrip("0").rstrip(".")
    return s or "0"


# ---- leave ----

@permission_required("request leave", "approve requests")
def leave_index(request):
    employee = getattr(request.user, "employee", None)
    can_approve = request.user.can("approve requests")
    qs = LeaveRequest.objects.select_related("employee", "leave_type", "approved_by").order_by("-id")
    if not can_approve and employee:
        qs = qs.filter(employee=employee)
    scope = LeaveRequest.objects.filter(employee=employee) if (not can_approve and employee) else LeaveRequest.objects.all()
    today = timezone.now().date()
    stats = {
        "pending": scope.filter(status="pending").count(),
        "approved_month": scope.filter(status="approved", date_from__year=today.year, date_from__month=today.month).count(),
        "days_year": _fmt_days(sum(float(d) for d in scope.filter(status="approved", date_from__year=today.year).values_list("days", flat=True))),
        "denied_year": scope.filter(status="denied", date_from__year=today.year).count(),
    }
    return render(request, "leave/index.html", {"requests": Paginator(qs, 20).get_page(request.GET.get("page")), "can_approve": can_approve, "stats": stats, "employee": employee})


@permission_required("request leave")
@employee_required
def leave_create(request):
    employee = request.user.employee
    today = timezone.now().date()
    facts = [
        {"label": "Pending", "value": employee.leave_requests.filter(status="pending").count(), "hint": "awaiting approval", "tone": "warn"},
        {"label": "Days this year", "value": _fmt_days(sum(float(d) for d in employee.leave_requests.filter(status="approved", date_from__year=today.year).values_list("days", flat=True))), "hint": "approved leave", "tone": "brand"},
    ]
    return render(request, "leave/create.html", {
        "leave_types": LeaveType.objects.order_by("name"), "facts": facts,
        "errors": request.session.pop("form_errors", None), "old": request.session.pop("form_old", None) or {},
        "back_url": reverse("leave.index"), "back_label": "Back to Leave",
        "leave_bullets": [
            '<b class="text-gray-800 dark:text-slate-100">Full day</b> — pick the leave type and the dates; consecutive days count once each.',
            '<b class="text-gray-800 dark:text-slate-100">Half day</b> — morning or afternoon; counted as 0.5 day and excuses the missing half in payroll once approved.',
            f'<b class="text-gray-800 dark:text-slate-100">Leaving early or sick today?</b> Use <a href="{reverse("leave.early.create")}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">Go home early / Sick</a> instead — it records your planned time out.',
            "Unapproved absences and undertime are deducted at your daily / per-minute rate, so file before the day when you can.",
        ],
    })


@permission_required("request leave")
@employee_required
@require_POST
def leave_store(request):
    employee = request.user.employee
    d = {k: (request.POST.get(k) or "").strip() for k in ("leave_type_id", "day_portion", "date_from", "date_to", "reason")}
    errors = {}
    if d["day_portion"] not in ("full", "half_am", "half_pm"):
        errors["day_portion"] = "Pick a duration."
    leave_type = LeaveType.objects.filter(pk=d["leave_type_id"]).first() if d["leave_type_id"].isdigit() else None
    if d["day_portion"] == "full" and not leave_type:
        errors["leave_type_id"] = "Please choose a leave type for a full-day leave."
    try:
        date_from = date.fromisoformat(d["date_from"])
    except ValueError:
        date_from = None
        errors["date_from"] = "Pick a start date."
    date_to = None
    if d["day_portion"] == "full":
        try:
            date_to = date.fromisoformat(d["date_to"])
            if date_from and date_to < date_from:
                errors["date_to"] = "The end date must be on or after the start date."
        except ValueError:
            errors["date_to"] = "Please choose an end date for a full-day leave."
    elif date_from:
        date_to = date_from
    if errors:
        request.session["form_errors"], request.session["form_old"] = errors, d
        return redirect("leave.create")
    days = (date_to - date_from).days + 1 if d["day_portion"] == "full" else 0.5
    LeaveRequest.objects.create(employee=employee, leave_type=leave_type, day_portion=d["day_portion"], date_from=date_from, date_to=date_to, days=days, reason=d["reason"] or None, status="pending")
    portion = f"{days} day(s) from {date_from:%b %-d}" if d["day_portion"] == "full" else f"Half day on {date_from:%b %-d}"
    _notify_approvers("Leave", employee.full_name, portion, reverse("leave.index"))
    messages.success(request, "Leave request submitted for approval.")
    return redirect("leave.index")


@permission_required("request leave")
@employee_required
def early_create(request):
    employee = request.user.employee
    today = timezone.now().date()
    scheduled_out = employee.schedule.time_out if employee.schedule else None
    facts = [
        {"label": "Scheduled out", "value": f"{scheduled_out:%-I:%M %p}" if scheduled_out else "—", "hint": "your normal time out"},
        {"label": "Early leaves", "value": employee.leave_requests.filter(is_early_leave=True, date_from__year=today.year, date_from__month=today.month).count(), "hint": "this month"},
    ]
    return render(request, "leave/early.html", {
        "scheduled_out": scheduled_out, "facts": facts, "default_time": timezone.now().strftime("%H:%M"),
        "errors": request.session.pop("form_errors", None), "old": request.session.pop("form_old", None) or {},
        "back_url": reverse("attendance.create"), "back_label": "Back to Clock In / Out",
        "early_bullets": [
            "HR is notified immediately and approves or denies it — usually the same day.",
            '<b class="text-gray-800 dark:text-slate-100">Approved</b>: clocking out before your scheduled time is <b>not</b> counted as undertime for that day.',
            '<b class="text-gray-800 dark:text-slate-100">Not approved</b>: the minutes before your scheduled time out are deducted at your per-minute rate.',
            "Still clock out on your phone when you leave — this request does not replace the punch.",
        ],
    })


@permission_required("request leave")
@employee_required
@require_POST
def early_store(request):
    employee = request.user.employee
    t, reason = (request.POST.get("requested_time_out") or "").strip(), (request.POST.get("reason") or "").strip()
    errors = {}
    try:
        requested = datetime.strptime(t, "%H:%M").time()
    except ValueError:
        requested = None
        errors["requested_time_out"] = "Please enter the time you need to leave."
    if not reason:
        errors["reason"] = "Please give a reason (e.g. not feeling well)."
    if errors:
        request.session["form_errors"], request.session["form_old"] = errors, {"requested_time_out": t, "reason": reason}
        return redirect("leave.early.create")
    today = timezone.now().date()
    sick = LeaveType.objects.filter(code="SL").first()
    LeaveRequest.objects.create(employee=employee, leave_type=sick, day_portion="half_pm", is_early_leave=True, requested_time_out=requested, date_from=today, date_to=today, days=0.5, reason=reason, status="pending")
    _notify_approvers("Early leave", employee.full_name, f"Sick — out by {requested:%-I:%M %p} today", reverse("leave.index"))
    messages.success(request, "Early-leave request submitted for HR approval.")
    return redirect("leave.index")


def _decide_leave(request, leave, status):
    leave.status, leave.approved_by, leave.approved_at = status, getattr(request.user, "employee", None), timezone.now()
    leave.save()
    if leave.employee.user:
        notify(leave.employee.user, kind="approved" if status == "approved" else "rejected", title=f"{'Early leave' if leave.is_early_leave else 'Leave'} {status}", message=f"Your request for {leave.when_label} was {status}.", url=reverse("leave.index"))


@permission_required("approve requests")
@require_POST
def leave_approve(request, pk):
    _decide_leave(request, get_object_or_404(LeaveRequest.objects.select_related("employee__user"), pk=pk), "approved")
    messages.success(request, "Leave request approved.")
    return redirect(request.META.get("HTTP_REFERER") or "leave.index")


@permission_required("approve requests")
@require_POST
def leave_deny(request, pk):
    _decide_leave(request, get_object_or_404(LeaveRequest.objects.select_related("employee__user"), pk=pk), "denied")
    messages.success(request, "Leave request denied.")
    return redirect(request.META.get("HTTP_REFERER") or "leave.index")


# ---- overtime ----

def _planned_hours(start, end):
    s = datetime.combine(date.today(), start)
    e = datetime.combine(date.today(), end)
    if e <= s:
        e += timedelta(days=1)
    return round((e - s).total_seconds() / 3600, 2)


@permission_required("request overtime", "approve requests")
def overtime_index(request):
    employee = getattr(request.user, "employee", None)
    can_approve = request.user.can("approve requests")
    qs = OvertimeRequest.objects.select_related("employee__schedule", "approved_by").order_by("-ot_date", "-id")
    if not can_approve and employee:
        qs = qs.filter(employee=employee)
    page = Paginator(qs, 20).get_page(request.GET.get("page"))
    for r in page.object_list:
        r.calc = sync_actual_hours(r)
    scope = OvertimeRequest.objects.filter(employee=employee) if (not can_approve and employee) else OvertimeRequest.objects.all()
    today = timezone.now().date()
    stats = {
        "pending": scope.filter(status="pending").count(),
        "approved_month": scope.filter(status="approved", ot_date__year=today.year, ot_date__month=today.month).count(),
        "hours_month": _fmt_days(sum(float(h) for h in scope.filter(status="approved", ot_date__year=today.year, ot_date__month=today.month).values_list("hours", flat=True) if h is not None)),
        "denied_year": scope.filter(status="denied", ot_date__year=today.year).count(),
    }
    return render(request, "overtime/index.html", {"requests": page, "can_approve": can_approve, "stats": stats, "employee": employee, "today": today})


@permission_required("request overtime")
@employee_required
def overtime_create(request):
    employee = request.user.employee
    today = timezone.now().date()
    default_start = employee.schedule.time_out.strftime("%H:%M") if (employee.schedule and employee.schedule.time_out) else "18:00"
    facts = [
        {"label": "Pending", "value": employee.overtime_requests.filter(status="pending").count(), "hint": "awaiting approval", "tone": "warn"},
        {"label": "OT hours", "value": _fmt_days(sum(float(h) for h in employee.overtime_requests.filter(status="approved", ot_date__year=today.year, ot_date__month=today.month).values_list("hours", flat=True) if h is not None)), "hint": f"approved · {today:%B}", "tone": "brand"},
    ]
    return render(request, "overtime/create.html", {
        "default_start": default_start, "rest_day_start": "08:00", "rest_day_end": "17:00",
        "min_date": (today - timedelta(days=LATE_FILING_DAYS)).isoformat(), "today": today.isoformat(), "facts": facts,
        "errors": request.session.pop("form_errors", None), "old": request.session.pop("form_old", None) or {},
        "back_url": reverse("overtime.index"), "back_label": "Back to Overtime",
        "ot_bullets": [
            'Overtime counts <b class="text-gray-800 dark:text-slate-100">only when approved</b> — file before you stay late; HR can still approve up to a few days after.',
            '<b class="text-gray-800 dark:text-slate-100">Weekday</b>: hours after 5:30 PM at 125% of your hourly rate.',
            '<b class="text-gray-800 dark:text-slate-100">Saturday / Sunday</b>: a rest-day shift — every hour worked is overtime at 130%.',
            "The actual hours come from your clock-out, capped at what you requested — so clock out when you finish.",
        ],
    })


@permission_required("request overtime")
@employee_required
@require_POST
def overtime_store(request):
    employee = request.user.employee
    d = {k: (request.POST.get(k) or "").strip() for k in ("ot_date", "planned_start", "planned_end", "reason")}
    errors = {}
    today = timezone.now().date()
    try:
        ot_date = date.fromisoformat(d["ot_date"])
        if ot_date < today - timedelta(days=LATE_FILING_DAYS):
            errors["ot_date"] = f"Overtime must be requested in advance — pick today or a future date (up to {LATE_FILING_DAYS} days back is allowed for late filing, e.g. weekend site work filed on Monday)."
    except ValueError:
        ot_date = None
        errors["ot_date"] = "Pick a date."
    try:
        start, end = datetime.strptime(d["planned_start"], "%H:%M").time(), datetime.strptime(d["planned_end"], "%H:%M").time()
    except ValueError:
        start = end = None
        errors["planned_end"] = "Enter the planned start and end."
    if len(d["reason"]) < 10:
        errors["reason"] = "Tell your approver what the overtime is for (the task or work to be done)." if not d["reason"] else "Please describe the work in a bit more detail."
    requested = _planned_hours(start, end) if start and end else 0
    if start and end and (requested <= 0 or requested > 12):
        errors["planned_end"] = "The planned window must be between 15 minutes and 12 hours."
    if ot_date and employee.overtime_requests.filter(ot_date=ot_date, status__in=["pending", "approved"]).exists():
        errors["ot_date"] = "You already have a pending or approved overtime request for that date."
    if errors:
        request.session["form_errors"], request.session["form_old"] = errors, d
        return redirect("overtime.create")
    ot = OvertimeRequest.objects.create(employee=employee, ot_date=ot_date, planned_start=start, planned_end=end, requested_hours=requested, hours=None, ot_type="rest_day" if ot_date.weekday() >= 5 else "regular", reason=d["reason"], status="pending")
    _notify_approvers("Overtime", employee.full_name, f"{requested}h on {ot.ot_date:%b %-d} ({ot.planned_window()}) — {d['reason'][:80]}", reverse("overtime.index"))
    messages.success(request, f"Overtime request for {requested}h on {ot.ot_date:%b %-d} sent for approval.")
    return redirect("overtime.index")


def _decide_ot(request, ot, status):
    if ot.status != "pending":
        messages.error(request, "This request has already been decided.")
        return False
    ot.status, ot.admin_remarks = status, (request.POST.get("remarks") or "").strip() or None
    ot.approved_by, ot.approved_at = getattr(request.user, "employee", None), timezone.now()
    ot.save()
    if ot.employee.user:
        notify(ot.employee.user, kind="approved" if status == "approved" else "rejected", title=f"Overtime {status}", message=f"Your overtime request for {ot.ot_date:%b %-d} was {status}.", url=reverse("overtime.index"))
    return True


@permission_required("approve requests")
@require_POST
def overtime_approve(request, pk):
    ot = get_object_or_404(OvertimeRequest.objects.select_related("employee__user", "employee__schedule"), pk=pk)
    if _decide_ot(request, ot, "approved"):
        ot.refresh_from_db()
        sync_actual_hours(ot)
        refresh_payroll_line(ot)
        messages.success(request, "Overtime request approved.")
    return redirect(request.META.get("HTTP_REFERER") or "overtime.index")


@permission_required("approve requests")
@require_POST
def overtime_deny(request, pk):
    ot = get_object_or_404(OvertimeRequest.objects.select_related("employee__user"), pk=pk)
    if _decide_ot(request, ot, "denied"):
        messages.success(request, "Overtime request denied.")
    return redirect(request.META.get("HTTP_REFERER") or "overtime.index")


@permission_required("approve requests")
@require_POST
def overtime_cancel(request, pk):
    """Withdraw an approval — the employee was sick, sent home, or the work fell through. Nothing is paid for it."""
    ot = get_object_or_404(OvertimeRequest.objects.select_related("employee__user"), pk=pk)
    if not ot.can_cancel():
        messages.error(request, "Only an approved request that has not been paid yet can be cancelled.")
        return redirect(request.META.get("HTTP_REFERER") or "overtime.index")
    ot.status, ot.cancel_reason = "cancelled", (request.POST.get("reason") or "").strip() or None
    ot.cancelled_by, ot.cancelled_at = getattr(request.user, "employee", None), timezone.now()
    ot.save()
    refresh_payroll_line(ot)
    if ot.employee.user:
        notify(ot.employee.user, kind="rejected", title="Overtime cancelled", message=f"Your approved overtime for {ot.ot_date:%b %-d} was cancelled by HR{f' — {ot.cancel_reason}' if ot.cancel_reason else ''}.", url=reverse("overtime.index"))
    messages.success(request, f"Overtime for {ot.ot_date:%b %-d} cancelled — it will not be paid.")
    return redirect(request.META.get("HTTP_REFERER") or "overtime.index")


