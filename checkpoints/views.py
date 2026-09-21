import csv
import json
from datetime import date, datetime, timedelta

from django.contrib import messages
from django.core.exceptions import PermissionDenied, ValidationError
from django.core.paginator import Paginator
from django.db.models import Case, Count, IntegerField, Q, Value, When
from django.http import Http404, HttpResponse, JsonResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse
from django.utils import timezone
from django.views.decorators.http import require_POST

from accounts.decorators import employee_required, permission_required
from attendance.geofence import GeofenceService
from employees.models import Employee, Site

from .models import Checkpoint, CheckpointCampaign
from .services import CampaignManager, CheckpointAudit, CheckpointDispatcher, CheckpointPhoto, CheckpointReviewer, CheckpointSettings, CheckpointVerifier, StateError


def _back(request, fallback):
    return redirect(request.META.get("HTTP_REFERER") or fallback)


def _err(request, e, fallback):
    msg = "; ".join(m for v in e.message_dict.values() for m in v) if isinstance(e, ValidationError) and hasattr(e, "message_dict") else str(e)
    messages.error(request, msg)
    return _back(request, fallback)


def _iso(dt):
    return timezone.make_aware(dt).isoformat() if dt and timezone.is_naive(dt) else (dt.isoformat() if dt else None)


# ---- HR: list / history / daily ----

@permission_required("view checkpoint module")
def index(request):
    CheckpointDispatcher(CheckpointAudit(request)).sweep()
    qs = (CheckpointCampaign.objects.select_related("project_site")
          .annotate(participants_count=Count("participants", distinct=True), completed_count=Count("checkpoints", filter=Q(checkpoints__status__in=Checkpoint.COMPLETED_STATUSES), distinct=True))
          .annotate(rank=Case(When(status__in=["active", "paused"], then=Value(0)), When(status="draft", then=Value(1)), default=Value(2), output_field=IntegerField()))
          .order_by("rank", "-starts_at", "-scheduled_start_at", "-created_at"))
    page = Paginator(qs, 20).get_page(request.GET.get("page"))
    today = timezone.now().date()
    stats = {
        "live": CheckpointCampaign.objects.live().count(),
        "scheduled": CheckpointCampaign.objects.filter(status=CheckpointCampaign.DRAFT, scheduled_start_at__isnull=False).count(),
        "today": Checkpoint.objects.filter(created_at__date=today).count(),
        "today_done": Checkpoint.objects.filter(created_at__date=today).completed().count(),
    }
    return render(request, "checkpoints/index.html", {"campaigns": page, "stats": stats})


@permission_required("view checkpoint module")
def history(request):
    status = request.GET.get("status") if request.GET.get("status") in CheckpointCampaign.STATUSES else None
    site_id = int(request.GET["site"]) if request.GET.get("site", "").isdigit() else None
    frm, to = request.GET.get("from") or None, request.GET.get("to") or None
    qs = CheckpointCampaign.objects.select_related("project_site", "created_by", "closed_by")
    qs = qs.filter(status=status) if status else qs.history()
    if site_id:
        qs = qs.filter(project_site_id=site_id)
    try:
        if frm:
            qs = qs.filter(starts_at__date__gte=date.fromisoformat(frm))
        if to:
            qs = qs.filter(starts_at__date__lte=date.fromisoformat(to))
    except ValueError:
        pass
    qs = qs.annotate(participants_count=Count("participants", distinct=True), completed_count=Count("checkpoints", filter=Q(checkpoints__status__in=Checkpoint.COMPLETED_STATUSES), distinct=True), non_compliant_count=Count("checkpoints", filter=Q(checkpoints__status__in=Checkpoint.NON_COMPLIANT_STATUSES), distinct=True)).order_by("-starts_at", "-id")
    return render(request, "checkpoints/history.html", {"campaigns": Paginator(qs, 20).get_page(request.GET.get("page")), "status": status, "site_id": site_id, "frm": frm, "to": to, "sites": Site.objects.order_by("name"), "statuses": CheckpointCampaign.STATUSES})


@permission_required("view checkpoint module")
def daily(request):
    CheckpointDispatcher(CheckpointAudit(request)).sweep()
    try:
        day = date.fromisoformat(request.GET.get("date", "")) if request.GET.get("date") else timezone.now().date()
    except ValueError:
        day = timezone.now().date()
    site_id = int(request.GET["site"]) if request.GET.get("site", "").isdigit() else None
    sites = sorted(Site.objects.all(), key=lambda s: (0 if s.type == "project_site" else 1, s.name))
    qs = CheckpointCampaign.objects.filter(starts_at__date=day).select_related("project_site").prefetch_related("checkpoints__employee")
    if site_id:
        qs = qs.filter(project_site_id=site_id)
    campaigns = list(qs.annotate(participants_count=Count("participants", distinct=True), completed_count=Count("checkpoints", filter=Q(checkpoints__status__in=Checkpoint.COMPLETED_STATUSES), distinct=True), non_compliant_count=Count("checkpoints", filter=Q(checkpoints__status__in=Checkpoint.NON_COMPLIANT_STATUSES), distinct=True)).order_by("starts_at"))
    grid = {}
    open_follow = 0
    for c in campaigns:
        for cp in c.checkpoints.all():
            cp.campaign = c
            if cp.is_non_compliant and not cp.reviewed_at:
                open_follow += 1
            row = grid.setdefault(cp.employee_id, {"employee": cp.employee, "cells": {}})
            row["cells"][c.id] = cp
    rows = sorted(grid.values(), key=lambda r: r["employee"].full_name)
    for r in rows:
        r["cell_list"] = [r["cells"].get(c.id) for c in campaigns]
        r["done"] = sum(1 for cp in r["cells"].values() if cp.is_completed)
        r["of"] = len(r["cells"])
    totals = {"checkpoints": len(campaigns), "employees": len(rows), "completed": sum(c.completed_count for c in campaigns), "non_compliant": sum(c.non_compliant_count for c in campaigns), "open_follow_ups": open_follow}
    around = CheckpointCampaign.objects.filter(starts_at__date__range=(day - timedelta(days=14), day + timedelta(days=14)))
    if site_id:
        around = around.filter(project_site_id=site_id)
    active_days = sorted({c.starts_at.date() for c in around})
    return render(request, "checkpoints/daily.html", {"date": day, "site_id": site_id, "sites": sites, "campaigns": campaigns, "rows": rows, "totals": totals, "active_days": active_days, "prev_day": day - timedelta(days=1), "next_day": day + timedelta(days=1), "today": timezone.now().date()})


# ---- HR: create / edit ----

def _form_data(request):
    settings = CheckpointSettings()
    employees = list(Employee.objects.active().order_by("first_name", "last_name"))
    return {
        "sites": sorted(Site.objects.filter(status="active"), key=lambda s: (0 if s.type == "project_site" else 1, s.name)),
        "employee_rows": ([{"id": e.id, "name": e.full_name, "no": e.employee_no or "", "site_id": (e.assigned_site().id if e.assigned_site() else None), "site": (e.assigned_site().name if e.assigned_site() else None)} for e in employees]),
        "defaults": settings.defaults(), "instruction_library": settings.instructions(),
        "errors": request.session.pop("form_errors", None), "old": request.session.pop("form_old", None) or {},
    }


def _validated(request):
    P = request.POST
    errors = {}
    name = (P.get("name") or "").strip()[:150]
    if not name:
        errors["name"] = "Give the checkpoint a name."
    site = Site.objects.filter(pk=P.get("project_site_id") or 0).first()
    if not site:
        errors["project_site_id"] = "Pick the project site."
    instruction = (P.get("instruction") or "").strip()[:200]
    if not instruction:
        errors["instruction"] = "Enter the checkpoint instruction employees must follow."
    ids = list(dict.fromkeys(int(i) for i in P.getlist("employees") if str(i).isdigit()))
    if not ids or Employee.objects.filter(pk__in=ids).count() != len(ids):
        errors["employees"] = "Select at least one employee to include."
    try:
        window = int(P.get("response_window_minutes", "10"))
        if not 3 <= window <= 120:
            raise ValueError
    except ValueError:
        errors["response_window_minutes"] = "Between 3 and 120 minutes."
        window = 10
    attrs = {"name": name, "project_site": site, "instruction": instruction, "response_window_minutes": window}
    old = {"name": name, "project_site_id": P.get("project_site_id"), "instruction": instruction, "response_window_minutes": window, "employees": ids}
    return attrs, ids, errors, old


@permission_required("create checkpoint campaign")
def create(request):
    data = _form_data(request)
    data["selected"] = data["old"].get("employees") or []
    return render(request, "checkpoints/create.html", {**data, "campaign": None, "editing": False, "back_url": reverse("checkpoints.index"), "back_label": "Back to Check Point"})


@permission_required("create checkpoint campaign")
@require_POST
def store(request):
    attrs, ids, errors, old = _validated(request)
    if errors:
        request.session["form_errors"], request.session["form_old"] = errors, old
        return redirect("checkpoints.create")
    campaign = CampaignManager(CheckpointAudit(request)).create(attrs, ids, request.user)
    messages.success(request, "Checkpoint saved as a draft. Review the employees below, then activate it now or set a start time.")
    return redirect("checkpoints.show", pk=campaign.id)


@permission_required("create checkpoint campaign")
def edit(request, pk):
    campaign = get_object_or_404(CheckpointCampaign, pk=pk)
    if not campaign.is_draft:
        messages.error(request, "Only a draft checkpoint can be edited.")
        return redirect("checkpoints.show", pk=pk)
    data = _form_data(request)
    if not data["old"]:
        data["old"] = {"name": campaign.name, "project_site_id": str(campaign.project_site_id), "instruction": campaign.instruction, "response_window_minutes": campaign.response_window_minutes, "employees": list(campaign.participants.values_list("employee_id", flat=True))}
    data["selected"] = data["old"].get("employees") or []
    return render(request, "checkpoints/create.html", {**data, "campaign": campaign, "editing": True, "back_url": reverse("checkpoints.show", args=[pk]), "back_label": "Back to checkpoint"})


@permission_required("create checkpoint campaign")
@require_POST
def update(request, pk):
    campaign = get_object_or_404(CheckpointCampaign, pk=pk)
    attrs, ids, errors, old = _validated(request)
    if errors:
        request.session["form_errors"], request.session["form_old"] = errors, old
        return redirect("checkpoints.edit", pk=pk)
    try:
        CampaignManager(CheckpointAudit(request)).update(campaign, attrs, ids, request.user)
    except StateError as e:
        return _err(request, e, reverse("checkpoints.show", args=[pk]))
    messages.success(request, "Checkpoint updated.")
    return redirect("checkpoints.show", pk=pk)


# ---- HR: monitoring ----

@permission_required("view checkpoint module")
def show(request, pk):
    CheckpointDispatcher(CheckpointAudit(request)).sweep()
    campaign = get_object_or_404(CheckpointCampaign.objects.select_related("project_site", "activated_by"), pk=pk)
    employees = [p.employee for p in campaign.participants.select_related("employee").order_by("employee__last_name", "employee__first_name")]
    responses = {cp.employee_id: cp for cp in campaign.checkpoints.select_related("employee")}
    for cp in responses.values():
        cp.campaign = campaign
    rows = []
    for e in employees:
        cp = responses.get(e.id)
        rows.append({"employee": e, "checkpoint": cp, "result": cp.result() if cp else ("pending" if campaign.is_draft else "not_completed")})
    order = {"waiting": 0, "not_completed": 1, "completed": 2, "pending": 3}
    rows.sort(key=lambda r: order[r["result"]])
    counts = {"total": len(rows), "completed": sum(r["result"] == "completed" for r in rows), "waiting": sum(r["result"] == "waiting" for r in rows), "not_completed": sum(r["result"] == "not_completed" for r in rows)}
    counts["pct"] = int(round(counts["completed"] / counts["total"] * 100)) if counts["total"] else 0
    now = timezone.now()
    return render(request, "checkpoints/show.html", {
        "campaign": campaign, "rows": rows, "counts": counts, "seconds_left": campaign.seconds_remaining(now), "expires_iso": _iso(campaign.expires_at), "server_now_iso": _iso(now),
        "hr_reasons": Checkpoint.HR_REASONS, "today": timezone.now().date().isoformat(), "status_url": reverse("checkpoints.status", args=[pk]),
        "back_url": reverse("checkpoints.index"), "back_label": "Back to Check Point",
    })


@permission_required("view checkpoint module")
def status(request, pk):
    CheckpointDispatcher(CheckpointAudit(request)).sweep()
    campaign = get_object_or_404(CheckpointCampaign, pk=pk)
    return JsonResponse({
        "status": campaign.status, "server_now": _iso(timezone.now()), "expires_at": _iso(campaign.expires_at),
        "completed": campaign.checkpoints.completed().count(), "total": campaign.participants.count(),
        "changed_at": _iso(campaign.checkpoints.order_by("-updated_at").values_list("updated_at", flat=True).first()),
    })


def _lifecycle(request, pk, fn, success):
    campaign = get_object_or_404(CheckpointCampaign, pk=pk)
    manager = CampaignManager(CheckpointAudit(request))
    try:
        c = fn(manager, campaign)
    except (StateError, ValidationError) as e:
        return _err(request, e, reverse("checkpoints.show", args=[pk]))
    messages.success(request, success(c) if callable(success) else success)
    return redirect("checkpoints.show", pk=pk)


@permission_required("activate checkpoint campaign")
@require_POST
def activate(request, pk):
    return _lifecycle(request, pk, lambda m, c: m.activate(c, request.user), lambda c: f"Checkpoint activated. Start {c.starts_at:%-I:%M %p} · deadline {c.expires_at:%-I:%M %p} — the same for all {c.participants.count()} employee(s). Notifications sent.")


@permission_required("activate checkpoint campaign")
@require_POST
def schedule(request, pk):
    try:
        at = datetime.fromisoformat(request.POST.get("scheduled_start_at", ""))
    except ValueError:
        messages.error(request, "Pick a start date and time.")
        return redirect("checkpoints.show", pk=pk)
    return _lifecycle(request, pk, lambda m, c: m.schedule(c, at, request.user), f"Checkpoint scheduled to start automatically at {at:%b %-d, %-I:%M %p}.")


@permission_required("activate checkpoint campaign")
@require_POST
def schedule_random(request, pk):
    try:
        day = date.fromisoformat(request.POST.get("date", ""))
        frm = datetime.strptime(request.POST.get("window_start", ""), "%H:%M").time()
        to = datetime.strptime(request.POST.get("window_end", ""), "%H:%M").time()
        if to <= frm:
            raise ValueError
    except ValueError:
        messages.error(request, "Pick a date and a window where the end is after the start.")
        return redirect("checkpoints.show", pk=pk)
    return _lifecycle(request, pk, lambda m, c: m.schedule_random(c, day, frm, to, request.user), lambda c: f"The system picked {c.scheduled_start_at:%b %-d, %-I:%M %p} — give the team leader a heads-up before then.")


@permission_required("pause checkpoint campaign")
@require_POST
def pause(request, pk):
    return _lifecycle(request, pk, lambda m, c: m.pause(c, request.user, request.POST.get("reason")), "Checkpoint paused — the countdown is frozen and submissions are on hold.")


@permission_required("pause checkpoint campaign")
@require_POST
def resume(request, pk):
    return _lifecycle(request, pk, lambda m, c: m.resume(c, request.user), lambda c: f"Checkpoint resumed. New deadline for everyone: {c.expires_at:%-I:%M %p}.")


@permission_required("end checkpoint campaign")
@require_POST
def end(request, pk):
    return _lifecycle(request, pk, lambda m, c: m.end_now(c, request.user, CheckpointDispatcher(CheckpointAudit(request))), "Checkpoint window closed. Employees without a valid submission are now marked missed.")


@permission_required("end checkpoint campaign")
@require_POST
def cancel(request, pk):
    campaign = get_object_or_404(CheckpointCampaign, pk=pk)
    try:
        CampaignManager(CheckpointAudit(request)).cancel(campaign, request.user, request.POST.get("reason"))
    except StateError as e:
        return _err(request, e, reverse("checkpoints.show", args=[pk]))
    messages.success(request, "Checkpoint cancelled.")
    return redirect("checkpoints.index")


@permission_required("end checkpoint campaign")
@require_POST
def complete(request, pk):
    return _lifecycle(request, pk, lambda m, c: m.complete(c, request.user), "Checkpoint marked as completed.")


@permission_required("export checkpoint reports")
def export(request, pk):
    campaign = get_object_or_404(CheckpointCampaign, pk=pk)
    CheckpointAudit(request).campaign(campaign, "exported", request.user)
    resp = HttpResponse(content_type="text/csv")
    resp["Content-Disposition"] = f'attachment; filename="checkpoint-{campaign.id}-{timezone.now():%Y%m%d_%H%M%S}.csv"'
    w = csv.writer(resp)
    w.writerow(["Reference", "Checkpoint", "Project site", "Start", "Deadline", "Employee No.", "Employee", "Status", "Verification", "Notified", "Seen", "Attempts", "Last attempt", "Last attempt result", "Submitted (server)", "Response (s)", "Latitude", "Longitude", "GPS accuracy (m)", "Distance (m)", "Within geofence", "Matched site", "Network", "Issue reported", "Employee explanation", "HR reason", "HR note", "Escalated", "Reviewed by", "Reviewed at"])
    for cp in campaign.checkpoints.select_related("employee", "project_site", "matched_site", "reviewed_by").order_by("status"):
        cp.campaign = campaign
        w.writerow([cp.reference(), campaign.name, cp.project_site.name, campaign.starts_at, campaign.expires_at, cp.employee.employee_no, cp.employee.full_name, cp.status_label, cp.verification_label,
                    cp.notified_at, cp.seen_at, cp.submission_attempts, cp.last_attempt_at, cp.last_attempt_result, cp.submitted_at, cp.response_seconds(), cp.latitude, cp.longitude,
                    cp.gps_accuracy_meters, cp.distance_from_site_meters, "" if cp.within_geofence is None else ("yes" if cp.within_geofence else "no"), cp.matched_site.name if cp.matched_site else "",
                    cp.network_status, cp.issue_reported, cp.employee_explanation, cp.hr_reason_label, cp.hr_note, cp.escalated_at, cp.reviewed_by.name if cp.reviewed_by else "", cp.reviewed_at])
    return resp


# ---- settings ----

@permission_required("manage checkpoint settings")
def settings_edit(request):
    s = CheckpointSettings()
    return render(request, "checkpoints/settings.html", {"defaults": s.defaults(), "instructions": s.instructions(), "back_url": reverse("checkpoints.index"), "back_label": "Back to Check Point"})


@permission_required("manage checkpoint settings")
@require_POST
def settings_update(request):
    try:
        window = int(request.POST.get("response_window_minutes", "10"))
        if not 3 <= window <= 120:
            raise ValueError
    except ValueError:
        messages.error(request, "The response window must be between 3 and 120 minutes.")
        return redirect("checkpoints.settings")
    lines = [l for l in (request.POST.get("instructions") or "").splitlines()]
    if not any(l.strip() for l in lines):
        messages.error(request, "Add at least one instruction.")
        return redirect("checkpoints.settings")
    CheckpointSettings().save({"response_window_minutes": window}, lines)
    messages.success(request, "Check Point settings saved.")
    return redirect("checkpoints.settings")


# ---- results & follow-up ----

@permission_required("view checkpoint results")
def results_index(request):
    CheckpointDispatcher(CheckpointAudit(request)).sweep()
    G = request.GET
    f = {"campaign": int(G["campaign"]) if G.get("campaign", "").isdigit() else None, "employee": int(G["employee"]) if G.get("employee", "").isdigit() else None,
         "status": G.get("status") if G.get("status") in Checkpoint.STATUSES else None, "follow": G.get("follow") if G.get("follow") in ("open", "reviewed", "escalated") else None,
         "from": G.get("from") or None, "to": G.get("to") or None}
    qs = Checkpoint.objects.select_related("employee", "project_site", "campaign", "reviewed_by")
    if f["campaign"]:
        qs = qs.filter(campaign_id=f["campaign"])
    if f["employee"]:
        qs = qs.filter(employee_id=f["employee"])
    if f["status"]:
        qs = qs.filter(status=f["status"])
    if f["follow"] == "open":
        qs = qs.non_compliant().filter(reviewed_at__isnull=True)
    elif f["follow"] == "reviewed":
        qs = qs.filter(reviewed_at__isnull=False)
    elif f["follow"] == "escalated":
        qs = qs.filter(escalated_at__isnull=False)
    try:
        if f["from"]:
            qs = qs.filter(campaign__starts_at__date__gte=date.fromisoformat(f["from"]))
        if f["to"]:
            qs = qs.filter(campaign__starts_at__date__lte=date.fromisoformat(f["to"]))
    except ValueError:
        pass
    page = Paginator(qs.order_by("-updated_at"), 25).get_page(G.get("page"))
    return render(request, "checkpoints/results.html", {"checkpoints": page, "campaigns": CheckpointCampaign.objects.order_by("-id"), "employees": Employee.objects.order_by("first_name", "last_name"), "filters": f, "statuses": Checkpoint.STATUSES})


@permission_required("view checkpoint results")
def results_show(request, pk):
    cp = get_object_or_404(Checkpoint.objects.select_related("campaign__project_site", "employee__user", "project_site", "matched_site", "reviewed_by"), pk=pk)
    reviewer = CheckpointReviewer(CheckpointAudit(request))
    return render(request, "checkpoints/result.html", {
        "checkpoint": cp, "campaign": cp.campaign, "context": reviewer.movement_context(cp), "audit": cp.audit_logs.select_related("user"), "reviews": cp.reviews.select_related("reviewer"),
        "has_photo": CheckpointPhoto().exists(cp), "hr_reasons": Checkpoint.HR_REASONS, "issues": Checkpoint.ISSUES, "assigned_site": cp.employee.assigned_site(),
        "back_url": reverse("checkpoints.show", args=[cp.campaign_id]), "back_label": "Back to checkpoint",
    })


@permission_required("review checkpoint exceptions")
@require_POST
def follow_up(request, pk):
    cp = get_object_or_404(Checkpoint.objects.select_related("campaign", "employee__user"), pk=pk)
    action = request.POST.get("action")
    explanation, reason, note = (request.POST.get("explanation") or "").strip()[:1000], request.POST.get("reason") or None, (request.POST.get("note") or "").strip()[:1000] or None
    if reason and reason not in Checkpoint.HR_REASONS:
        reason = None
    reviewer = CheckpointReviewer(CheckpointAudit(request))
    try:
        if action == "explanation":
            if not explanation:
                raise StateError("Enter the employee's explanation.")
            reviewer.record_explanation(cp, request.user, explanation, reason, note)
            msg = "Explanation recorded."
        elif action == "note":
            if not note:
                raise StateError("Enter a note.")
            reviewer.add_note(cp, request.user, note)
            msg = "HR note added."
        elif action == "mark_review":
            reviewer.mark_for_review(cp, request.user, note)
            msg = "Marked for HR review."
        elif action in ("approve", "reject"):
            if not reason:
                raise StateError("Select a reason before approving or rejecting.")
            (reviewer.approve if action == "approve" else reviewer.reject)(cp, request.user, reason, note)
            msg = "Exception approved — counts as completed after review." if action == "approve" else "Exception rejected."
        elif action == "escalate":
            reviewer.escalate(cp, request.user, note)
            msg = "Case escalated to management."
        else:
            raise StateError("Unknown action.")
    except StateError as e:
        return _err(request, e, reverse("checkpoints.results.show", args=[pk]))
    messages.success(request, f"{cp.reference()}: {msg}")
    return _back(request, reverse("checkpoints.results.show", args=[pk]))


def photo(request, pk):
    if not request.user.is_authenticated:
        raise PermissionDenied
    cp = get_object_or_404(Checkpoint, pk=pk)
    me = getattr(request.user, "employee", None)
    if not ((me and cp.employee_id == me.id) or request.user.can("view checkpoint results")):
        raise PermissionDenied
    return CheckpointPhoto().response(cp)


# ---- employee side ----

@permission_required("clock attendance")
@employee_required
def my_index(request):
    employee = request.user.employee
    CheckpointDispatcher(CheckpointAudit(request)).sweep()
    active = list(employee.checkpoints.filter(campaign__status__in=["active", "paused"]).select_related("campaign", "project_site"))
    recent = Paginator(employee.checkpoints.exclude(campaign__status__in=["active", "paused"]).select_related("project_site", "campaign").order_by("-updated_at"), 20).get_page(request.GET.get("page"))
    return render(request, "checkpoints/my/index.html", {"active": active, "recent": recent, "server_now_iso": _iso(timezone.now())})


def my_active(request):
    if not request.user.is_authenticated:
        return JsonResponse({"active": None}, status=401)
    employee = getattr(request.user, "employee", None)
    if not employee:
        return JsonResponse({"active": None})
    CheckpointDispatcher(CheckpointAudit(request)).sweep()
    cp = employee.checkpoints.filter(status__in=Checkpoint.WAITING_STATUSES, campaign__status=CheckpointCampaign.ACTIVE).select_related("campaign", "project_site").order_by("-id").first()
    return JsonResponse({"server_now": _iso(timezone.now()), "active": {
        "id": cp.id, "reference": cp.reference(), "site": cp.project_site.name, "instruction": cp.campaign.instruction,
        "starts_at": _iso(cp.campaign.starts_at), "expires_at": _iso(cp.campaign.expires_at), "status": cp.status, "url": reverse("my-checkpoints.show", args=[cp.id]),
        "message": f"Live presence checkpoint active. Please complete your verification before {cp.campaign.expires_at:%-I:%M %p}.",
    } if cp else None})


@permission_required("clock attendance")
@employee_required
def my_show(request, pk):
    employee = request.user.employee
    cp = get_object_or_404(Checkpoint.objects.select_related("project_site", "campaign", "matched_site", "reviewed_by"), pk=pk)
    if cp.employee_id != employee.id:
        raise PermissionDenied
    CheckpointDispatcher(CheckpointAudit(request)).sweep()
    cp.refresh_from_db()
    campaign = cp.campaign
    if not cp.seen_at:
        cp.seen_at = timezone.now()
        cp.save(update_fields=["seen_at"])
    if campaign.is_live and not cp.is_completed:
        s = cp.project_site
        return render(request, "checkpoints/my/verify.html", {
            "checkpoint": cp, "campaign": campaign, "site_js": ({"id": s.id, "name": s.name, "address": s.address, "latitude": float(s.latitude), "longitude": float(s.longitude), "geofence_radius_m": s.geofence_radius_m}),
            "employee_name": employee.full_name, "min_accuracy": GeofenceService().min_accuracy_meters(), "server_now": _iso(timezone.now()), "expires_at": _iso(campaign.expires_at),
            "paused": campaign.status == CheckpointCampaign.PAUSED, "immersive": True, "hide_checkpoint_alert": True, "attempt_error": request.session.pop("attempt_error", None), "issues": Checkpoint.ISSUES,
        })
    return render(request, "checkpoints/my/result.html", {"checkpoint": cp, "campaign": campaign, "has_photo": CheckpointPhoto().exists(cp), "can_explain": cp.is_non_compliant and not cp.reviewed_at, "issues": Checkpoint.ISSUES, "back_url": reverse("my-checkpoints.index"), "back_label": "Back to My Checkpoints"})


@permission_required("clock attendance")
@employee_required
@require_POST
def my_submit(request, pk):
    employee = request.user.employee
    cp = get_object_or_404(Checkpoint, pk=pk)
    if cp.employee_id != employee.id:
        raise PermissionDenied
    def _f(name):
        v = request.POST.get(name, "")
        try:
            return float(v) if v not in ("", None) else None
        except ValueError:
            return None
    lat, lng, acc = _f("latitude"), _f("longitude"), _f("gps_accuracy")
    if (lat is None) != (lng is None):
        lat = lng = None
    net = request.POST.get("network_status") if request.POST.get("network_status") in ("online", "offline_synced") else "online"
    try:
        result = CheckpointVerifier(CheckpointAudit(request)).submit(cp, employee, {"latitude": lat, "longitude": lng, "accuracy": acc, "photo": request.POST.get("photo", ""), "client_timestamp": request.POST.get("client_timestamp"), "network_status": net})
    except ValidationError as e:
        request.session["attempt_error"] = "; ".join(m for v in e.message_dict.values() for m in v)
        return redirect("my-checkpoints.show", pk=pk)
    if result.is_completed:
        messages.success(request, f"Checkpoint completed — thank you. {result.validation_message}")
    else:
        request.session["attempt_error"] = result.validation_message
    return redirect("my-checkpoints.show", pk=pk)


@permission_required("clock attendance")
@employee_required
@require_POST
def my_issue(request, pk):
    employee = request.user.employee
    cp = get_object_or_404(Checkpoint, pk=pk)
    if cp.employee_id != employee.id:
        raise PermissionDenied
    issue = request.POST.get("issue")
    if issue is None and request.content_type == "application/json":
        try:
            issue = json.loads(request.body.decode() or "{}").get("issue")
        except ValueError:
            issue = None
    if cp.is_completed or issue not in Checkpoint.ISSUES:
        if request.headers.get("accept", "").startswith("application/json"):
            return JsonResponse({"ok": False}, status=422)
        messages.error(request, "This checkpoint is already completed.")
        return redirect("my-checkpoints.show", pk=pk)
    cp.issue_reported = issue
    if issue == "camera_denied" and cp.is_waiting:
        cp.status, cp.failure_reason = Checkpoint.CAMERA_PERMISSION_DENIED, "photo_missing"
    elif issue == "gps_unavailable" and cp.is_waiting:
        cp.status, cp.failure_reason = Checkpoint.GPS_UNAVAILABLE, "gps_unavailable"
    cp.save()
    CheckpointAudit(request).checkpoint(cp, "issue_reported", request.user, {"issue": issue})
    if request.headers.get("accept", "").startswith("application/json") or request.headers.get("x-requested-with") == "XMLHttpRequest":
        return JsonResponse({"ok": True})
    messages.success(request, f"Problem reported to HR: {Checkpoint.ISSUES[issue]}.")
    return redirect("my-checkpoints.show", pk=pk)


@permission_required("clock attendance")
@employee_required
@require_POST
def my_explain(request, pk):
    employee = request.user.employee
    cp = get_object_or_404(Checkpoint, pk=pk)
    if cp.employee_id != employee.id:
        raise PermissionDenied
    if not (cp.is_non_compliant and not cp.reviewed_at):
        messages.error(request, "This checkpoint is not open for an explanation.")
        return redirect("my-checkpoints.show", pk=pk)
    text = (request.POST.get("employee_explanation") or "").strip()[:1000]
    if not text:
        messages.error(request, "Enter your explanation.")
        return redirect("my-checkpoints.show", pk=pk)
    cp.employee_explanation = text
    issue = request.POST.get("issue")
    if issue in Checkpoint.ISSUES:
        cp.issue_reported = issue
    cp.save()
    CheckpointAudit(request).checkpoint(cp, "explanation_added", request.user, {"issue": issue} if issue in Checkpoint.ISSUES else None)
    messages.success(request, "Your explanation was sent to HR.")
    return redirect("my-checkpoints.show", pk=pk)
