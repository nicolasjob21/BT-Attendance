"""
Design-system helpers for templates (loaded as a builtin, no {% load %} needed).
"""

import json
import re
from datetime import date, datetime
from decimal import Decimal

from django import template
from django.core.serializers.json import DjangoJSONEncoder
from django.utils.safestring import mark_safe

from attendance.sessions import label as hours_label
from core.support import money as money_fmt, number as number_fmt

register = template.Library()


@register.filter(is_safe=True)
def js(value):
    """Laravel's @js: a JavaScript expression that is safe inside a double-quoted HTML attribute or a <script>.

    Objects and lists come out as JSON.parse('…') with every quote, angle bracket and ampersand
    escaped, so Alpine's x-data="fn({{ rows|js }})" works without breaking the attribute.
    """
    if value is None:
        return mark_safe("null")
    if isinstance(value, bool):
        return mark_safe("true" if value else "false")
    if isinstance(value, (int, float)):
        return mark_safe(json.dumps(value))
    if isinstance(value, Decimal):
        return mark_safe(str(value))
    if isinstance(value, str):
        text = value.replace("\\", "\\\\").replace("'", "\\'").replace("\n", "\\n").replace("\r", "")
        text = text.replace("<", "\\u003c").replace(">", "\\u003e").replace("&", "\\u0026").replace('"', "\\u0022")
        return mark_safe(f"'{text}'")
    text = json.dumps(value, cls=DjangoJSONEncoder)
    text = text.replace("\\", "\\\\").replace("'", "\\'")
    text = text.replace("<", "\\u003c").replace(">", "\\u003e").replace("&", "\\u0026").replace('"', "\\u0022")
    return mark_safe(f"JSON.parse('{text}')")


# ---- filters ----

@register.filter
def money(v):
    return money_fmt(v)


@register.filter
def n2(v):
    return number_fmt(v, 2)


@register.filter
def n0(v):
    return number_fmt(v, 0)


@register.filter
def n1(v):
    return number_fmt(v, 1)


@register.filter
def hlabel(minutes):
    return hours_label(int(minutes or 0))


@register.filter
def get(d, key):
    try:
        return d.get(key) if hasattr(d, "get") else d[key]
    except Exception:
        return None


@register.filter
def pct(part, whole):
    try:
        return 0 if not whole else round(float(part) / float(whole) * 100, 1)
    except Exception:
        return 0


@register.filter
def pct0(part, whole):
    try:
        return 0 if not whole else int(round(float(part) / float(whole) * 100))
    except Exception:
        return 0


@register.filter
def sub(a, b):
    try:
        return float(a) - float(b)
    except Exception:
        return 0


@register.filter
def mul(a, b):
    try:
        return float(a) * float(b)
    except Exception:
        return 0


@register.filter
def div(a, b):
    try:
        return float(a) / float(b) if float(b) else 0
    except Exception:
        return 0


@register.filter
def humanize_status(s):
    return (s or "").replace("_", " ")


@register.filter
def t12(value):
    """time or datetime → '8:30 AM'."""
    if not value:
        return ""
    return value.strftime("%-I:%M %p")


@register.filter
def dmd(value):
    """'Sep 19'"""
    return value.strftime("%b %-d") if value else ""


@register.filter
def dmdy(value):
    """'Sep 19, 2026'"""
    return value.strftime("%b %-d, %Y") if value else ""


@register.filter
def dfull(value):
    """'Sat, Sep 19, 2026'"""
    return value.strftime("%a, %b %-d, %Y") if value else ""


@register.filter
def dt12(value):
    """'Sep 19, 6:32 PM'"""
    return value.strftime("%b %-d, %-I:%M %p") if value else ""


@register.filter
def diff_for_humans(value):
    from django.utils import timezone
    from django.utils.timesince import timesince

    if not value:
        return ""
    now = timezone.now()
    if value > now:
        return "just now"
    s = timesince(value, now).split(",")[0]
    return "just now" if s.startswith("0") else f"{s} ago"


@register.filter
def ends_with(value, suffix):
    return str(value).endswith(suffix)


@register.filter
def split_words(value):
    return (value or "").split()


@register.filter
def field_errors(errors, name):
    """errors is a dict {field: [messages]} — first message for a field."""
    if not errors:
        return ""
    msgs = errors.get(name) if hasattr(errors, "get") else None
    return msgs[0] if msgs else ""


# ---- simple tags ----

@register.simple_tag(takes_context=True)
def active(context, *patterns):
    """True when the current URL name matches any pattern ('leave.*', 'dashboard')."""
    match = context.get("request").resolver_match if context.get("request") else None
    name = match.url_name if match else ""
    for p in patterns:
        if p.endswith(".*"):
            if name == p[:-2] or name.startswith(p[:-1]):
                return True
        elif name == p:
            return True
    return False


@register.simple_tag
def next_day(frm, to):
    """"12:05 AM⁺¹": marks a time on a later calendar day than the row's day."""
    if not frm or not to:
        return ""
    d0 = frm if isinstance(frm, date) and not isinstance(frm, datetime) else frm.date()
    d1 = to.date() if isinstance(to, datetime) else to
    days = (d1 - d0).days
    if days <= 0:
        return ""
    return mark_safe(f'<sup class="ml-0.5 text-[10px] font-semibold text-brand-600 dark:text-brand-300" title="Next day — {to:%a, %b %-d}">+{days}</sup>')


STATUS_MAP = {
    "pending": "badge-warn", "approved": "badge-success", "denied": "badge-danger", "active": "badge-success",
    "inactive": "badge-neutral", "on_leave": "badge-warn", "open": "badge-info", "processing": "badge-warn",
    "closed": "badge-neutral", "completed": "badge-info", "ended": "badge-neutral", "cancelled": "badge-danger",
    "paid": "badge-success",
}


@register.simple_tag
def status_badge(status, extra=""):
    cls = STATUS_MAP.get(status, "badge-neutral")
    return mark_safe(f'<span class="badge {cls} {extra}">{(status or "").replace("_", " ")}</span>')


@register.simple_tag
def campaign_badge(c, extra=""):
    from checkpoints.models import CheckpointCampaign as C

    m = {C.DRAFT: "badge-neutral", C.ACTIVE: "badge-success", C.PAUSED: "badge-warn", C.EXPIRED: "badge-danger", C.COMPLETED: "badge-info", C.CANCELLED: "badge-muted"}
    cls = "badge-info" if c.is_scheduled else m.get(c.status, "badge-neutral")
    dot = '<i class="dot animate-pulse"></i>' if c.status == C.ACTIVE else ""
    return mark_safe(f'<span class="badge {cls} {extra}">{dot}{c.status_label}</span>')


@register.simple_tag
def checkpoint_badge(cp, extra=""):
    from checkpoints.models import Checkpoint as K

    m = {K.PENDING: "badge-neutral", K.NOTIFIED: "badge-info", K.RESPONDED: "badge-success", K.APPROVED_EXCEPTION: "badge-success",
         K.MISSED: "badge-danger", K.OUTSIDE_GEOFENCE: "badge-danger", K.REJECTED_EXCEPTION: "badge-danger", K.GPS_UNAVAILABLE: "badge-warn",
         K.CAMERA_PERMISSION_DENIED: "badge-warn", K.SUBMISSION_FAILED: "badge-warn", K.PENDING_REVIEW: "badge-warn"}
    label = cp.verification_label if (cp.is_completed and cp.verification_result) else cp.status_label
    return mark_safe(f'<span class="badge {m.get(cp.status, "badge-neutral")} {extra}">{label}</span>')


@register.simple_tag
def location_badge(status, verification=None, compact=False, extra=""):
    from attendance.geofence import GeofenceService as G

    m = {G.VERIFIED_LOCATION: "badge-success", G.AUTHORIZED_ALTERNATE_LOCATION: "badge-info", G.OUTSIDE_AUTHORIZED_AREA: "badge-danger", G.LOW_ACCURACY: "badge-warn", G.GPS_UNAVAILABLE: "badge-neutral"}
    short = {G.VERIFIED_LOCATION: "Verified", G.AUTHORIZED_ALTERNATE_LOCATION: "Alt. location", G.OUTSIDE_AUTHORIZED_AREA: "Outside area", G.LOW_ACCURACY: "Low accuracy", G.GPS_UNAVAILABLE: "No GPS"}
    cls = m.get(status, "badge-muted")
    label = short.get(status, "Unchecked") if compact else G.label(status)
    if verification == "approved":
        cls, label = "badge-success", label + " · approved"
    elif verification == "rejected":
        cls, label = "badge-danger", label + " · rejected"
    elif verification == "pending":
        cls, label = "badge-warn", label + " · pending"
    return mark_safe(f'<span class="badge {cls} {extra}" title="{G.label(status)}">{label}</span>')


# ---- inclusion tags (components) ----

@register.inclusion_tag("ui/stat.html")
def stat(label, value, hint=None, tone="neutral", href=None, extra=""):
    tones = {
        "neutral": "text-gray-900 dark:text-slate-100", "brand": "text-brand-700 dark:text-brand-300",
        "success": "text-emerald-600 dark:text-emerald-400", "warn": "text-amber-600 dark:text-amber-400",
        "danger": "text-accent-600 dark:text-accent-400", "muted": "text-gray-400 dark:text-slate-500",
    }
    return {"label": label, "value": value, "hint": hint, "tone_class": tones.get(tone, tones["neutral"]), "href": href, "extra": extra}


@register.inclusion_tag("ui/empty_state.html")
def empty_state(title, hint=None, href=None, action=None, icon="inbox", dispatch=None):
    """`dispatch` names a window event the action button fires instead of following `href` (for htmx-driven lists)."""
    icons = {
        "inbox": "M3 13h4l2 3h6l2-3h4M5 6h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z",
        "clock": "M12 8v4l2.5 2M12 21a9 9 0 110-18 9 9 0 010 18z",
        "calendar": "M4 9h16M8 3v4M16 3v4M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z",
        "cash": "M3 8h18v10H3zM12 13a2 2 0 100-4 2 2 0 000 4zM6 8V6h12v2",
        "users": "M16 11a4 4 0 10-8 0 4 4 0 008 0zM4 21a8 8 0 0116 0",
        "map": "M12 21s-6-5.5-6-10a6 6 0 1112 0c0 4.5-6 10-6 10zM12 11a1.5 1.5 0 100-3 1.5 1.5 0 000 3z",
        "shield": "M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3zM9 12l2 2 4-4",
    }
    return {"title": title, "hint": hint, "href": href, "action": action, "dispatch": dispatch, "path": icons.get(icon, icons["inbox"])}


NAV_ICONS = {
    "grid": '<path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/>',
    "clock": '<circle cx="12" cy="12" r="8"/><path stroke-linecap="round" d="M12 8v4l2.5 2"/>',
    "list": '<path stroke-linecap="round" d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
    "calendar": '<rect x="4" y="5" width="16" height="16" rx="2"/><path stroke-linecap="round" d="M4 9h16M8 3v4M16 3v4"/>',
    "plus-clock": '<circle cx="12" cy="12" r="8"/><path stroke-linecap="round" d="M12 9v6M9 12h6"/>',
    "users": '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4h-1m-4 5H2v-1a4 4 0 014-4h4a4 4 0 014 4v1zm-3-11a3 3 0 11-6 0 3 3 0 016 0zm7 1a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/>',
    "cash": '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
    "map-pin": '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6-5.2-6-10a6 6 0 1112 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/>',
    "user-cog": '<circle cx="10" cy="8" r="3.5"/><path stroke-linecap="round" d="M3 20v-1a5 5 0 015-5h3"/><circle cx="17.5" cy="16.5" r="2.5"/><path stroke-linecap="round" d="M17.5 12.5v1.5M17.5 19v1.5M13.5 16.5H15M20 16.5h1.5M14.7 13.7l1 1M19.3 19.3l1 1M14.7 19.3l1-1M19.3 13.7l1 1"/>',
    "shield-check": '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/>',
}


@register.inclusion_tag("ui/nav_item.html", takes_context=True)
def nav_item(context, href, label, icon="grid", pattern=None, badge=None):
    is_active = active(context, pattern or "")
    return {"href": href, "label": label, "icon_svg": mark_safe(NAV_ICONS.get(icon, NAV_ICONS["grid"])), "active": is_active, "badge": badge}


@register.inclusion_tag("ui/confirm_action.html")
def confirm_action(action, title, message, button="Confirm", tone="brand", reason=False, size="sm", variant="outline", extra="", reason_label="Reason (optional)"):
    btn = {"emerald": "btn-success", "amber": "btn-outline-warn", "rose": "btn-danger"}.get(tone, "btn-brand")
    trigger = {"emerald": "btn-outline-success", "amber": "btn-outline-warn", "rose": "btn-outline-danger"}.get(tone, "btn-outline-brand")
    size_class = "btn-md" if size == "md" else "btn-xs"
    trigger_classes = f"btn-app {size_class} btn-brand" if variant == "primary" else f"btn-app {size_class} {trigger}"
    return {"action": action, "title": title, "message": message, "button": button, "btn": btn, "trigger_classes": trigger_classes + " " + extra,
            "reason": reason, "reason_label": reason_label, "wrap": "w-full sm:w-auto" if "w-full" in extra else ""}


@register.inclusion_tag("ui/back_button.html")
def back_button(href, label="Back"):
    return {"href": href, "label": label}


@register.inclusion_tag("ui/form_aside.html")
def form_aside(facts=None, title="Good to know", bullets=None):
    return {"facts": facts or [], "title": title, "bullets": bullets or []}


@register.filter
def split_csv(value):
    return [v for v in str(value).split(",") if v]


@register.simple_tag(takes_context=True)
def query_without_page(context):
    """Current query string minus `page`, ready to prefix a page link."""
    request = context.get("request")
    if not request:
        return ""
    q = request.GET.copy()
    q.pop("page", None)
    encoded = q.urlencode()
    return encoded + "&" if encoded else ""


@register.simple_tag
def gps_badge(cp):
    if cp.latitude is None and cp.last_attempt_at is None:
        return mark_safe('<span class="text-gray-400 dark:text-slate-500">—</span>')
    if cp.latitude is None:
        g = "gps_unavailable"
    elif cp.last_attempt_result == "low_gps_accuracy":
        g = "low_accuracy"
    elif cp.within_geofence:
        g = "verified_location"
    elif cp.matched_site_id:
        g = "authorized_alternate_location"
    else:
        g = "outside_authorized_area"
    return location_badge(g, None, True)


@register.filter
def iso(dt):
    """Naive local datetime → ISO string with the Manila offset (for JS Date parsing)."""
    from django.utils import timezone as tz

    if not dt:
        return ""
    return tz.make_aware(dt).isoformat() if tz.is_naive(dt) else dt.isoformat()
