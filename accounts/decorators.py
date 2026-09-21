from functools import wraps

from django.core.exceptions import PermissionDenied


def permission_required(*names):
    """Allow when the user holds ANY of the named permissions (mirrors `permission:a|b`)."""

    def decorator(view):
        @wraps(view)
        def wrapped(request, *args, **kwargs):
            if not request.user.is_authenticated:
                from django.contrib.auth.views import redirect_to_login

                return redirect_to_login(request.get_full_path())
            if not request.user.can_any(*names):
                raise PermissionDenied
            return view(request, *args, **kwargs)

        return wrapped

    return decorator


def employee_required(view):
    """The page needs an HR record behind the login (management-only accounts get 403)."""

    @wraps(view)
    def wrapped(request, *args, **kwargs):
        if not getattr(request.user, "employee", None):
            raise PermissionDenied
        return view(request, *args, **kwargs)

    return wrapped
