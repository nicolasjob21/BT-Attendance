"""
HTMX support. One view serves both the full page and the fragment: branch with
`is_htmx(request)` and answer the fragment with `render_partial(...)`. The
library itself is a single vendored script (static/vendor/htmx.min.js); the
CSRF header and the flash region are wired once in layouts/app.html.
"""

from django.http import HttpResponse
from django.template.loader import render_to_string


def is_htmx(request):
    """An htmx request that wants a fragment. A history restore (back button) wants the whole page."""
    return request.headers.get("HX-Request") == "true" and request.headers.get("HX-History-Restore-Request") != "true"


def render_partial(request, template, context=None, *, oob=(), status=200, reswap=None, retarget=None):
    """
    Render a fragment for an htmx swap. `oob` lists extra (template, context) fragments whose
    root elements carry their own `hx-swap-oob` targets; pending flash messages always ride
    along as `#flash` so an action shows the same banner a full page load would.
    """
    context = {**(context or {}), "hx": True}
    html = render_to_string(template, context, request)
    for tpl, ctx in oob:
        html += render_to_string(tpl, {**ctx, "hx": True, "oob": True}, request)
    html += render_to_string("ui/flash.html", {"oob": True}, request)
    response = HttpResponse(html, status=status)
    if reswap:
        response["HX-Reswap"] = reswap
    if retarget:
        response["HX-Retarget"] = retarget
    return response
