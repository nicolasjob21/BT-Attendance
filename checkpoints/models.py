from django.conf import settings
from django.db import models
from django.utils import timezone

from employees.models import Employee, Site


class CampaignQuerySet(models.QuerySet):
    def live(self):
        return self.filter(status__in=[CheckpointCampaign.ACTIVE, CheckpointCampaign.PAUSED])

    def history(self):
        return self.filter(status__in=[CheckpointCampaign.COMPLETED, CheckpointCampaign.CANCELLED])


class CheckpointCampaign(models.Model):
    DRAFT, ACTIVE, PAUSED, EXPIRED, COMPLETED, CANCELLED = "draft", "active", "paused", "expired", "completed", "cancelled"
    STATUSES = {DRAFT: "Draft", ACTIVE: "Active", PAUSED: "Paused", EXPIRED: "Expired", COMPLETED: "Completed", CANCELLED: "Cancelled"}

    name = models.CharField(max_length=255)
    project_site = models.ForeignKey(Site, on_delete=models.CASCADE, related_name="checkpoint_campaigns")
    instruction = models.CharField(max_length=255)
    reason = models.TextField(null=True, blank=True)
    response_window_minutes = models.PositiveIntegerField(default=10)
    schedule_mode = models.CharField(max_length=20, null=True, blank=True)  # fixed | random
    scheduled_start_at = models.DateTimeField(null=True, blank=True)
    random_window_start = models.TimeField(null=True, blank=True)
    random_window_end = models.TimeField(null=True, blank=True)
    starts_at = models.DateTimeField(null=True, blank=True)
    expires_at = models.DateTimeField(null=True, blank=True)
    status = models.CharField(max_length=20, default="draft")
    created_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="created_by")
    activated_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="activated_by")
    activated_at = models.DateTimeField(null=True, blank=True)
    paused_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="paused_by")
    paused_at = models.DateTimeField(null=True, blank=True)
    closed_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="closed_by")
    closed_at = models.DateTimeField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    objects = CampaignQuerySet.as_manager()

    class Meta:
        db_table = "checkpoint_campaigns"
        ordering = ["-id"]

    def __str__(self):
        return self.name

    @property
    def site(self):
        return self.project_site

    @property
    def is_draft(self):
        return self.status == self.DRAFT

    @property
    def is_scheduled(self):
        return self.status == self.DRAFT and self.scheduled_start_at is not None

    @property
    def is_randomly_scheduled(self):
        return self.schedule_mode == "random" and self.scheduled_start_at is not None

    def random_window_label(self):
        if not self.random_window_start or not self.random_window_end:
            return None
        return f"{self.random_window_start:%-I:%M %p} – {self.random_window_end:%-I:%M %p}"

    @property
    def is_active(self):
        return self.status == self.ACTIVE

    @property
    def is_live(self):
        return self.status in (self.ACTIVE, self.PAUSED)

    @property
    def is_finished(self):
        return self.status in (self.COMPLETED, self.CANCELLED)

    def accepts_submissions(self, now=None):
        now = now or timezone.now()
        return self.is_active and self.starts_at and self.expires_at and self.starts_at <= now <= self.expires_at

    def seconds_remaining(self, now=None):
        if not self.expires_at:
            return 0
        return max(0, int((self.expires_at - (now or timezone.now())).total_seconds()))

    @property
    def status_label(self):
        if self.is_scheduled:
            return "Scheduled"
        return self.STATUSES.get(self.status, self.status.capitalize())


class CheckpointCampaignParticipant(models.Model):
    campaign = models.ForeignKey(CheckpointCampaign, on_delete=models.CASCADE, related_name="participants")
    employee = models.ForeignKey(Employee, on_delete=models.CASCADE, related_name="checkpoint_participations")
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "checkpoint_campaign_participants"
        unique_together = [("campaign", "employee")]


class CheckpointQuerySet(models.QuerySet):
    def completed(self):
        return self.filter(status__in=Checkpoint.COMPLETED_STATUSES)

    def non_compliant(self):
        return self.filter(status__in=Checkpoint.NON_COMPLIANT_STATUSES)

    def needs_review(self):
        return self.filter(status=Checkpoint.PENDING_REVIEW)


class Checkpoint(models.Model):
    PENDING, NOTIFIED, RESPONDED, MISSED = "pending", "notified", "responded", "missed"
    OUTSIDE_GEOFENCE, GPS_UNAVAILABLE = "outside_geofence", "gps_unavailable"
    CAMERA_PERMISSION_DENIED, SUBMISSION_FAILED = "camera_permission_denied", "submission_failed"
    PENDING_REVIEW, APPROVED_EXCEPTION, REJECTED_EXCEPTION = "pending_review", "approved_exception", "rejected_exception"

    STATUSES = {
        PENDING: "Pending",
        NOTIFIED: "Notified – not responded",
        RESPONDED: "Completed",
        MISSED: "Missed",
        OUTSIDE_GEOFENCE: "Outside geofence",
        GPS_UNAVAILABLE: "GPS unavailable",
        CAMERA_PERMISSION_DENIED: "Camera permission denied",
        SUBMISSION_FAILED: "Submission failed",
        PENDING_REVIEW: "Pending HR review",
        APPROVED_EXCEPTION: "Approved exception",
        REJECTED_EXCEPTION: "Rejected exception",
    }
    COMPLETED_STATUSES = [RESPONDED, APPROVED_EXCEPTION]
    WAITING_STATUSES = [PENDING, NOTIFIED, OUTSIDE_GEOFENCE, GPS_UNAVAILABLE, CAMERA_PERMISSION_DENIED, SUBMISSION_FAILED]
    NON_COMPLIANT_STATUSES = [MISSED, OUTSIDE_GEOFENCE, GPS_UNAVAILABLE, CAMERA_PERMISSION_DENIED, SUBMISSION_FAILED, PENDING_REVIEW, REJECTED_EXCEPTION]

    COMPLETED, COMPLETED_LOW_ACCURACY, COMPLETED_AFTER_REVIEW = "completed", "completed_with_low_gps_accuracy", "completed_after_review"
    VERIFICATION_RESULTS = {COMPLETED: "Completed", COMPLETED_LOW_ACCURACY: "Completed with low GPS accuracy", COMPLETED_AFTER_REVIEW: "Completed after review"}
    RESULTS = {
        "verified_presence": "Verified presence", "low_gps_accuracy": "Low GPS accuracy", "outside_geofence": "Outside geofence",
        "alternate_location": "At another authorized location", "gps_unavailable": "GPS unavailable", "photo_missing": "Photo missing",
        "checkpoint_expired": "Checkpoint expired", "checkpoint_paused": "Checkpoint paused", "duplicate_submission": "Already completed",
        "unauthorized_employee": "Unauthorized employee", "no_response": "No response",
    }
    ISSUES = {"camera_denied": "Camera permission denied", "gps_unavailable": "GPS unavailable", "no_internet": "No internet connection", "device_problem": "Device problem"}
    HR_REASONS = {
        "no_internet": "No internet connection", "device_problem": "Device problem", "gps_problem": "GPS problem",
        "camera_permission": "Camera permission problem", "work_related": "Work-related reason",
        "temporarily_away": "Employee was temporarily away from the site", "forgot": "Employee forgot to complete the checkpoint",
        "ignored": "Employee ignored the checkpoint", "other": "Other reason",
    }

    campaign = models.ForeignKey(CheckpointCampaign, on_delete=models.CASCADE, related_name="checkpoints")
    employee = models.ForeignKey(Employee, on_delete=models.CASCADE, related_name="checkpoints")
    project_site = models.ForeignKey(Site, on_delete=models.CASCADE, related_name="+")
    status = models.CharField(max_length=40, default="pending")
    verification_result = models.CharField(max_length=60, null=True, blank=True)
    failure_reason = models.CharField(max_length=60, null=True, blank=True)
    validation_message = models.CharField(max_length=255, null=True, blank=True)
    notified_at = models.DateTimeField(null=True, blank=True)
    seen_at = models.DateTimeField(null=True, blank=True)
    submission_attempts = models.PositiveIntegerField(default=0)
    last_attempt_at = models.DateTimeField(null=True, blank=True)
    last_attempt_result = models.CharField(max_length=60, null=True, blank=True)
    issue_reported = models.CharField(max_length=40, null=True, blank=True)
    submitted_at = models.DateTimeField(null=True, blank=True)
    server_timestamp = models.DateTimeField(null=True, blank=True)
    client_timestamp = models.DateTimeField(null=True, blank=True)
    latitude = models.DecimalField(max_digits=10, decimal_places=7, null=True, blank=True)
    longitude = models.DecimalField(max_digits=10, decimal_places=7, null=True, blank=True)
    gps_accuracy_meters = models.DecimalField(max_digits=10, decimal_places=2, null=True, blank=True)
    distance_from_site_meters = models.DecimalField(max_digits=10, decimal_places=2, null=True, blank=True)
    matched_site = models.ForeignKey(Site, null=True, blank=True, on_delete=models.SET_NULL, related_name="+")
    within_geofence = models.BooleanField(null=True, blank=True)
    photo_path = models.CharField(max_length=2048, null=True, blank=True)
    network_status = models.CharField(max_length=20, null=True, blank=True)
    employee_explanation = models.TextField(null=True, blank=True)
    hr_reason = models.CharField(max_length=40, null=True, blank=True)
    hr_note = models.TextField(null=True, blank=True)
    escalated_at = models.DateTimeField(null=True, blank=True)
    reviewed_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="reviewed_by")
    reviewed_at = models.DateTimeField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    objects = CheckpointQuerySet.as_manager()

    class Meta:
        db_table = "checkpoints"
        ordering = ["id"]

    @property
    def site(self):
        return self.project_site

    def reference(self):
        return f"CP-{self.id:06d}"

    @property
    def is_completed(self):
        return self.status in self.COMPLETED_STATUSES

    @property
    def is_waiting(self):
        return self.status in self.WAITING_STATUSES

    @property
    def is_non_compliant(self):
        return self.status in self.NON_COMPLIANT_STATUSES

    @property
    def is_reviewable(self):
        return self.status != self.RESPONDED

    @property
    def has_submission(self):
        return self.submitted_at is not None or self.last_attempt_at is not None

    def result(self):
        if self.is_completed:
            return "completed"
        if self.campaign.is_live and self.is_waiting:
            return "waiting"
        return "not_completed"

    @property
    def result_label(self):
        return {"completed": "Completed", "waiting": "Waiting", "not_completed": "Not completed"}[self.result()]

    def response_seconds(self):
        if not self.submitted_at or not self.campaign.starts_at:
            return None
        return int((self.submitted_at - self.campaign.starts_at).total_seconds())

    @property
    def status_label(self):
        if self.issue_reported == "no_internet" and self.status in (self.MISSED, self.PENDING, self.NOTIFIED):
            return "No internet reported"
        return self.STATUSES.get(self.status, self.status.replace("_", " ").capitalize())

    @property
    def verification_label(self):
        return self.VERIFICATION_RESULTS.get(self.verification_result, self.verification_result) if self.verification_result else None

    @property
    def failure_label(self):
        return self.RESULTS.get(self.failure_reason, (self.failure_reason or "").replace("_", " ").capitalize()) if self.failure_reason else None

    @property
    def hr_reason_label(self):
        return self.HR_REASONS.get(self.hr_reason, self.hr_reason) if self.hr_reason else None

    @property
    def notification_status(self):
        if self.seen_at:
            return f"Seen {self.seen_at:%-I:%M %p}"
        if self.notified_at:
            return f"Sent {self.notified_at:%-I:%M %p}"
        return "Not sent"


class CheckpointReview(models.Model):
    ACTIONS = {
        "explanation_recorded": "Explanation recorded", "note_added": "HR note added", "marked_for_review": "Marked for review",
        "approved": "Exception approved", "rejected": "Exception rejected", "escalated": "Escalated to management",
    }

    checkpoint = models.ForeignKey(Checkpoint, on_delete=models.CASCADE, related_name="reviews")
    reviewer = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+")
    action = models.CharField(max_length=40)
    reason = models.CharField(max_length=60, null=True, blank=True)
    explanation = models.TextField(null=True, blank=True)
    note = models.TextField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        db_table = "checkpoint_reviews"
        ordering = ["-created_at", "-id"]

    @property
    def action_label(self):
        return self.ACTIONS.get(self.action, self.action.replace("_", " ").capitalize())


class CheckpointAuditLog(models.Model):
    campaign = models.ForeignKey(CheckpointCampaign, null=True, blank=True, on_delete=models.CASCADE, related_name="audit_logs")
    checkpoint = models.ForeignKey(Checkpoint, null=True, blank=True, on_delete=models.CASCADE, related_name="audit_logs")
    user = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+")
    action = models.CharField(max_length=60)
    details = models.JSONField(null=True, blank=True)
    ip_address = models.CharField(max_length=45, null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        db_table = "checkpoint_audit_logs"
        ordering = ["-created_at", "-id"]

    @property
    def action_label(self):
        return self.action.replace("_", " ").capitalize()
