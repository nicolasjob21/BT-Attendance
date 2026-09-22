import calendar
import json
import os
import secrets
from collections import defaultdict
from datetime import date, datetime, timedelta

from django.conf import settings
from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.core.exceptions import PermissionDenied
from django.core.paginator import Paginator
from django.http import Http404, HttpResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse
from django.utils import timezone
from django.views.decorators.http import require_POST

from accounts.decorators import employee_required, permission_required
from core.htmx import is_htmx, render_partial
from core.models import notify, users_with_permission
from core.photos import decode_data_url
from core.support import media_path
from employees.models import Employee, Site
from leaveot.overtime import resync_day

from . import sessions as ws, softcopy
from .geofence import GeofenceService
from .models import AttendanceLog


def _own_or_reports(request, employee_id):
    me = getattr(request.user, "employee", None)
    if not (me and me.id == employee_id) and not request.user.can("view team reports"):
        raise PermissionDenied


# ---- clock in / out ----

@permission_required("clock attendance")
@employee_required
def create(request):
    employee = request.user.employee
    geofence = GeofenceService()
    last_log = employee.attendance_logs.order_by("-logged_at").first()
    next_action = "time_out" if (last_log and last_log.log_type == "time_in") else "time_in"
    sites = list(Site.objects.active_on().order_by("-type", "name"))  # office first
    sites.sort(key=lambda s: (0 if s.type == "office" else 1, s.name))
    assigned = employee.assigned_site()
    return render(request, "attendance/create.html", {
        "last_log": last_log, "next_action": next_action, "employee_name": employee.full_name,
        "sites_js": ([{"id": s.id, "name": s.name, "type": s.type, "address": s.address, "latitude": float(s.latitude), "longitude": float(s.longitude), "geofence_radius_m": s.geofence_radius_m} for s in sites]),
        "assigned_site_id": assigned.id if assigned else None, "assigned_site_name": assigned.name if assigned else None,
        "geofence_mode": geofence.mode(), "min_accuracy": geofence.min_accuracy_meters(),
        "immersive": True, "hide_checkpoint_alert": False,
        "errors": request.session.pop("clock_errors", None), "old": request.session.pop("clock_old", None) or {},
    })


@permission_required("clock attendance")
@employee_required
@require_POST
def store(request):
    employee = request.user.employee
    geofence = GeofenceService()
    log_type = request.POST.get("log_type")
    if log_type not in ("time_in", "time_out"):
        raise Http404
    def _f(name):
        v = request.POST.get(name, "")
        try:
            return float(v) if v not in ("", None) else None
        except ValueError:
            return None
    lat, lng, accuracy = _f("latitude"), _f("longitude"), _f("gps_accuracy")
    if (lat is None) != (lng is None):
        lat = lng = None
    photo = request.POST.get("photo", "")
    if decode_data_url(photo) is None:
        request.session["clock_errors"] = {"photo": "Take a selfie before submitting."}
        return redirect("attendance.create")

    result = geofence.evaluate(employee, lat, lng, accuracy)
    if geofence.blocks(result):
        request.session["clock_errors"] = {"latitude": f"{result.message} You must be inside an authorized work site to {'clock in' if log_type == 'time_in' else 'clock out'}."}
        return redirect("attendance.create")
    reason = (request.POST.get("location_reason") or "").strip() if result.is_exception() else ""
    if result.is_exception() and not reason:
        request.session["clock_errors"] = {"location_reason": f"Please tell HR why you are clocking {'in' if log_type == 'time_in' else 'out'} outside the authorized area (e.g. approved temporary location, GPS inaccurate)."}
        return redirect("attendance.create")

    binary, ext = decode_data_url(photo)
    rel = f"attendance/{employee.id}/{timezone.now():%Y%m%d_%H%M%S}_{secrets.token_hex(3)}.{ext}"
    dest = media_path(rel)
    os.makedirs(dest.parent, exist_ok=True)
    dest.write_bytes(binary)

    now = timezone.now()
    log = AttendanceLog.objects.create(
        employee=employee, log_type=log_type, logged_at=now, latitude=lat, longitude=lng, photo_path=rel,
        synced_offline=request.POST.get("synced_offline") in ("1", "true", "on"),
        location_reason=reason or None, location_verification_status=geofence.initial_verification_status(result),
        **result.to_log_attributes(),
    )
    verb = "Clocked in" if log_type == "time_in" else "Clocked out"
    msg = f"{verb} at {now:%-I:%M %p}."
    if result.status == GeofenceService.AUTHORIZED_ALTERNATE_LOCATION:
        msg += f" Recorded at {result.site.name} (your assigned project is {result.assigned_site.name})."
    elif result.is_exception():
        msg += " Your location could not be verified — this punch is pending HR approval." if log.location_verification_status == "pending" else " Your location could not be verified — HR has been notified to review it."
        reviewers = users_with_permission("approve requests").exclude(pk=employee.user_id)
        notify(reviewers, kind="attendance", title="Location exception", message=f"{employee.full_name} clocked {'in' if log_type == 'time_in' else 'out'} {GeofenceService.label(result.status).lower()} at {now:%-I:%M %p}.", url=reverse("attendance.monitor") + f"?date={now:%Y-%m-%d}")
    if log_type == "time_in" and softcopy.status(log) == "Early In":
        msg += " You clocked in early — that's fine, it does not affect your pay."
    messages.success(request, msg)
    request.session["softcopy_log_id"], request.session["softcopy_type"] = log.id, "in" if log_type == "time_in" else "out"
    return redirect("attendance.index")


def soft_copy(request, pk, kind):
    if not request.user.is_authenticated:
        raise PermissionDenied
    if kind not in ("in", "out"):
        raise Http404
    log = get_object_or_404(AttendanceLog.objects.select_related("employee__schedule", "site"), pk=pk)
    if log.log_type != ("time_in" if kind == "in" else "time_out"):
        raise Http404
    _own_or_reports(request, log.employee_id)
    resp = HttpResponse(softcopy.png(log), content_type="image/png")
    resp["Content-Disposition"] = f'attachment; filename="{softcopy.filename(log)}"'
    return resp


# ---- my attendance ----

@permission_required("clock attendance")
@employee_required
def index(request):
    employee = request.user.employee
    qs = employee.attendance_logs.select_related("site", "assigned_site", "location_verified_by").order_by("-logged_at")
    logs = Paginator(qs, 20).get_page(request.GET.get("page"))
    today = timezone.now().date()
    start = today.replace(day=1)
    end = today.replace(day=calendar.monthrange(today.year, today.month)[1])
    month_logs = list(employee.attendance_logs.filter(logged_at__date__range=(start, end)).order_by("logged_at"))
    sessions = ws.pair(month_logs)
    schedule = employee.schedule
    late = 0
    if schedule and schedule.time_in:
        by_day = defaultdict(list)
        for s in sessions:
            by_day[s["in"].logged_at.date()].append(s)
        for day, group in by_day.items():
            first = group[0]["in"].logged_at
            expected = datetime.combine(day, schedule.time_in) + timedelta(minutes=int(schedule.grace_minutes or 0))
            if first > expected:
                late += 1
    month = {
        "days": len({s["in"].logged_at.date() for s in sessions}), "minutes": ws.worked_minutes(sessions), "late": late,
        "last": employee.attendance_logs.order_by("-logged_at").first(),
    }
    return render(request, "attendance/index.html", {
        "logs": logs, "month": month, "today": today,
        "softcopy_log_id": request.session.pop("softcopy_log_id", None), "softcopy_type": request.session.pop("softcopy_type", None),
        "statuses": {l.id: softcopy.status(l) for l in logs.object_list},
    })


# ---- attendance log (monitor) ----

def _monitor_day(params):
    try:
        return date.fromisoformat(params.get("date", "")) if params.get("date") else timezone.now().date()
    except ValueError:
        return timezone.now().date()


def _monitor_rows(day, employees):
    """One row per employee for the day: paired sessions, hours split, flags. Shared by the page and the row swaps."""
    is_rest_day = day.weekday() >= 5
    window_start = datetime.combine(day, datetime.min.time())
    window_end = datetime.combine(day + timedelta(days=1), datetime.max.time())
    logs = AttendanceLog.objects.filter(logged_at__range=(window_start, window_end), employee_id__in=[e.id for e in employees]).select_related("site", "assigned_site", "ot_verified_by", "location_verified_by").order_by("logged_at")
    by_emp = defaultdict(list)
    for l in logs:
        by_emp[l.employee_id].append(l)
    rows = []
    for emp in employees:
        group = by_emp.get(emp.id, [])
        sess = ws.starting_on(ws.pair(group), day)
        minutes = ws.worked_minutes(sess)
        split = ws.split(minutes, is_rest_day)
        closing = ws.closing_out(sess)
        needs = ws.needs_verification(minutes) and closing is not None
        exceptions = [l for l in group if l.has_location_exception()]
        rest_req = emp.overtime_requests.filter(ot_date=day, status__in=["pending", "approved"]).first() if (is_rest_day and sess) else None
        rows.append({
            "employee": emp, "rest_day_request": rest_req, "location_exceptions": exceptions,
            "time_in": sess[0]["in"] if sess else None, "time_out": closing, "minutes": minutes, "hours": ws.label(minutes) if minutes else None,
            "regular_minutes": split["regular"], "ot_minutes": split["overtime"], "rest_day": is_rest_day, "sessions": len(sess), "open": ws.has_open(sess),
            "needs_verification": needs, "verify_log_id": closing.id if closing else None,
            "verification_status": closing.ot_verification_status if closing else None, "verification_remarks": closing.ot_remarks if closing else None,
            "verified_by": closing.ot_verified_by.name if (closing and closing.ot_verified_by) else None, "verified_at": closing.ot_verified_at if closing else None,
        })
    return rows


def _monitor_context(params):
    """Everything the Attendance Log shows for one date + search (`params` is GET for the page, POST for a row action)."""
    day = _monitor_day(params)
    search = params.get("search", "").strip()
    is_rest_day = day.weekday() >= 5
    employees = list(Employee.objects.active().search(search).select_related("schedule").order_by("first_name", "last_name"))
    rows = _monitor_rows(day, employees)
    present = sum(1 for r in rows if r["time_in"])
    today = timezone.now().date()
    return {
        "rows": rows, "date": day, "search": search, "present": present, "absent": len(rows) - present,
        "still_in": sum(1 for r in rows if r["open"]), "ot_minutes": sum(r["ot_minutes"] for r in rows),
        "needs_check": sum(1 for r in rows if r["needs_verification"] or r["location_exceptions"]), "is_rest_day": is_rest_day,
        "prev_day": day - timedelta(days=1), "next_day": day + timedelta(days=1), "today": today, "is_today": day == today,
    }


@permission_required("view team reports")
def monitor(request):
    ctx = _monitor_context(request.GET)
    if is_htmx(request):  # date stepper / search: swap the results (and the stepper, out of band)
        return render_partial(request, "attendance/_monitor_results.html", ctx)
    return render(request, "attendance/monitor.html", ctx)


def _monitor_row_response(request, employee_id, error=None, error_form=None, error_log_id=None):
    """After an HR decision from the log: the employee's row, plus the stat strip out of band."""
    ctx = _monitor_context(request.POST)
    row = next((r for r in ctx["rows"] if r["employee"].id == employee_id), None)
    if row is None:  # filtered out since the page was rendered — still answer with the row itself
        row = _monitor_rows(ctx["date"], [get_object_or_404(Employee.objects.select_related("schedule"), pk=employee_id)])[0]
    ctx.update(row=row, form_error=error, error_form=error_form, error_log_id=error_log_id)
    return render_partial(request, "attendance/_monitor_row.html", ctx, oob=[("attendance/_monitor_summary.html", ctx)])


# ---- timesheet ----

@login_required
def timesheet(request, pk):
    employee = get_object_or_404(Employee.objects.select_related("schedule"), pk=pk)
    _own_or_reports(request, employee.id)
    try:
        month = datetime.strptime(request.GET.get("month", ""), "%Y-%m").date().replace(day=1) if request.GET.get("month") else timezone.now().date().replace(day=1)
    except ValueError:
        month = timezone.now().date().replace(day=1)
    month_end = month.replace(day=calendar.monthrange(month.year, month.month)[1])
    today = timezone.now().date()
    logs = list(employee.attendance_logs.filter(logged_at__range=(datetime.combine(month, datetime.min.time()), datetime.combine(month_end + timedelta(days=1), datetime.max.time()))).select_related("site", "ot_verified_by").order_by("logged_at"))
    all_sessions = ws.pair(logs)
    leaves = list(employee.leave_requests.filter(status="approved", date_from__lte=month_end, date_to__gte=month).select_related("leave_type"))
    approved_ot = {ot.ot_date: ot for ot in employee.overtime_requests.filter(status="approved", ot_date__range=(month, month_end))}
    days, totals = [], {"worked": 0, "regular": 0, "overtime": 0, "present": 0, "absent": 0, "leave": 0, "exceptions": 0}
    d = month
    while d <= month_end:
        is_rest = d.weekday() >= 5
        sess = ws.starting_on(all_sessions, d)
        minutes = ws.worked_minutes(sess)
        split = ws.split(minutes, is_rest)
        first_in = sess[0]["in"] if sess else None
        out = ws.closing_out(sess)
        leave = next((l for l in leaves if l.date_from <= d <= l.date_to), None)
        punches = [p for s in sess for p in (s["in"], s["out"]) if p]
        exception = next((p for p in punches if p.has_location_exception()), None)
        if first_in and out:
            status = "present"
        elif first_in:
            status = "open" if d == today else "incomplete"
        elif leave:
            status = "leave"
        elif is_rest:
            status = "rest"
        elif d > today:
            status = "upcoming"
        else:
            status = "absent"
        if status in ("present", "incomplete", "open"):
            totals["present"] += 1
        elif status == "absent":
            totals["absent"] += 1
        elif status == "leave":
            totals["leave"] += 1
        totals["worked"] += minutes
        totals["regular"] += split["regular"]
        totals["overtime"] += split["overtime"]
        if exception:
            totals["exceptions"] += 1
        days.append({"date": d, "rest_day": is_rest, "status": status, "in": first_in, "out": out, "sessions": len(sess), "minutes": minutes,
                     "regular": split["regular"], "overtime": split["overtime"], "leave": leave, "ot_request": approved_ot.get(d), "exception": exception,
                     "site": first_in.site.name if (first_in and first_in.site) else None, "is_today": d == today, "muted": status in ("rest", "upcoming") and not first_in})
        d += timedelta(days=1)
    expected = sum(1 for i in range((min(month_end, today) - month).days + 1) if (month + timedelta(days=i)).weekday() < 5) if today >= month else 0
    prev_m = (month - timedelta(days=1)).replace(day=1)
    next_m = (month_end + timedelta(days=1))
    return render(request, "attendance/timesheet.html", {
        "employee": employee, "month": month, "days": days, "totals": totals, "expected_days": expected,
        "has_fixed_schedule": bool(employee.schedule and employee.schedule.time_in), "can_review": request.user.can("view team reports"),
        "prev_month": f"{prev_m:%Y-%m}", "next_month": f"{next_m:%Y-%m}", "this_month": f"{today:%Y-%m}", "is_current_month": month.month == today.month and month.year == today.year,
        "today": today, "back_url": reverse("attendance.monitor") if request.user.can("view team reports") else reverse("attendance.index"), "back_label": "Back",
    })


# ---- HR verification ----

@permission_required("approve requests")
@require_POST
def verify(request, pk):
    log = get_object_or_404(AttendanceLog.objects.select_related("employee__user"), pk=pk)
    decision, remarks = request.POST.get("decision"), (request.POST.get("remarks") or "").strip()
    error = None
    if log.log_type != "time_out":
        error = "Verification attaches to a clock-out event."
    elif decision not in ("approved", "rejected") or not remarks:
        error = "Choose a decision and give a reason."
    if error:
        if is_htmx(request):  # keep the form open with the message next to it
            return _monitor_row_response(request, log.employee_id, error, "ot", log.id)
        messages.error(request, error)
        return redirect(request.META.get("HTTP_REFERER") or "attendance.monitor")
    log.ot_verification_status, log.ot_remarks, log.ot_verified_by, log.ot_verified_at = decision, remarks, request.user, timezone.now()
    log.save()
    resync_day(log.employee, log.logged_at.date())  # the decision is what payroll pays from
    if log.employee.user:
        notify(log.employee.user, kind="approved" if decision == "approved" else "rejected", title=f"Overtime {decision}", message=f"Your long day on {log.logged_at:%b %-d} was {decision} by HR.", url=reverse("attendance.index"))
    messages.success(request, f"Overtime {decision} for {log.employee.full_name}.")
    if is_htmx(request):
        return _monitor_row_response(request, log.employee_id)
    return redirect(request.META.get("HTTP_REFERER") or "attendance.monitor")


@permission_required("approve requests")
@require_POST
def verify_location(request, pk):
    log = get_object_or_404(AttendanceLog.objects.select_related("employee__user"), pk=pk)
    decision = request.POST.get("decision")
    error = None
    if not log.has_location_exception():
        error = "This punch has no location exception to review."
    elif decision not in ("approved", "rejected"):
        error = "Choose approve or reject."
    if error:
        if is_htmx(request):
            return _monitor_row_response(request, log.employee_id, error, "location", log.id)
        messages.error(request, error)
        return redirect(request.META.get("HTTP_REFERER") or "attendance.monitor")
    log.location_verification_status, log.location_remarks = decision, (request.POST.get("remarks") or "").strip() or None
    log.location_verified_by, log.location_verified_at = request.user, timezone.now()
    log.save()
    if log.employee.user:
        notify(log.employee.user, kind="approved" if decision == "approved" else "rejected", title=f"Attendance location {decision}", message=f"Your punch on {log.logged_at:%b %-d, %-I:%M %p} was {decision} by HR.", url=reverse("attendance.index"))
    messages.success(request, f"Location {decision} for {log.employee.full_name} ({log.logged_at:%b %-d, %-I:%M %p}).")
    if is_htmx(request):
        return _monitor_row_response(request, log.employee_id)
    return redirect(request.META.get("HTTP_REFERER") or "attendance.monitor")
