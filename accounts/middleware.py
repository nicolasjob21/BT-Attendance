from django.http import JsonResponse
from django.shortcuts import redirect
from django.urls import reverse


class TrackUserPresenceMiddleware:
    """Stamp users.last_seen_at (throttled) so User Management can show who is online."""

    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        user = getattr(request, "user", None)
        if user is not None and user.is_authenticated:
            user.touch_presence(request.META.get("REMOTE_ADDR"))
        return self.get_response(request)


class RequirePasswordChangeMiddleware:
    """
    A user holding a temporary (admin-given) password can do nothing until they
    set their own. Only the password-change and logout routes go through; GET
    requests still render so the layout can show the modal.
    """

    ALLOWED = {"password.temporary", "logout", "login"}
    SAFE = {"GET", "HEAD", "OPTIONS"}

    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        return self.get_response(request)

    def process_view(self, request, view_func, view_args, view_kwargs):
        user = getattr(request, "user", None)
        if not (user is not None and user.is_authenticated and user.must_change_password):
            return None
        name = request.resolver_match.url_name if request.resolver_match else None
        if name in self.ALLOWED or request.method in self.SAFE:
            return None
        wants_json = request.headers.get("x-requested-with") == "XMLHttpRequest" or "application/json" in request.headers.get("accept", "")
        if wants_json:
            return JsonResponse({"message": "Set a new password to continue."}, status=423)
        request.session["temporary_password_error"] = "Set a new password to continue."
        return redirect(request.META.get("HTTP_REFERER") or reverse("dashboard"))
