"""
Check Point services: campaign lifecycle, the dispatcher clock, submission
verification, HR review, private photo storage, settings and the audit trail.
"""

import io
import os
import secrets
import time
from datetime import datetime, timedelta

from django.conf import settings
from django.core.exceptions import ValidationError
from django.db import transaction
from django.http import FileResponse, Http404
from django.utils import timezone
from PIL import Image, ImageDraw, ImageFont

from attendance.geofence import GeofenceService, distance_meters
from attendance.models import AttendanceLog
from core.models import Setting, notify, users_with_permission
from core.photos import decode_data_url
from core.support import private_media_path
from employees.models import Site
from leaveot.models import LeaveRequest

from .models import Checkpoint, CheckpointAuditLog, CheckpointCampaign, CheckpointCampaignParticipant, CheckpointReview


class StateError(Exception):
    """A transition that the campaign's current status does not allow (HTTP 422)."""


# ---- audit ----

class CheckpointAudit:
    def __init__(self, request=None):
        self.request = request

    def campaign(self, campaign, action, user=None, details=None):
        return self._write(action, user, details, campaign_id=campaign.id)

    def checkpoint(self, cp, action, user=None, details=None):
        return self._write(action, user, details, campaign_id=cp.campaign_id, checkpoint_id=cp.id)

    def _write(self, action, user, details, campaign_id=None, checkpoint_id=None):
        return CheckpointAuditLog.objects.create(
            campaign_id=campaign_id, checkpoint_id=checkpoint_id,
            user=user if (user is not None and getattr(user, "is_authenticated", True)) else None,
            action=action, details=details or None,
            ip_address=self.request.META.get("REMOTE_ADDR") if self.request else None,
        )


# ---- settings ----

class CheckpointSettings:
    KEY_DEFAULTS = "checkpoints.defaults"
    KEY_INSTRUCTIONS = "checkpoints.instructions"

    def defaults(self) -> dict:
        saved = Setting.get(self.KEY_DEFAULTS, {}) or {}
        merged = dict(settings.CHECKPOINTS["defaults"])
        merged.update({k: v for k, v in saved.items() if v not in (None, "")})
        return {k: int(v) for k, v in merged.items()}

    def instructions(self) -> list:
        saved = Setting.get(self.KEY_INSTRUCTIONS)
        items = saved if isinstance(saved, list) and saved else settings.CHECKPOINTS["instructions"]
        out = []
        for s in items:
            s = str(s).strip()
            if s and s not in out:
                out.append(s)
        return out

    def save(self, defaults: dict, instructions: list):
        Setting.set(self.KEY_DEFAULTS, {k: int(v) for k, v in defaults.items()})
        clean = []
        for s in instructions:
            s = str(s).strip()
            if s and s not in clean:
                clean.append(s)
        Setting.set(self.KEY_INSTRUCTIONS, clean)


# ---- photos (private disk) ----

class CheckpointPhoto:
    def store(self, cp: Checkpoint, data_url: str, gps_status: str):
        decoded = decode_data_url(data_url)
        if decoded is None:
            return None
        binary, ext = decoded
        stamped = self._stamp(binary, cp, gps_status)
        if stamped is not None:
            binary, ext = stamped, "jpg"
        rel = f"checkpoints/{cp.campaign_id}/{cp.employee_id}/{timezone.now():%Y%m%d_%H%M%S}_{cp.id}_{secrets.token_hex(3)}.{ext}"
        path = private_media_path(rel)
        os.makedirs(path.parent, exist_ok=True)
        path.write_bytes(binary)
        return rel

    def exists(self, cp: Checkpoint) -> bool:
        return bool(cp.photo_path) and private_media_path(cp.photo_path).is_file()

    def response(self, cp: Checkpoint):
        if not self.exists(cp):
            raise Http404
        resp = FileResponse(open(private_media_path(cp.photo_path), "rb"), content_type="image/jpeg", filename=cp.reference() + ".jpg")
        resp["Cache-Control"] = "private, no-store"
        return resp

    def _stamp(self, binary, cp, gps_status):
        """Burn a caption strip into the bottom of the photo so the image carries who/where/when."""
        try:
            img = Image.open(io.BytesIO(binary)).convert("RGB")
            w, h = img.size
            size = max(14, int(round(w / 42)))
            font = ImageFont.truetype(str(settings.BASE_DIR / "static" / "fonts" / "Lato-Regular.ttf"), size)
            line_h = int(round(size * 1.45))
            lines = [
                f"{cp.employee.full_name} · {cp.project_site.name}",
                f"{timezone.now():%a, %b %-d, %Y %-I:%M:%S %p} · {cp.reference()}",
                f"GPS: {gps_status} · {cp.campaign.instruction}",
            ]
            strip_h = line_h * len(lines) + line_h
            overlay = Image.new("RGBA", (w, strip_h), (0, 0, 0, 153))
            img.paste(overlay, (0, h - strip_h), overlay)
            draw = ImageDraw.Draw(img)
            y = h - strip_h + int(line_h * 0.6)
            for line in lines:
                draw.text((int(size * 0.8), y), line, font=font, fill=(255, 255, 255))
                y += line_h
            out = io.BytesIO()
            img.save(out, "JPEG", quality=82)
            return out.getvalue()
        except Exception:
            return None


# ---- campaign lifecycle ----

class CampaignManager:
    def __init__(self, audit: CheckpointAudit | None = None):
        self.audit = audit or CheckpointAudit()

    def create(self, attributes: dict, employee_ids, by) -> CheckpointCampaign:
        with transaction.atomic():
            campaign = CheckpointCampaign.objects.create(status=CheckpointCampaign.DRAFT, created_by=by, **attributes)
            self._sync(campaign, employee_ids)
            self.audit.campaign(campaign, "created", by, {"participants": len(set(employee_ids))})
            return campaign

    def update(self, campaign, attributes: dict, employee_ids, by) -> CheckpointCampaign:
        if not campaign.is_draft:
            raise StateError("Only a draft checkpoint can be edited.")
        with transaction.atomic():
            for k, v in attributes.items():
                setattr(campaign, k, v)
            campaign.save()
            self._sync(campaign, employee_ids)
            self.audit.campaign(campaign, "updated", by, {"participants": len(set(employee_ids))})
            return campaign

    def _sync(self, campaign, employee_ids):
        ids = {int(i) for i in employee_ids}
        campaign.participants.exclude(employee_id__in=ids).delete()
        existing = set(campaign.participants.values_list("employee_id", flat=True))
        CheckpointCampaignParticipant.objects.bulk_create([CheckpointCampaignParticipant(campaign=campaign, employee_id=i) for i in ids - existing])

    def activate(self, campaign, by, now=None) -> CheckpointCampaign:
        """Activate now: one official start and one deadline for every participant; `by` is None when the dispatcher starts it."""
        now = now or timezone.now()
        if not campaign.is_draft:
            raise StateError("This checkpoint cannot be activated from its current status.")
        if campaign.participants.count() == 0:
            raise ValidationError({"employees": "Add at least one employee before activating."})
        with transaction.atomic():
            starts = now.replace(microsecond=0)
            expires = starts + timedelta(minutes=campaign.response_window_minutes)
            campaign.status = CheckpointCampaign.ACTIVE
            campaign.starts_at, campaign.expires_at, campaign.scheduled_start_at = starts, expires, None
            campaign.activated_by, campaign.activated_at = by, now
            campaign.save()
            self.audit.campaign(campaign, "activated" if by else "auto_started", by, {"starts_at": str(starts), "expires_at": str(expires)})
            for part in campaign.participants.select_related("employee__user"):
                cp, _ = Checkpoint.objects.get_or_create(campaign=campaign, employee=part.employee, defaults={"project_site": campaign.project_site, "status": Checkpoint.PENDING})
                if part.employee.user:
                    notify(part.employee.user, kind="checkpoint", title="Checkpoint started", message=f"{campaign.instruction} — respond within {campaign.response_window_minutes} minutes.", url=f"/my-checkpoints/{cp.id}")
                    cp.status, cp.notified_at = Checkpoint.NOTIFIED, now
                    cp.save(update_fields=["status", "notified_at"])
        campaign.refresh_from_db()
        return campaign

    def schedule(self, campaign, at: datetime, by) -> CheckpointCampaign:
        if not campaign.is_draft:
            raise StateError("Only a draft checkpoint can be scheduled.")
        if at <= timezone.now():
            raise ValidationError({"scheduled_start_at": "The start time must be in the future — or activate now."})
        if campaign.participants.count() == 0:
            raise ValidationError({"employees": "Add at least one employee before scheduling."})
        campaign.scheduled_start_at, campaign.schedule_mode = at, "manual"
        campaign.random_window_start = campaign.random_window_end = None
        campaign.save()
        self.audit.campaign(campaign, "scheduled", by, {"scheduled_start_at": str(at), "mode": "manual"})
        return campaign

    def schedule_random(self, campaign, day, frm, to, by) -> CheckpointCampaign:
        """Draw the start at random inside the admin's window, leaving room for the response window."""
        if not campaign.is_draft:
            raise StateError("Only a draft checkpoint can be scheduled.")
        if campaign.participants.count() == 0:
            raise ValidationError({"employees": "Add at least one employee before scheduling."})
        window_start = datetime.combine(day, frm)
        window_end = datetime.combine(day, to)
        earliest = max(window_start, timezone.now() + timedelta(minutes=1)).replace(second=0, microsecond=0)
        latest = (window_end - timedelta(minutes=campaign.response_window_minutes)).replace(second=0, microsecond=0)
        if latest < earliest:
            raise ValidationError({"random_window": f"That window is too short (or already over) for a {campaign.response_window_minutes}-minute checkpoint. Widen it or pick a later time."})
        minutes = int((latest - earliest).total_seconds() // 60)
        at = earliest + timedelta(minutes=secrets.randbelow(minutes + 1))
        campaign.scheduled_start_at, campaign.schedule_mode = at, "random"
        campaign.random_window_start, campaign.random_window_end = frm, to
        campaign.save()
        self.audit.campaign(campaign, "scheduled", by, {"scheduled_start_at": str(at), "mode": "random", "window": f"{frm:%H:%M}–{to:%H:%M}"})
        return campaign

    def pause(self, campaign, by, reason=None):
        if not campaign.is_active:
            raise StateError("Only an active checkpoint can be paused.")
        campaign.status, campaign.paused_by, campaign.paused_at = CheckpointCampaign.PAUSED, by, timezone.now()
        campaign.save()
        self.audit.campaign(campaign, "paused", by, {k: v for k, v in {"reason": reason, "deadline_at_pause": str(campaign.expires_at)}.items() if v})
        return campaign

    def resume(self, campaign, by, now=None):
        now = now or timezone.now()
        if campaign.status != CheckpointCampaign.PAUSED:
            raise StateError("Only a paused checkpoint can be resumed.")
        paused_for = int((now - campaign.paused_at).total_seconds())
        campaign.expires_at = campaign.expires_at + timedelta(seconds=paused_for)
        campaign.status, campaign.paused_by, campaign.paused_at = CheckpointCampaign.ACTIVE, None, None
        campaign.save()
        self.audit.campaign(campaign, "resumed", by, {"paused_seconds": paused_for, "new_deadline": str(campaign.expires_at)})
        return campaign

    def cancel(self, campaign, by, reason=None):
        if campaign.is_finished:
            raise StateError("This checkpoint is already finished.")
        campaign.status, campaign.closed_by, campaign.closed_at = CheckpointCampaign.CANCELLED, by, timezone.now()
        campaign.save()
        self.audit.campaign(campaign, "cancelled", by, {"reason": reason} if reason else None)
        return campaign

    def complete(self, campaign, by):
        if campaign.status != CheckpointCampaign.EXPIRED:
            raise StateError("A checkpoint can be completed once it has expired.")
        campaign.status, campaign.closed_by, campaign.closed_at = CheckpointCampaign.COMPLETED, by, timezone.now()
        campaign.save()
        self.audit.campaign(campaign, "completed", by, {"open_follow_ups": campaign.checkpoints.non_compliant().filter(reviewed_at__isnull=True).count()})
        return campaign

    def end_now(self, campaign, by, dispatcher=None):
        if not campaign.is_live:
            raise StateError("Only a running checkpoint can be ended.")
        campaign.status, campaign.expires_at, campaign.paused_at, campaign.paused_by = CheckpointCampaign.ACTIVE, timezone.now(), None, None
        campaign.save()
        self.audit.campaign(campaign, "ended_early", by)
        (dispatcher or CheckpointDispatcher(self.audit)).expire_campaign(campaign, timezone.now())
        campaign.refresh_from_db()
        return campaign


# ---- dispatcher (the module's clock) ----

class CheckpointDispatcher:
    _last_sweep = 0.0

    def __init__(self, audit: CheckpointAudit | None = None):
        self.audit = audit or CheckpointAudit()

    def tick(self, now=None) -> dict:
        now = now or timezone.now()
        out = {"started": self.start_scheduled(now)}
        out.update(self.expire_lapsed(now))
        return out

    def sweep(self):
        ttl = max(5, int(settings.CHECKPOINTS["sweep_throttle_seconds"]))
        if time.monotonic() - CheckpointDispatcher._last_sweep > ttl:
            CheckpointDispatcher._last_sweep = time.monotonic()
            self.tick()

    def start_scheduled(self, now) -> int:
        n = 0
        for c in CheckpointCampaign.objects.filter(status=CheckpointCampaign.DRAFT, scheduled_start_at__isnull=False, scheduled_start_at__lte=now):
            CampaignManager(self.audit).activate(c, None, now)
            n += 1
        return n

    def expire_lapsed(self, now) -> dict:
        expired = missed = 0
        for c in CheckpointCampaign.objects.filter(status=CheckpointCampaign.ACTIVE, expires_at__lt=now):
            missed += self.expire_campaign(c, now)
            expired += 1
        return {"expired": expired, "missed": missed}

    def expire_campaign(self, campaign, now) -> int:
        """Close the window: everyone without a valid response becomes MISSED."""
        missed = 0
        for cp in campaign.checkpoints.filter(status__in=Checkpoint.WAITING_STATUSES):
            if cp.status in (Checkpoint.PENDING, Checkpoint.NOTIFIED):
                cp.status, cp.failure_reason = Checkpoint.MISSED, "no_response"
                cp.validation_message = f"No submission was received before the checkpoint deadline at {campaign.expires_at:%-I:%M %p}."
                cp.server_timestamp = now
                cp.save()
                missed += 1
            self.audit.checkpoint(cp, "deadline_passed", None, {"status": cp.status})
        campaign.status = CheckpointCampaign.EXPIRED
        campaign.save(update_fields=["status"])
        non_compliant = campaign.checkpoints.non_compliant().count()
        self.audit.campaign(campaign, "expired", None, {"missed": missed, "non_compliant": non_compliant})
        if non_compliant > 0:
            notify(users_with_permission("review checkpoint exceptions"), kind="checkpoint", title="Checkpoint needs follow-up",
                   message=f"{campaign.name}: {non_compliant} employee(s) did not complete it.", url=f"/checkpoints/campaigns/{campaign.id}")
        return missed


# ---- verifier ----

class CheckpointVerifier:
    def __init__(self, audit: CheckpointAudit | None = None):
        self.geofence = GeofenceService()
        self.photos = CheckpointPhoto()
        self.audit = audit or CheckpointAudit()

    def submit(self, checkpoint, employee, data: dict, now=None) -> Checkpoint:
        now = now or timezone.now()
        if not data.get("photo") or decode_data_url(str(data["photo"])) is None:
            raise ValidationError({"photo": Checkpoint.RESULTS["photo_missing"] + " — take a live photo before submitting."})
        cp = Checkpoint.objects.select_related("campaign", "project_site").get(pk=checkpoint.pk)
        campaign = cp.campaign
        participant = cp.employee_id == employee.id and campaign.participants.filter(employee=employee).exists()
        if not participant:
            self.audit.checkpoint(cp, "rejected_submission", employee.user, {"reason": "unauthorized_employee"})
            raise ValidationError({"checkpoint": Checkpoint.RESULTS["unauthorized_employee"] + "."})
        if cp.is_completed:
            self.audit.checkpoint(cp, "rejected_submission", employee.user, {"reason": "duplicate_submission"})
            raise ValidationError({"checkpoint": Checkpoint.RESULTS["duplicate_submission"] + " — you have already completed this checkpoint."})
        if campaign.status == CheckpointCampaign.PAUSED:
            raise ValidationError({"checkpoint": Checkpoint.RESULTS["checkpoint_paused"] + " — HR has paused this checkpoint; wait for it to resume."})
        if not campaign.accepts_submissions(now):
            cp.submission_attempts += 1
            cp.last_attempt_at, cp.last_attempt_result = now, "checkpoint_expired"
            cp.client_timestamp, cp.network_status = self._client_time(data.get("client_timestamp")), data.get("network_status")
            cp.save()
            self.audit.checkpoint(cp, "late_submission", employee.user, {"at": str(now)})
            raise ValidationError({"checkpoint": f"{Checkpoint.RESULTS['checkpoint_expired']} — the deadline was {campaign.expires_at:%-I:%M %p}. You can add an explanation for HR."})

        with transaction.atomic():
            cp = Checkpoint.objects.select_for_update().select_related("campaign", "project_site", "employee").get(pk=checkpoint.pk)
            if cp.is_completed:
                raise ValidationError({"checkpoint": Checkpoint.RESULTS["duplicate_submission"] + "."})
            lat, lng, acc = data.get("latitude"), data.get("longitude"), data.get("accuracy")
            outcome = self.evaluate(cp, employee, lat, lng, acc, now)
            photo_path = self.photos.store(cp, str(data["photo"]), outcome["gps_label"])
            if photo_path is None:
                raise ValidationError({"photo": Checkpoint.RESULTS["photo_missing"] + "."})
            accepted = outcome["status"] == Checkpoint.RESPONDED
            cp.status = outcome["status"]
            cp.verification_result = outcome["verification"] if accepted else None
            cp.failure_reason = None if accepted else outcome["reason"]
            cp.validation_message = outcome["message"]
            cp.submission_attempts += 1
            cp.last_attempt_at, cp.last_attempt_result = now, outcome["reason"] or "verified_presence"
            cp.submitted_at = now if accepted else None
            cp.server_timestamp = now
            cp.client_timestamp = self._client_time(data.get("client_timestamp"))
            cp.network_status = data.get("network_status") or "online"
            cp.latitude, cp.longitude, cp.gps_accuracy_meters = lat, lng, acc
            cp.distance_from_site_meters, cp.matched_site_id, cp.within_geofence = outcome["distance"], outcome["matched_site_id"], outcome["within"]
            cp.photo_path = photo_path
            if accepted:
                cp.issue_reported = None
            cp.save()
            self.audit.checkpoint(cp, "submitted" if accepted else "attempt_failed", employee.user, {"result": outcome["reason"] or "verified_presence", "distance_m": outcome["distance"], "attempt": cp.submission_attempts})
            return cp

    def evaluate(self, cp, employee, lat, lng, acc, now) -> dict:
        site = cp.project_site
        campaign_distance = round(distance_meters(lat, lng, site.latitude, site.longitude), 2) if (lat is not None and lng is not None and site) else None
        if lat is None or lng is None:
            return dict(status=Checkpoint.GPS_UNAVAILABLE, verification=None, reason="gps_unavailable",
                        message=f"Location was not available on the device, so presence at {site.name if site else 'the site'} could not be verified. You may retry while the checkpoint is open.",
                        distance=None, within=None, matched_site_id=None, gps_label="Unavailable")
        sites = list(Site.objects.active_on(now.date()))
        if site and site.id not in {s.id for s in sites}:
            sites.append(site)
        result = self.geofence.evaluate(employee, lat, lng, acc, sites, now)
        within_campaign_site = site is not None and campaign_distance is not None and campaign_distance <= float(site.geofence_radius_m)
        dist = f"{campaign_distance:,.0f}"
        acc_s = f"{float(acc or 0):,.0f}"
        if within_campaign_site:
            if result.status == GeofenceService.LOW_ACCURACY:
                return dict(status=Checkpoint.RESPONDED, verification=Checkpoint.COMPLETED_LOW_ACCURACY, reason="low_gps_accuracy",
                            message=f"Completed inside the {site.name} geofence ({dist} m from centre), but GPS accuracy was ±{acc_s} m.",
                            distance=campaign_distance, within=True, matched_site_id=site.id, gps_label=f"Low accuracy ±{acc_s} m")
            return dict(status=Checkpoint.RESPONDED, verification=Checkpoint.COMPLETED, reason=None,
                        message=f"Verified presence — inside the {site.name} geofence ({dist} m from centre).",
                        distance=campaign_distance, within=True, matched_site_id=site.id, gps_label=f"Verified · {dist} m")
        if result.status == GeofenceService.LOW_ACCURACY:
            return dict(status=Checkpoint.GPS_UNAVAILABLE, verification=None, reason="low_gps_accuracy",
                        message=f"GPS accuracy was ±{acc_s} m and the fix landed {dist} m from {site.name} — too weak to confirm presence. Retry for a better fix.",
                        distance=campaign_distance, within=False, matched_site_id=result.site.id if result.site else None, gps_label=f"Low accuracy ±{acc_s} m")
        if result.within and result.site:
            return dict(status=Checkpoint.PENDING_REVIEW, verification=None, reason="alternate_location",
                        message=f"At {result.site.name} (an authorized location), {dist} m from {site.name}. Needs HR review.",
                        distance=campaign_distance, within=False, matched_site_id=result.site.id, gps_label=f"Alt. site · {result.site.name}")
        return dict(status=Checkpoint.OUTSIDE_GEOFENCE, verification=None, reason="outside_geofence",
                    message=f"Outside the {site.name} geofence — about {dist} m from the site centre (radius {int(site.geofence_radius_m)} m).",
                    distance=campaign_distance, within=False, matched_site_id=None, gps_label=f"Outside · {dist} m")

    @staticmethod
    def _client_time(iso):
        if not iso:
            return None
        try:
            dt = datetime.fromisoformat(str(iso).replace("Z", "+00:00"))
            return timezone.make_naive(dt) if timezone.is_aware(dt) else dt
        except Exception:
            return None


# ---- reviewer ----

class CheckpointReviewer:
    def __init__(self, audit: CheckpointAudit | None = None):
        self.audit = audit or CheckpointAudit()

    def record_explanation(self, cp, by, explanation, reason=None, note=None):
        return self._act(cp, by, "explanation_recorded", {"explanation": explanation, "reason": reason, "note": note},
                         {"employee_explanation": explanation, "hr_reason": reason or cp.hr_reason, "hr_note": note or cp.hr_note})

    def add_note(self, cp, by, note):
        return self._act(cp, by, "note_added", {"note": note}, {"hr_note": note})

    def mark_for_review(self, cp, by, note=None):
        if not cp.is_reviewable:
            raise StateError("A completed checkpoint has nothing to review.")
        return self._act(cp, by, "marked_for_review", {"note": note}, {"status": Checkpoint.PENDING_REVIEW, "hr_note": note or cp.hr_note})

    def approve(self, cp, by, reason, note=None):
        if not cp.is_reviewable:
            raise StateError("A completed checkpoint has nothing to approve.")
        cp = self._act(cp, by, "approved", {"reason": reason, "note": note}, {
            "status": Checkpoint.APPROVED_EXCEPTION, "verification_result": Checkpoint.COMPLETED_AFTER_REVIEW,
            "hr_reason": reason, "hr_note": note or cp.hr_note, "reviewed_by": by, "reviewed_at": timezone.now(),
        })
        self._notify_employee(cp, "approved")
        return cp

    def reject(self, cp, by, reason, note=None):
        if not cp.is_reviewable:
            raise StateError("A completed checkpoint has nothing to reject.")
        cp = self._act(cp, by, "rejected", {"reason": reason, "note": note}, {
            "status": Checkpoint.REJECTED_EXCEPTION, "verification_result": None,
            "hr_reason": reason, "hr_note": note or cp.hr_note, "reviewed_by": by, "reviewed_at": timezone.now(),
        })
        self._notify_employee(cp, "rejected")
        return cp

    def escalate(self, cp, by, note=None):
        if not cp.is_reviewable:
            raise StateError("A completed checkpoint cannot be escalated.")
        return self._act(cp, by, "escalated", {"note": note}, {"escalated_at": timezone.now(), "hr_note": note or cp.hr_note})

    def movement_context(self, cp) -> dict:
        """The day's approved leave and punches so an already-approved absence is visible to the reviewer."""
        at = cp.campaign.starts_at or cp.created_at
        day = at.date()
        leave = None
        for l in LeaveRequest.objects.filter(employee_id=cp.employee_id, status="approved", date_from__lte=day, date_to__gte=day).select_related("leave_type"):
            if l.is_early_leave and l.requested_time_out:
                if at >= datetime.combine(day, l.requested_time_out):
                    leave = l
                    break
            elif l.day_portion == "half_am":
                if at < datetime.combine(day, datetime.min.time()).replace(hour=12):
                    leave = l
                    break
            elif l.day_portion == "half_pm":
                if at >= datetime.combine(day, datetime.min.time()).replace(hour=12):
                    leave = l
                    break
            else:
                leave = l
                break
        punches = list(AttendanceLog.objects.filter(employee_id=cp.employee_id, logged_at__date=day).select_related("site").order_by("logged_at"))
        last_before = None
        for p in punches:
            if p.logged_at <= at:
                last_before = p
        return {"approved_leave": leave, "punches": punches, "clocked_out": last_before is not None and last_before.log_type == "time_out", "last_punch": last_before}

    def _act(self, cp, by, action, review: dict, attributes: dict):
        clean = {k: v for k, v in review.items() if v not in (None, "")}
        with transaction.atomic():
            CheckpointReview.objects.create(checkpoint=cp, reviewer=by, action=action, **clean)
            for k, v in attributes.items():
                setattr(cp, k, v)
            cp.save()
            self.audit.checkpoint(cp, action, by, clean or None)
            cp.refresh_from_db()
            return cp

    def _notify_employee(self, cp, outcome):
        user = cp.employee.user if cp.employee_id else None
        if user:
            notify(user, kind="approved" if outcome == "approved" else "rejected", title=f"Checkpoint {outcome}",
                   message=f"{cp.campaign.name}: HR {outcome} your checkpoint exception.", url=f"/my-checkpoints/{cp.id}")
