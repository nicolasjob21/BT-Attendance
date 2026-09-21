from django.conf import settings
from django.db import models
from django.utils import timezone


class Setting(models.Model):
    """Key/value store for UI-editable module settings (JSON values)."""

    key = models.CharField(max_length=191, primary_key=True)
    value = models.JSONField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "settings"

    @classmethod
    def get(cls, key, default=None):
        row = cls.objects.filter(pk=key).first()
        return row.value if row else default

    @classmethod
    def set(cls, key, value):
        cls.objects.update_or_create(key=key, defaults={"value": value})


class Notification(models.Model):
    """In-app notification (the bell). Mirrors Laravel's database channel."""

    KINDS = ["request", "approved", "rejected", "payroll", "checkpoint", "attendance"]

    user = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.CASCADE, related_name="notifications")
    kind = models.CharField(max_length=30, default="request")
    title = models.CharField(max_length=255)
    message = models.TextField(blank=True)
    url = models.CharField(max_length=2048, blank=True)
    read_at = models.DateTimeField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        db_table = "app_notifications"
        ordering = ["-created_at"]

    @property
    def is_unread(self):
        return self.read_at is None

    def mark_read(self):
        if self.read_at is None:
            self.read_at = timezone.now()
            self.save(update_fields=["read_at"])


def notify(users, *, kind, title, message="", url=""):
    """Send one notification to each user (accepts a queryset, list or single user)."""
    if users is None:
        return
    if not hasattr(users, "__iter__"):
        users = [users]
    Notification.objects.bulk_create(
        [Notification(user=u, kind=kind, title=title, message=message, url=url) for u in users if u is not None]
    )


def users_with_permission(name):
    """Every active user holding a named permission (via group or directly), plus superusers."""
    from django.contrib.auth import get_user_model
    from django.db.models import Q

    from accounts.rbac import codename

    User = get_user_model()
    code = codename(name)
    return (
        User.objects.filter(disabled_at__isnull=True)
        .filter(Q(is_superuser=True) | Q(groups__permissions__codename=code) | Q(user_permissions__codename=code))
        .distinct()
    )
