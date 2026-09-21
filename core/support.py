"""Small framework-free helpers shared by every module."""

from decimal import ROUND_HALF_UP, Decimal


def money(amount) -> str:
    """Peso amounts: '₱1,250.00'; '−₱250.00' for a negative."""
    d = Decimal(str(amount or 0)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
    sign = "−" if d < 0 else ""
    return f"{sign}₱{abs(d):,.2f}"


def number(amount, places=2) -> str:
    d = Decimal(str(amount or 0)).quantize(Decimal(1).scaleb(-places), rounding=ROUND_HALF_UP)
    return f"{d:,.{places}f}"


def dec(value, places=2) -> Decimal:
    return Decimal(str(value or 0)).quantize(Decimal(1).scaleb(-places), rounding=ROUND_HALF_UP)


def form_values(old, instance, fields, aliases=None):
    """Re-displayed input merged over the instance's current values — Laravel's old('key', $model->key).

    Returns a plain dict so templates can read `v.first_name` safely even when there is no instance yet.
    `aliases` maps a form field to a dotted attribute path on the instance (e.g. username -> user.username).
    """
    import datetime as _dt

    old = old or {}
    aliases = aliases or {}
    values = {}
    for field in fields:
        if field in old:
            values[field] = old[field]
            continue
        cur = instance
        for part in aliases.get(field, field).split("."):
            cur = getattr(cur, part, None) if cur is not None else None
        if isinstance(cur, _dt.datetime):
            cur = cur.strftime("%Y-%m-%dT%H:%M")
        elif isinstance(cur, _dt.date):
            cur = cur.isoformat()
        values[field] = "" if cur is None else cur
    return values


def media_path(*parts):
    """Absolute path under MEDIA_ROOT (public uploads: selfies, profile photos, map tiles)."""
    from pathlib import Path

    from django.conf import settings

    return Path(settings.MEDIA_ROOT).joinpath(*parts)


def private_media_path(*parts):
    """Absolute path under PRIVATE_MEDIA_ROOT (checkpoint photos, served through a permission-checked view)."""
    from pathlib import Path

    from django.conf import settings

    return Path(settings.PRIVATE_MEDIA_ROOT).joinpath(*parts)
