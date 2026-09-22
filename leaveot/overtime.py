"""
Actual overtime hours, derived from the day's punches. Shared by the Overtime
page (so employees see their hours fill in) and payroll (so pay never depends
on someone having opened that page).
"""

from datetime import date, datetime, timedelta

from django.utils import timezone

from attendance import sessions as ws

from .models import OvertimeRequest


def compute_overtime(employee, day: date) -> dict:
    """OT = actual out − scheduled out on a fixed weekday; worked time beyond 8h for flexible staff or weekends.

    A 13h+ day is only trusted once HR verifies it on the Attendance Log: `held` until they decide,
    and `hours` is 0 when they reject it.
    """
    logs = employee.attendance_logs.filter(logged_at__range=(datetime.combine(day, datetime.min.time()), datetime.combine(day + timedelta(days=1), datetime.max.time()))).order_by("logged_at")
    sess = ws.starting_on(ws.pair(list(logs)), day)
    is_weekend = day.weekday() >= 5
    ot_type = "rest_day" if is_weekend else "regular"
    closing = ws.closing_out(sess)
    actual_out = closing.logged_at if closing else None
    base = {"ot_type": ot_type, "actual_out": actual_out, "scheduled_out": None, "verification": None, "held": False}
    if not actual_out:
        return {**base, "ok": False, "hours": 0.0, "message": "No clock-out found for that date. Clock out first, then file your overtime."}
    minutes = ws.worked_minutes(sess)
    if ws.needs_verification(minutes):
        base["verification"] = closing.ot_verification_status or "pending"
        if base["verification"] == "rejected":
            return {**base, "ok": False, "hours": 0.0, "message": f"HR rejected the overtime on this clock-out{f' — “{closing.ot_remarks}”' if closing.ot_remarks else ''}."}
        if base["verification"] != "approved":
            return {**base, "ok": False, "held": True, "hours": 0.0, "message": f"This {ws.label(minutes)} day is waiting for HR to verify it on the Attendance Log before its overtime counts."}
    scheduled_time_out = employee.schedule.time_out if employee.schedule else None
    if scheduled_time_out and not is_weekend:
        scheduled_out = datetime.combine(day, scheduled_time_out)
        ot_minutes = max(0, int(round((actual_out - scheduled_out).total_seconds() / 60)))
        hours = round(ot_minutes / 60, 2)
        msg = f"Auto-calculated {hours}h — actual out {actual_out:%-I:%M %p} minus scheduled out {scheduled_out:%-I:%M %p}." if hours > 0 else f"Your time out ({actual_out:%-I:%M %p}) is not past your scheduled out ({scheduled_out:%-I:%M %p}), so there is no overtime."
        return {**base, "ok": hours > 0, "hours": hours, "scheduled_out": scheduled_out, "message": msg}
    hours = round(ws.split(minutes, is_weekend)["overtime"] / 60, 2)
    basis = "weekend rest-day work (all hours are overtime)" if is_weekend else "time worked beyond 8 hours"
    return {**base, "ok": hours > 0, "hours": hours, "message": f"Auto-calculated {hours}h from {basis} — actual out {actual_out:%-I:%M %p}." if hours > 0 else f"No overtime — {basis}."}


def sync_actual_hours(ot: OvertimeRequest):
    """Fill in (or refresh) an approved request's actual hours from the punches once its date has passed.

    Always recomputes, so an HR decision on the Attendance Log flips the hours the next time anyone
    looks. Returns the calculation, or None when the request is not due for a sync.
    """
    if ot.status != "approved" or not ot.employee_id or ot.ot_date > timezone.now().date():
        return None
    calc = compute_overtime(ot.employee, ot.ot_date)
    if not calc["actual_out"]:
        return calc
    hours = None if calc["held"] else calc["hours"]
    current = None if ot.hours is None else float(ot.hours)
    if current != hours or ot.ot_type != calc["ot_type"]:
        ot.hours, ot.ot_type, ot.hours_synced_at = hours, calc["ot_type"], timezone.now()
        ot.save(update_fields=["hours", "ot_type", "hours_synced_at"])
    return calc


def resync_day(employee, day: date):
    """Refresh every approved request that could be paid from this day's punches (an overnight session
    closing on `day` belongs to the request dated the day before)."""
    for ot in OvertimeRequest.objects.filter(employee=employee, status="approved", ot_date__in=(day, day - timedelta(days=1))):
        sync_actual_hours(ot)
        refresh_payroll_line(ot)


def refresh_payroll_line(ot: OvertimeRequest):
    """If the cutoff that pays this OT is already computed but not final, recompute the employee's line now
    so what HR sees before releasing is current — unless they edited that line by hand."""
    from payroll.calculator import PayrollCalculator
    from payroll.models import PayrollItem

    period = ot.payout_period()
    if not period or not period.generated_at or period.is_closed or period.is_released:
        return
    item = PayrollItem.objects.filter(employee_id=ot.employee_id, payroll_period=period).first()
    if item and not item.is_adjusted:
        PayrollCalculator().calculate(ot.employee, period)


def unpaid_watchlist(period) -> dict:
    """Approved-but-unpayable OT in the window a period pays for, so HR can act before releasing:
    requests still pending a decision, and 13h+ days still waiting for HR verification."""
    ot_from, ot_to = period.overtime_window()
    today = timezone.now().date()
    pending = list(OvertimeRequest.objects.filter(status="pending", ot_date__range=(ot_from, ot_to)).select_related("employee"))
    held = []
    for ot in OvertimeRequest.objects.filter(status="approved", ot_date__range=(ot_from, ot_to), ot_date__lte=today).select_related("employee"):
        calc = sync_actual_hours(ot)
        if calc and calc["held"]:
            held.append(ot)
    return {"pending": pending, "held": held, "window": period.overtime_window_label(), "any": bool(pending or held)}
