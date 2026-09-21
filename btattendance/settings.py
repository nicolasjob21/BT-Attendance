"""
Django settings for BT-Attendance.

Conventions follow BT-Inventory (Django 5.2, custom user model, dotenv,
dj-database-url, whitenoise) so the two projects can share code later.
"""

import os
from pathlib import Path

import dj_database_url
from dotenv import load_dotenv

load_dotenv()

BASE_DIR = Path(__file__).resolve().parent.parent

SECRET_KEY = os.getenv("SECRET_KEY", "dev-only-secret-key")
DEBUG = os.getenv("DEBUG", "False") == "True"
ALLOWED_HOSTS = os.getenv("ALLOWED_HOSTS", "localhost,127.0.0.1").split(",")
if DEBUG:
    ALLOWED_HOSTS = ["*"]

APP_NAME = "BT Attendance"
APP_URL = os.getenv("APP_URL", "http://localhost:8001")

INSTALLED_APPS = [
    *(["whitenoise.runserver_nostatic"] if DEBUG else []),
    "django.contrib.admin",
    "django.contrib.auth",
    "django.contrib.contenttypes",
    "django.contrib.sessions",
    "django.contrib.messages",
    "django.contrib.staticfiles",
    "django.contrib.humanize",
    "core",
    "accounts",
    "employees",
    "attendance",
    "leaveot",
    "payroll",
    "checkpoints",
]

MIDDLEWARE = [
    "django.middleware.security.SecurityMiddleware",
    "whitenoise.middleware.WhiteNoiseMiddleware",
    "django.contrib.sessions.middleware.SessionMiddleware",
    "django.middleware.common.CommonMiddleware",
    "django.middleware.csrf.CsrfViewMiddleware",
    "django.contrib.auth.middleware.AuthenticationMiddleware",
    "django.contrib.messages.middleware.MessageMiddleware",
    "django.middleware.clickjacking.XFrameOptionsMiddleware",
    # Redirects raised below this point reach htmx as HX-Redirect (session/CSRF cookies still set above).
    "core.middleware.HtmxRedirectMiddleware",
    # Same order as the Laravel app: presence first, then the temporary-password gate.
    "accounts.middleware.TrackUserPresenceMiddleware",
    "accounts.middleware.RequirePasswordChangeMiddleware",
    # Opportunistic checkpoint sweep when the scheduler isn't running (dev).
    "checkpoints.middleware.CheckpointSweepMiddleware",
]

ROOT_URLCONF = "btattendance.urls"

TEMPLATES = [
    {
        "BACKEND": "django.template.backends.django.DjangoTemplates",
        "DIRS": [BASE_DIR / "templates"],
        "APP_DIRS": True,
        "OPTIONS": {
            "context_processors": [
                "django.template.context_processors.request",
                "django.contrib.auth.context_processors.auth",
                "django.contrib.messages.context_processors.messages",
                "core.context_processors.app",
            ],
            "builtins": ["core.templatetags.ui"],
        },
    },
]

WSGI_APPLICATION = "btattendance.wsgi.application"

DATABASES = {
    "default": dj_database_url.config(
        default=os.getenv("DATABASE_URL", f"sqlite:///{BASE_DIR / 'db.sqlite3'}"),
        conn_max_age=600,
    )
}

AUTH_USER_MODEL = "accounts.User"

# Laravel stored bcrypt "$2y$" hashes in users.password. The first hasher is the
# default for new passwords; LaravelBcryptHasher lets existing accounts keep
# logging in after the switch, and Django upgrades the hash on next login.
# Raw "$2y$" bcrypt first: the users table is shared with the Laravel app (and the SSO plan),
# so PHP's password_verify() must keep working on anything Django writes. PBKDF2 stays
# so hashes created before this setting still verify (and get re-hashed on next login).
PASSWORD_HASHERS = [
    "accounts.hashers.LaravelBcryptHasher",
    "django.contrib.auth.hashers.PBKDF2PasswordHasher",
]

AUTH_PASSWORD_VALIDATORS = [
    {"NAME": "django.contrib.auth.password_validation.MinimumLengthValidator", "OPTIONS": {"min_length": 8}},
]

LOGIN_URL = "/login"
LOGIN_REDIRECT_URL = "/dashboard"
LOGOUT_REDIRECT_URL = "/login"

LANGUAGE_CODE = "en-us"
# The office runs on Manila time and every cutoff, clock and countdown is in it.
# Naive local datetimes (USE_TZ=False) match what the Laravel app stored, so the
# same database can be read by either app.
TIME_ZONE = "Asia/Manila"
USE_I18N = True
USE_TZ = False

STATIC_URL = "/static/"
STATICFILES_DIRS = [BASE_DIR / "static"]
STATIC_ROOT = BASE_DIR / "staticfiles"
STORAGES = {
    "default": {"BACKEND": "django.core.files.storage.FileSystemStorage"},
    "staticfiles": {"BACKEND": "whitenoise.storage.CompressedStaticFilesStorage"},
}

MEDIA_URL = "/storage/"
MEDIA_ROOT = BASE_DIR / "media" / "public"
# Private files (checkpoint photos) are never served directly.
PRIVATE_MEDIA_ROOT = BASE_DIR / "media" / "private"

DATA_UPLOAD_MAX_MEMORY_SIZE = 12 * 1024 * 1024  # base64 selfies

DEFAULT_AUTO_FIELD = "django.db.models.BigAutoField"

MESSAGE_STORAGE = "django.contrib.messages.storage.session.SessionStorage"

# ---- Module settings (same meaning as the Laravel config files) ----
ATTENDANCE = {
    # warning: record and flag · approval: record as pending · strict: reject
    "geofence_mode": os.getenv("ATTENDANCE_GEOFENCE_MODE", "warning"),
    "min_gps_accuracy_m": int(os.getenv("ATTENDANCE_MIN_GPS_ACCURACY_M", "100")),
    # map tiles on the soft-copy PNG (OpenStreetMap); off in tests / air-gapped installs
    "softcopy_map": os.getenv("ATTENDANCE_SOFTCOPY_MAP", "1") == "1",
}
PAYROLL = {
    "standard_workday_hours": float(os.getenv("PAYROLL_STANDARD_WORKDAY_HOURS", "8")),
    "ot_verification_hours": float(os.getenv("PAYROLL_OT_VERIFICATION_HOURS", "13")),
}
CHECKPOINTS = {
    "defaults": {"response_window_minutes": 10},
    "instructions": [
        "Capture the project entrance.",
        "Capture the project signboard.",
        "Capture the site office.",
        "Capture the current work area.",
        "Capture the equipment or materials area.",
        "Capture the designated site landmark.",
    ],
    "sweep_throttle_seconds": 15,
    "poll_seconds": 20,
}

if not DEBUG:
    SECURE_PROXY_SSL_HEADER = ("HTTP_X_FORWARDED_PROTO", "https")
    SESSION_COOKIE_SECURE = True
    CSRF_COOKIE_SECURE = True
