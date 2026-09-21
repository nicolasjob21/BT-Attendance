"""
Downloadable proof-of-attendance image (PNG) for a single Time In / Time Out
punch — the selfie fills the frame and the evidence is drawn over it: map
thumbnail with the geofence (top-left), Time In/Out badge, time, date, site,
verification line (bottom-left), reference (right edge), company mark.
"""

import hashlib
import io
import math
import os
from datetime import datetime

import requests
from django.conf import settings
from django.utils import timezone
from PIL import Image, ImageDraw, ImageFont

from core.support import media_path

from .geofence import GeofenceService

FONT = settings.BASE_DIR / "static" / "fonts" / "Lato-Regular.ttf"
LOGO = settings.BASE_DIR / "static" / "images" / "brite-logo.png"
ACCENT = (234, 108, 68)
CYAN = (126, 200, 227)


def reference(log) -> str:
    return f"ATT-{log.id}-{'IN' if log.log_type == 'time_in' else 'OUT'}"


def filename(log) -> str:
    return reference(log) + ".png"


def status(log) -> str:
    """Early In / On Time / Late for a time-in; Overtime / Undertime / On Time for a time-out; Regular when flexible."""
    schedule = log.employee.schedule if log.employee_id else None
    day = log.logged_at.date()
    if log.log_type == "time_in":
        if not schedule or not schedule.time_in:
            return "Regular"
        scheduled = datetime.combine(day, schedule.time_in)
        if log.logged_at < scheduled:
            return "Early In"
        expected = scheduled + timezone.timedelta(minutes=int(schedule.grace_minutes or 0))
        return "On Time" if log.logged_at <= expected else "Late"
    if not schedule or not schedule.time_out:
        return "Regular"
    expected_out = datetime.combine(day, schedule.time_out)
    if log.logged_at > expected_out:
        return "Overtime"
    return "Undertime" if log.logged_at < expected_out else "On Time"


def _font(size):
    return ImageFont.truetype(str(FONT), max(8, int(size)))


def _text_w(draw, text, font):
    return draw.textlength(text, font=font)


def _wrap(draw, text, font, max_w):
    lines, cur = [], ""
    for word in text.split():
        trial = word if not cur else f"{cur} {word}"
        if cur and _text_w(draw, trial, font) > max_w:
            lines.append(cur)
            cur = word
        else:
            cur = trial
    if cur:
        lines.append(cur)
    return lines or [""]


def _shadow_text(draw, xy, text, font, fill, anchor="ls", stroke=2):
    draw.text(xy, text, font=font, fill=fill, anchor=anchor, stroke_width=stroke, stroke_fill=(0, 0, 0))


def _map_image(lat, lng, w, h, zoom=16, fence=None):
    """OSM tile mosaic centred on the punch with the geofence circle and a drop-pin. Cached; None on network failure."""
    if not settings.ATTENDANCE.get("softcopy_map", True):
        return None
    cache_dir = media_path("attendance-maps")
    os.makedirs(cache_dir, exist_ok=True)
    key = hashlib.md5(f"{lat},{lng},{zoom},{w}x{h},{fence}".encode()).hexdigest() + ".png"
    cache = cache_dir / key
    if cache.exists():
        try:
            return Image.open(cache).convert("RGBA")
        except Exception:
            pass
    try:
        n = 2 ** zoom
        cx = (lng + 180) / 360 * n * 256
        lat_rad = math.radians(lat)
        cy = (1 - math.log(math.tan(lat_rad) + 1 / math.cos(lat_rad)) / math.pi) / 2 * n * 256
        ox, oy = cx - w / 2, cy - h / 2
        mosaic = Image.new("RGBA", (w, h), (223, 230, 234, 255))
        servers = "abc"
        for tx in range(int(ox // 256), int((ox + w - 1) // 256) + 1):
            for ty in range(int(oy // 256), int((oy + h - 1) // 256) + 1):
                if tx < 0 or ty < 0 or tx >= n or ty >= n:
                    continue
                s = servers[abs(tx + ty) % 3]
                r = requests.get(f"https://{s}.tile.openstreetmap.org/{zoom}/{tx}/{ty}.png", headers={"User-Agent": "BT-Attendance/1.0 (software@brite-tsi.com)"}, timeout=6)
                if r.status_code != 200:
                    return None
                tile = Image.open(io.BytesIO(r.content)).convert("RGBA")
                mosaic.paste(tile, (int(round(tx * 256 - ox)), int(round(ty * 256 - oy))))
        draw = ImageDraw.Draw(mosaic, "RGBA")
        if fence:
            f_lat, f_lng, radius_m = fence
            fx = (f_lng + 180) / 360 * n * 256 - ox
            f_lat_rad = math.radians(f_lat)
            fy = (1 - math.log(math.tan(f_lat_rad) + 1 / math.cos(f_lat_rad)) / math.pi) / 2 * n * 256 - oy
            mpp = 156543.03392 * math.cos(f_lat_rad) / n
            rp = max(4, int(round(radius_m / mpp)))
            draw.ellipse([fx - rp, fy - rp, fx + rp, fy + rp], fill=(74, 155, 181, 56), outline=(74, 155, 181, 255), width=2)
            draw.ellipse([fx - 5, fy - 5, fx + 5, fy + 5], fill=(37, 99, 235, 255), outline=(255, 255, 255, 255), width=2)
        px, py = w // 2, h // 2
        draw.ellipse([px - 8, py - 3, px + 8, py + 3], fill=(0, 0, 0, 64))
        draw.polygon([(px - 8, py - 17), (px + 8, py - 17), (px, py)], fill=ACCENT + (255,))
        draw.ellipse([px - 11, py - 35, px + 11, py - 13], fill=ACCENT + (255,), outline=(255, 255, 255, 255), width=2)
        draw.ellipse([px - 4, py - 28, px + 4, py - 20], fill=(255, 255, 255, 255))
        mosaic.save(cache)
        return mosaic
    except Exception:
        return None


def png(log) -> bytes:
    is_in = log.log_type == "time_in"
    employee = log.employee
    photo_abs = media_path(log.photo_path) if log.photo_path else None
    if photo_abs and photo_abs.is_file():
        img = Image.open(photo_abs).convert("RGBA")
        img.thumbnail((1200, 1600))
        if min(img.size) < 720:
            target_w = 1440 if img.width >= img.height else 1080
            img = img.resize((target_w, int(img.height * target_w / img.width)))
    else:
        img = Image.new("RGBA", (1080, 1440), (28, 28, 30, 255))
        d = ImageDraw.Draw(img)
        d.text((540, 720), "No photo captured", font=_font(40), fill=(148, 163, 184), anchor="mm")

    W, H = img.size
    u = min(W, H) / 1080
    px = lambda v: int(round(v * u))  # noqa: E731

    # Scrims so overlays read on any background.
    overlay = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    od = ImageDraw.Draw(overlay)
    top = int(H * 0.22)
    for i in range(24):
        a = int(255 * 0.45 * (1 - i / 24) ** 1.4)
        od.rectangle([0, int(top * i / 24), W, int(top * (i + 1) / 24)], fill=(0, 0, 0, a))
    scrim_top = int(H * 0.5)
    for i in range(48):
        a = int(255 * 0.78 * (i / 48) ** 1.6)
        od.rectangle([0, scrim_top + int((H - scrim_top) * i / 48), W, scrim_top + int((H - scrim_top) * (i + 1) / 48)], fill=(0, 0, 0, a))
    img = Image.alpha_composite(img, overlay)
    draw = ImageDraw.Draw(img, "RGBA")

    # Map thumbnail, top-left.
    lat = float(log.latitude) if log.latitude is not None else None
    lng = float(log.longitude) if log.longitude is not None else None
    site = log.site
    map_size, mx, my = px(300), px(36), px(36)
    draw.rectangle([mx - px(6), my - px(6), mx + map_size + px(6), my + map_size + px(6)], fill=(255, 255, 255, 255))
    fence = (float(site.latitude), float(site.longitude), int(site.geofence_radius_m)) if site else None
    m = _map_image(lat, lng, map_size, map_size, 16, fence) if lat is not None and lng is not None else None
    if m:
        img.paste(m, (mx, my), m)
        draw = ImageDraw.Draw(img, "RGBA")
        cap_h = px(34)
        draw.rectangle([mx, my + map_size - cap_h, mx + map_size, my + map_size], fill=(0, 0, 0, 158))
        caption = f"{lat:.5f}, {lng:.5f}" + (f"  ±{float(log.gps_accuracy_m):,.0f}m" if log.gps_accuracy_m is not None else "")
        draw.text((mx + map_size / 2, my + map_size - cap_h / 2), caption, font=_font(px(18)), fill=(255, 255, 255), anchor="mm")
    else:
        draw.rectangle([mx, my, mx + map_size, my + map_size], fill=(229, 231, 235, 255))
        draw.text((mx + map_size / 2, my + map_size / 2), "Map unavailable" if lat is not None else "No location", font=_font(px(22)), fill=(107, 114, 128), anchor="mm")

    # Bottom-left block, laid out upwards.
    left = px(48)
    text_w = W - left - px(120)
    verdict = GeofenceService.label(log.location_status) if log.location_status else ("Inside geofence" if log.within_geofence else "Outside geofence")
    ok = log.location_status in (GeofenceService.VERIFIED_LOCATION, GeofenceService.AUTHORIZED_ALTERNATE_LOCATION) or (log.location_status is None and log.within_geofence)
    if log.location_verification_status == "approved":
        verdict, ok = verdict + " · approved by HR", True
    elif log.location_verification_status == "rejected":
        verdict, ok = verdict + " · rejected by HR", False
    elif log.location_verification_status == "pending":
        verdict += " · pending HR review"
    verify_line = verdict + (f" · {site.name}" if site else "") + (f" · {float(log.distance_m):,.0f} m from nearest site" if log.distance_m is not None and not log.within_geofence else "")
    color = (110, 231, 183) if ok else (253, 164, 175)
    y = H - px(56)
    draw.ellipse([left, y - px(20), left + px(18), y - px(2)], fill=color)
    _shadow_text(draw, (left + px(28), y), verify_line, _font(px(26)), color, stroke=max(1, px(2)))

    address = (site.name + (f" · {site.address}" if site.address else "")).strip() if site else "No registered work site matched"
    addr_font = _font(px(30))
    addr_lines = _wrap(draw, address, addr_font, text_w - px(24))
    line_h = px(38)
    y -= px(34) + line_h * len(addr_lines)
    block_bottom = y + line_h * len(addr_lines)
    for i, line in enumerate(addr_lines):
        _shadow_text(draw, (left + px(20), y + line_h * (i + 1) - px(6)), line, addr_font, (255, 255, 255), stroke=max(1, px(2)))
    y -= px(46)
    _shadow_text(draw, (left + px(20), y + px(34)), log.logged_at.strftime("%a, %b %-d, %Y"), _font(px(34)), (255, 255, 255), stroke=max(1, px(2)))
    rule_top = y - px(4)
    draw.rectangle([left, rule_top, left + px(8), block_bottom], fill=ACCENT)

    # Badge: [ Time In ][ 2:24 PM ]
    badge_h = px(76)
    y = rule_top - px(28) - badge_h
    label = "Time In" if is_in else "Time Out"
    f40 = _font(px(40))
    label_w = int(_text_w(draw, label, f40) + px(44))
    draw.rectangle([left, y, left + label_w, y + badge_h], fill=ACCENT)
    draw.text((left + label_w / 2, y + badge_h / 2), label, font=f40, fill=(255, 255, 255), anchor="mm")
    time_s, ampm = log.logged_at.strftime("%-I:%M"), log.logged_at.strftime("%p")
    f56, f22 = _font(px(56)), _font(px(22))
    time_w = int(_text_w(draw, time_s, f56) + _text_w(draw, ampm, f22) + px(56))
    draw.rectangle([left + label_w, y, left + label_w + time_w, y + badge_h], fill=(255, 255, 255))
    draw.text((left + label_w + px(20), y + badge_h / 2 + px(2)), time_s, font=f56, fill=(17, 24, 39), anchor="lm")
    draw.text((left + label_w + time_w - px(20), y + badge_h / 2 - px(10)), ampm, font=f22, fill=(53, 116, 138), anchor="rm")

    # Employee name + number
    y -= px(24)
    name = employee.full_name if employee else "—"
    f64 = _font(px(64))
    _shadow_text(draw, (left, y), name, f64, (255, 255, 255), stroke=max(1, px(2)))
    if employee and employee.employee_no:
        _shadow_text(draw, (left + int(_text_w(draw, name, f64)) + px(18), y - px(8)), employee.employee_no, _font(px(24)), CYAN, stroke=max(1, px(2)))

    # Reference along the right edge (rotated).
    ref = f"{reference(log)}  ·  Brite-Tech Verified  ·  generated {timezone.now():%b %-d, %Y %-I:%M %p}"
    f22r = _font(px(22))
    rw = int(_text_w(draw, ref, f22r)) + px(8)
    strip = Image.new("RGBA", (rw, px(30)), (0, 0, 0, 0))
    ImageDraw.Draw(strip).text((0, px(24)), ref, font=f22r, fill=(229, 231, 235), anchor="ls", stroke_width=max(1, px(2)), stroke_fill=(0, 0, 0))
    strip = strip.rotate(90, expand=True)
    img.paste(strip, (W - px(52) - strip.width, H - px(56) - strip.height), strip)

    # Company mark, top-right.
    if LOGO.is_file():
        logo = Image.open(LOGO).convert("RGBA")
        lw = px(240)
        logo = logo.resize((lw, int(logo.height * lw / logo.width)))
        lx, ly = W - px(36) - logo.width, px(36)
        img.paste(logo, (lx, ly), logo)
        draw = ImageDraw.Draw(img, "RGBA")
        _shadow_text(draw, (W - px(36), ly + logo.height + px(30)), "Proof of attendance", _font(px(20)), (243, 244, 246), anchor="rs", stroke=max(1, px(2)))

    out = io.BytesIO()
    img.convert("RGB").save(out, "PNG")
    return out.getvalue()
