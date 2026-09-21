import json
import os
import uuid

from django.conf import settings
from django.contrib import messages
from django.contrib.auth import login as auth_login, logout as auth_logout, update_session_auth_hash, views as auth_views
from django.contrib.auth.decorators import login_required
from django.contrib.sessions.models import Session
from django.core.paginator import Paginator
from django.db import transaction
from django.db.models import Q
from django.http import JsonResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.utils import timezone
from django.views.decorators.http import require_POST

from core.support import media_path

from . import rbac
from .decorators import permission_required
from .forms import LoginForm, validate_username
from .models import User


class LoginView(auth_views.LoginView):
    template_name = "auth/login.html"
    authentication_form = LoginForm
    redirect_authenticated_user = True

    def form_valid(self, form):
        remember = form.cleaned_data.get("remember")
        response = super().form_valid(form)
        self.request.session.set_expiry(60 * 60 * 24 * 30 if remember else 0)
        user = form.get_user()
        User.all_objects.filter(pk=user.pk).update(last_login_at=timezone.now(), last_login_ip=self.request.META.get("REMOTE_ADDR"))
        return response


@require_POST
def logout_view(request):
    auth_logout(request)
    return redirect("login")


# ---- passwords ----

@login_required
@require_POST
def password_update(request):
    """Profile page: change own password (knows the current one)."""
    user = request.user
    current, new, confirm = request.POST.get("current_password", ""), request.POST.get("password", ""), request.POST.get("password_confirmation", "")
    errors = []
    if not user.check_password(current):
        errors.append("The current password is incorrect.")
    if len(new) < 8:
        errors.append("The new password must be at least 8 characters.")
    if new != confirm:
        errors.append("The password confirmation does not match.")
    if errors:
        request.session["password_errors"] = errors
        return redirect("profile.edit")
    user.set_own_password(new)
    user.save()
    update_session_auth_hash(request, user)
    messages.success(request, "Password updated.")
    return redirect("profile.edit")


@login_required
@require_POST
def password_temporary(request):
    """Forced change after signing in with a temporary password (the layout modal posts here)."""
    user = request.user
    current, new, confirm = request.POST.get("current_password", ""), request.POST.get("password", ""), request.POST.get("password_confirmation", "")
    errors = []
    if not user.check_password(current):
        errors.append("The temporary password is incorrect.")
    if len(new) < 8:
        errors.append("The new password must be at least 8 characters.")
    if new != confirm:
        errors.append("The password confirmation does not match.")
    if new and new == current:
        errors.append("The new password must be different from the temporary one.")
    if errors:
        request.session["temporary_password_error"] = " ".join(errors)
        return redirect(request.META.get("HTTP_REFERER") or "dashboard")
    user.set_own_password(new)
    user.save()
    update_session_auth_hash(request, user)
    messages.success(request, "Password updated. Welcome aboard!")
    return redirect(request.META.get("HTTP_REFERER") or "dashboard")


# ---- profile ----

@login_required
def profile_edit(request):
    return render(request, "accounts/profile.html", {
        "profile_errors": request.session.pop("profile_errors", None),
        "password_errors": request.session.pop("password_errors", None),
        "delete_errors": request.session.pop("delete_errors", None),
    })


@login_required
@require_POST
def profile_update(request):
    user = request.user
    name = request.POST.get("name", "").strip()
    email = request.POST.get("email", "").strip()
    errors = {}
    if not name:
        errors["name"] = "Name is required."
    try:
        username = validate_username(request.POST.get("username", ""), exclude_pk=user.pk)
    except Exception as e:
        errors["username"] = e.messages[0]
        username = user.username
    if not email or "@" not in email:
        errors["email"] = "Enter a valid e-mail."
    elif User.objects.filter(email=email).exclude(pk=user.pk).exists():
        errors["email"] = "That e-mail is already in use."
    photo = request.FILES.get("photo")
    if photo:
        if photo.size > 2 * 1024 * 1024:
            errors["photo"] = "The photo must be 2 MB or smaller."
        elif photo.content_type not in ("image/jpeg", "image/png", "image/webp"):
            errors["photo"] = "Use a JPG, PNG or WebP image."
    if errors:
        request.session["profile_errors"] = errors
        return redirect("profile.edit")
    user.name, user.username, user.email = name, username, email
    if request.POST.get("remove_photo") and user.profile_photo_path:
        _delete_media(user.profile_photo_path)
        user.profile_photo_path = None
    if photo:
        if user.profile_photo_path:
            _delete_media(user.profile_photo_path)
        ext = os.path.splitext(photo.name)[1].lower() or ".jpg"
        rel = f"avatars/{user.pk}/{uuid.uuid4().hex}{ext}"
        dest = media_path(rel)
        os.makedirs(dest.parent, exist_ok=True)
        with open(dest, "wb") as f:
            for chunk in photo.chunks():
                f.write(chunk)
        user.profile_photo_path = rel
    user.save()
    if user.employee:
        first, _, last = name.partition(" ")
        user.employee.first_name, user.employee.last_name, user.employee.email = first, last, email
        user.employee.save(update_fields=["first_name", "last_name", "email"])
    messages.success(request, "Profile updated.")
    return redirect("profile.edit")


@login_required
@require_POST
def profile_destroy(request):
    user = request.user
    if not user.check_password(request.POST.get("password", "")):
        request.session["delete_errors"] = ["The password is incorrect."]
        return redirect("profile.edit")
    auth_logout(request)
    if user.profile_photo_path:
        _delete_media(user.profile_photo_path)
    user.soft_delete()
    return redirect("/")


def _delete_media(rel):
    try:
        (media_path(rel)).unlink()
    except Exception:
        pass


# ---- user management ----

STATUSES = ["online", "offline", "disabled", "deleted"]


@permission_required("manage users")
def users_index(request):
    search = request.GET.get("search", "").strip()
    role = request.GET.get("role", "")
    status = request.GET.get("status", "") if request.GET.get("status", "") in STATUSES else ""
    qs = (User.all_objects.filter(deleted_at__isnull=False) if status == "deleted" else User.objects.all()).select_related("employee").prefetch_related("groups")
    if search:
        qs = qs.filter(Q(name__icontains=search) | Q(username__icontains=search) | Q(email__icontains=search))
    if role:
        qs = qs.filter(groups__name=role)
    if status == "online":
        qs = qs.online()
    elif status == "offline":
        cutoff = timezone.now() - timezone.timedelta(seconds=User.PRESENCE_ONLINE_WITHIN)
        qs = qs.filter(disabled_at__isnull=True).filter(Q(last_seen_at__isnull=True) | Q(last_seen_at__lte=cutoff))
    elif status == "disabled":
        qs = qs.filter(disabled_at__isnull=False)
    page = Paginator(qs.order_by("name"), 15).get_page(request.GET.get("page"))
    counts = {
        "total": User.objects.count(),
        "online": User.objects.online().count(),
        "disabled": User.objects.filter(disabled_at__isnull=False).count(),
        "deleted": User.all_objects.filter(deleted_at__isnull=False).count(),
    }
    return render(request, "accounts/users_index.html", {
        "users": page, "counts": counts, "roles": [(r, rbac.ROLE_LABELS[r]) for r in rbac.ROLES],
        "search": search, "role": role, "status": status, "online_within": User.PRESENCE_ONLINE_WITHIN,
        "ids": [u.id for u in page.object_list],
        "back_url": "/dashboard", "back_label": "Back to Dashboard",
    })


@permission_required("manage users")
def users_presence(request):
    ids = [int(i) for i in request.GET.get("ids", "").split(",") if i.isdigit()][:100]
    rows = User.all_objects.filter(id__in=ids)
    from core.templatetags.ui import diff_for_humans

    return JsonResponse({"users": {u.id: {"status": u.presence_status, "last_seen": diff_for_humans(u.last_seen_at) if u.last_seen_at else None} for u in rows}})


def _assignable_roles(actor, target):
    roles = list(rbac.assignable_by(actor.role))
    if target.role:
        roles.append(target.role)
    return [(r, rbac.ROLE_LABELS[r]) for r in rbac.ROLES if r in roles]


def _is_last_superadmin(user):
    return user.role == rbac.SUPERADMIN and not User.objects.filter(groups__name=rbac.SUPERADMIN, disabled_at__isnull=True).exclude(pk=user.pk).exists()


@permission_required("manage users")
def users_edit(request, pk):
    target = get_object_or_404(User.objects.select_related("employee"), pk=pk)
    return render(request, "accounts/users_edit.html", {
        "target": target, "roles": _assignable_roles(request.user, target),
        "errors": request.session.pop("form_errors", None), "old": request.session.pop("form_old", None) or {},
        "back_url": "/users", "back_label": "Back to User Management",
    })


@permission_required("manage users")
@require_POST
def users_update(request, pk):
    target = get_object_or_404(User, pk=pk)
    data = {k: request.POST.get(k, "").strip() for k in ("name", "username", "email", "role", "password")}
    errors = {}
    if not data["name"]:
        errors["name"] = "Name is required."
    try:
        data["username"] = validate_username(data["username"], exclude_pk=target.pk)
    except Exception as e:
        errors["username"] = e.messages[0]
    if not data["email"] or "@" not in data["email"]:
        errors["email"] = "Enter a valid e-mail."
    elif User.objects.filter(email=data["email"]).exclude(pk=target.pk).exists():
        errors["email"] = "That e-mail is already in use."
    if data["role"] not in rbac.ROLES:
        errors["role"] = "Pick a role."
    if data["password"] and len(data["password"]) < 8:
        errors["password"] = "The password must be at least 8 characters."
    current = target.role
    if not errors:
        if data["role"] != current and data["role"] not in rbac.assignable_by(request.user.role):
            errors["role"] = f"You cannot assign the {rbac.ROLE_LABELS[data['role']]} role."
        elif target.pk == request.user.pk and data["role"] != current:
            errors["role"] = "You cannot change your own role."
        elif _is_last_superadmin(target) and data["role"] != rbac.SUPERADMIN:
            errors["role"] = "At least one Super Admin account must remain."
    if errors:
        request.session["form_errors"], request.session["form_old"] = errors, {k: v for k, v in data.items() if k != "password"}
        return redirect("users.edit", pk=pk)
    with transaction.atomic():
        target.name, target.username, target.email = data["name"], data["username"], data["email"]
        if data["password"]:
            target.set_temporary_password(data["password"])
        target.save()
        target.set_role(data["role"])
        if getattr(target, "employee", None):
            first, _, last = data["name"].partition(" ")
            target.employee.first_name, target.employee.last_name, target.employee.email = first, last, data["email"]
            target.employee.save(update_fields=["first_name", "last_name", "email"])
    messages.success(request, f"Account {target.username} updated.")
    return redirect("users.index")


def _end_sessions(user):
    for s in Session.objects.all():
        if str(s.get_decoded().get("_auth_user_id")) == str(user.pk):
            s.delete()


@permission_required("manage users")
@require_POST
def users_toggle_disabled(request, pk):
    target = get_object_or_404(User, pk=pk)
    if target.pk == request.user.pk:
        messages.error(request, "You cannot disable your own account.")
        return redirect("users.index")
    if target.is_disabled:
        target.disabled_at = None
        target.save(update_fields=["disabled_at"])
        messages.success(request, f"{target.username} can sign in again.")
        return redirect(request.META.get("HTTP_REFERER") or "users.index")
    if _is_last_superadmin(target):
        messages.error(request, "At least one Super Admin account must remain enabled.")
        return redirect("users.index")
    target.disabled_at = timezone.now()
    target.save(update_fields=["disabled_at"])
    _end_sessions(target)
    messages.success(request, f"{target.username} is disabled and signed out.")
    return redirect(request.META.get("HTTP_REFERER") or "users.index")


@permission_required("manage users")
@require_POST
def users_destroy(request, pk):
    target = get_object_or_404(User, pk=pk)
    if not request.user.check_password(request.POST.get("password", "")):
        messages.error(request, "The password is incorrect.")
        return redirect("users.index")
    if target.pk == request.user.pk:
        messages.error(request, "You cannot delete your own account.")
        return redirect("users.index")
    if _is_last_superadmin(target):
        messages.error(request, "At least one Super Admin account must remain.")
        return redirect("users.index")
    _end_sessions(target)
    target.soft_delete()
    messages.success(request, f"Account {target.username} deleted. It can be restored from the Deleted filter.")
    return redirect("users.index")


@permission_required("manage users")
@require_POST
def users_restore(request, pk):
    target = get_object_or_404(User.all_objects.filter(deleted_at__isnull=False), pk=pk)
    target.restore()
    messages.success(request, f"Account {target.username} restored.")
    return redirect("users.index")
