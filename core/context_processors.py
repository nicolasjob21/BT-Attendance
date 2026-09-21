from django.conf import settings

from accounts import rbac


class Can:
    """`can.run_payroll` in templates → user.has_perm('accounts.run_payroll')."""

    def __init__(self, user):
        self.user = user

    def __getitem__(self, key):
        if not self.user or not self.user.is_authenticated:
            return False
        return self.user.has_perm(rbac.perm_label(key.replace("_", " ")))

    def __contains__(self, key):  # so {% if 'x' in can %} never explodes
        return True


def app(request):
    user = getattr(request, "user", None)
    ctx = {"APP_NAME": settings.APP_NAME, "can": Can(user), "CHECKPOINT_POLL_SECONDS": settings.CHECKPOINTS["poll_seconds"]}
    if user is not None and user.is_authenticated:
        from django.utils.functional import SimpleLazyObject

        from checkpoints.models import Checkpoint

        ctx["notifs"] = SimpleLazyObject(lambda: list(user.notifications.all()[:8]))
        ctx["unread_count"] = SimpleLazyObject(lambda: user.notifications.filter(read_at__isnull=True).count())
        employee = getattr(user, "employee", None)
        has_cp = bool(employee) and user.can("clock attendance") and employee.has_checkpoint_access()
        ctx["has_checkpoint_access"] = has_cp
        ctx["open_checkpoints"] = (
            Checkpoint.objects.filter(employee=employee, status__in=Checkpoint.WAITING_STATUSES, campaign__status="active").count() if has_cp else 0
        )
        ctx["temporary_password_error"] = request.session.pop("temporary_password_error", None)
    return ctx
