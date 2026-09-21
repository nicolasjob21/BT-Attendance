"""
Turn raw clock events into paired work sessions and split worked minutes into
regular / overtime. Shared by the attendance pages, overtime and payroll so
"what did this person actually work that day" is defined in one place.

A session is {"in": AttendanceLog, "out": AttendanceLog | None}; a None out
means the session is still open.
"""

from django.conf import settings


def pair(logs):
    sessions, open_in = [], None
    for log in logs:
        if log.log_type == "time_in":
            if open_in is not None:  # clocked in twice without a clock-out
                sessions.append({"in": open_in, "out": None})
            open_in = log
        elif open_in is not None:  # time_out closing an open session
            sessions.append({"in": open_in, "out": log})
            open_in = None
        # a time_out with no open in belongs to a prior day's session — skip it
    if open_in is not None:
        sessions.append({"in": open_in, "out": None})
    return sessions


def starting_on(sessions, day):
    """Only sessions whose time-in falls on `day` (a date) — overnight shifts stay on the day they started."""
    return [s for s in sessions if s["in"].logged_at.date() == day]


def worked_minutes(sessions) -> int:
    total = 0
    for s in sessions:
        if s["out"] is not None:
            total += int(round((s["out"].logged_at - s["in"].logged_at).total_seconds() / 60))
    return total


def has_open(sessions) -> bool:
    return any(s["out"] is None for s in sessions)


def closing_out(sessions):
    """The day's closing time-out log (last closed session's out), or None."""
    out = None
    for s in sessions:
        if s["out"] is not None:
            out = s["out"]
    return out


# ---- WorkHours ----

def standard_minutes() -> int:
    return int(round(settings.PAYROLL["standard_workday_hours"] * 60))


def verification_threshold_minutes() -> int:
    return int(round(settings.PAYROLL["ot_verification_hours"] * 60))


def needs_verification(minutes: int) -> bool:
    """A 13h+ day must be verified by HR before its overtime is trusted (also catches a forgotten clock-out)."""
    t = verification_threshold_minutes()
    return t > 0 and minutes >= t


def split(minutes: int, rest_day: bool = False) -> dict:
    """First 8h regular on a weekday; every minute is overtime on a rest day."""
    minutes = max(0, minutes)
    if rest_day:
        return {"regular": 0, "overtime": minutes}
    std = standard_minutes()
    return {"regular": min(minutes, std), "overtime": max(0, minutes - std)}


def label(minutes: int) -> str:
    minutes = max(0, int(minutes))
    return f"{minutes // 60}h {minutes % 60}m"


def next_day_offset(from_day, to_dt) -> int:
    """Calendar days between the day a session started and a punch — the ⁺¹ marker."""
    return (to_dt.date() - from_day).days
