import re
from datetime import timedelta

from django.contrib.auth.models import AbstractBaseUser, PermissionsMixin, UserManager as DjangoUserManager
from django.db import models
from django.utils import timezone

from . import rbac


class AppPermission(models.Model):
    """Carrier for the app's named permissions ('run payroll' → accounts.run_payroll)."""

    class Meta:
        managed = False
        default_permissions = ()
        permissions = [(rbac.codename(n), n) for n in rbac.PERMISSIONS]


class UserQuerySet(models.QuerySet):
    def alive(self):
        return self.filter(deleted_at__isnull=True)

    def online(self):
        return self.alive().filter(disabled_at__isnull=True, last_seen_at__gt=timezone.now() - timedelta(seconds=User.PRESENCE_ONLINE_WITHIN))


class AllUserManager(DjangoUserManager.from_queryset(UserQuerySet)):
    """Sees every account, including soft-deleted ones."""


class UserManager(AllUserManager):
    """Default manager hides soft-deleted accounts."""

    def get_queryset(self):
        return super().get_queryset().filter(deleted_at__isnull=True)

    def _create_user(self, username, email, password, **extra):
        user = self.model(username=User.normalize_username(username), email=email or "", **extra)
        user.set_password(password)
        user.save(using=self._db)
        return user

    def create_user(self, username, email=None, password=None, **extra):
        extra.setdefault("is_superuser", False)
        return self._create_user(username, email, password, **extra)

    def create_superuser(self, username, email=None, password=None, **extra):
        extra["is_superuser"] = True
        extra["is_staff"] = True
        return self._create_user(username, email, password, **extra)


class User(AbstractBaseUser, PermissionsMixin):
    """
    The login. Columns mirror the Laravel `users` table so the same database can
    be used by either app; Django's own auth columns are added on top.
    """

    PRESENCE_ONLINE_WITHIN = 300  # seconds
    PRESENCE_WRITE_EVERY = 60  # throttle last_seen_at writes

    name = models.CharField(max_length=255)
    email = models.EmailField(max_length=255)
    username = models.CharField(max_length=60, unique=True)
    profile_photo_path = models.CharField(max_length=2048, null=True, blank=True)
    last_seen_at = models.DateTimeField(null=True, blank=True)
    last_login_at = models.DateTimeField(null=True, blank=True)
    last_login_ip = models.CharField(max_length=45, null=True, blank=True)
    disabled_at = models.DateTimeField(null=True, blank=True)
    deleted_at = models.DateTimeField(null=True, blank=True)
    must_change_password = models.BooleanField(default=False)
    is_staff = models.BooleanField(default=False)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    USERNAME_FIELD = "username"
    REQUIRED_FIELDS = ["name", "email"]

    objects = UserManager()
    all_objects = AllUserManager()

    class Meta:
        db_table = "users"

    def __str__(self):
        return self.name or self.username

    # ---- identity ----
    @staticmethod
    def normalize_username(value: str) -> str:
        value = (value or "").strip().lower()
        return re.sub(r"\s+", "-", value)

    @property
    def is_active(self):
        return self.disabled_at is None and self.deleted_at is None

    @property
    def is_disabled(self):
        return self.disabled_at is not None

    @property
    def is_deleted(self):
        return self.deleted_at is not None

    # ---- roles ----
    @property
    def role(self):
        names = set(self.groups.values_list("name", flat=True))
        for r in rbac.ROLES:  # highest first
            if r in names:
                return r
        return None

    @property
    def role_label(self):
        return rbac.ROLE_LABELS.get(self.role, "—")

    def set_role(self, role):
        from django.contrib.auth.models import Group

        self.groups.set([Group.objects.get_or_create(name=role)[0]] if role else [])

    def can(self, name: str) -> bool:
        return self.has_perm(rbac.perm_label(name))

    def can_any(self, *names) -> bool:
        return any(self.can(n) for n in names)

    # ---- passwords ----
    def check_password(self, raw_password):
        """Django identifies hashers by an "algo$" prefix, which Laravel's raw "$2y$" hashes lack — route those ourselves."""
        from .hashers import LaravelBcryptHasher, is_raw_bcrypt

        if is_raw_bcrypt(self.password or ""):
            return LaravelBcryptHasher().verify(raw_password, self.password)
        return super().check_password(raw_password)

    def set_temporary_password(self, plain: str):
        self.set_password(plain)
        self.must_change_password = True

    def set_own_password(self, plain: str):
        self.set_password(plain)
        self.must_change_password = False

    # ---- presence ----
    @property
    def is_online(self):
        return bool(self.last_seen_at) and self.last_seen_at > timezone.now() - timedelta(seconds=self.PRESENCE_ONLINE_WITHIN)

    @property
    def presence_status(self):
        if self.deleted_at:
            return "deleted"
        if self.disabled_at:
            return "disabled"
        return "online" if self.is_online else "offline"

    def touch_presence(self, ip=None):
        now = timezone.now()
        if self.last_seen_at and self.last_seen_at > now - timedelta(seconds=self.PRESENCE_WRITE_EVERY):
            return
        User.all_objects.filter(pk=self.pk).update(last_seen_at=now)
        self.last_seen_at = now

    # ---- lifecycle ----
    def soft_delete(self):
        self.deleted_at = timezone.now()
        self.save(update_fields=["deleted_at"])

    def restore(self):
        self.deleted_at = None
        self.save(update_fields=["deleted_at"])

    @property
    def initials(self):
        parts = (self.name or self.username).split()
        return "".join(p[0] for p in parts[:2]).upper()

    @property
    def profile_photo_url(self):
        from django.conf import settings

        return f"{settings.MEDIA_URL}{self.profile_photo_path}" if self.profile_photo_path else None
