"""
Server-side location validation for a clock event. The GPS fix is compared
against EVERY active attendance location (office, active project sites,
temporary sites), not just the assigned project. The client-side map check is
a preview; this is the source of truth.
"""

import math
from dataclasses import dataclass

from django.conf import settings
from django.utils import timezone

from employees.models import Site

EARTH_RADIUS_M = 6371000.0


def distance_meters(lat1, lng1, lat2, lng2) -> float:
    """Great-circle (haversine) distance in metres."""
    d_lat = math.radians(float(lat2) - float(lat1))
    d_lng = math.radians(float(lng2) - float(lng1))
    a = math.sin(d_lat / 2) ** 2 + math.cos(math.radians(float(lat1))) * math.cos(math.radians(float(lat2))) * math.sin(d_lng / 2) ** 2
    return 2 * EARTH_RADIUS_M * math.asin(min(1.0, math.sqrt(a)))


@dataclass
class GeofenceResult:
    status: str
    message: str
    site: Site | None = None  # location the punch is credited to (inside a fence)
    nearest: Site | None = None
    distance: float | None = None
    within: bool = False
    assigned_site: Site | None = None
    accuracy: float | None = None

    def is_exception(self) -> bool:
        return self.status in (GeofenceService.OUTSIDE_AUTHORIZED_AREA, GeofenceService.GPS_UNAVAILABLE, GeofenceService.LOW_ACCURACY)

    def to_log_attributes(self) -> dict:
        return {
            "site": self.site,
            "assigned_site": self.assigned_site,
            "distance_m": round(self.distance, 2) if self.distance is not None else None,
            "gps_accuracy_m": round(self.accuracy, 2) if self.accuracy is not None else None,
            "within_geofence": self.within,
            "location_status": self.status,
            "location_validation_message": self.message,
        }


class GeofenceService:
    VERIFIED_LOCATION = "verified_location"
    AUTHORIZED_ALTERNATE_LOCATION = "authorized_alternate_location"
    OUTSIDE_AUTHORIZED_AREA = "outside_authorized_area"
    GPS_UNAVAILABLE = "gps_unavailable"
    LOW_ACCURACY = "low_accuracy"
    MODES = ("warning", "approval", "strict")
    LABELS = {
        VERIFIED_LOCATION: "Verified location",
        AUTHORIZED_ALTERNATE_LOCATION: "Authorized alternate location",
        OUTSIDE_AUTHORIZED_AREA: "Outside authorized area",
        GPS_UNAVAILABLE: "GPS unavailable",
        LOW_ACCURACY: "Low GPS accuracy",
    }

    def mode(self) -> str:
        m = settings.ATTENDANCE.get("geofence_mode", "warning")
        return m if m in self.MODES else "warning"

    def min_accuracy_meters(self) -> int:
        return max(1, int(settings.ATTENDANCE.get("min_gps_accuracy_m", 100)))

    def check(self, site: Site, lat: float, lng: float) -> dict:
        d = distance_meters(site.latitude, site.longitude, lat, lng)
        return {"distance": round(d, 2), "within": d <= float(site.geofence_radius_m)}

    def evaluate(self, employee, lat, lng, accuracy=None, sites=None, at=None) -> GeofenceResult:
        at = at or timezone.now()
        sites = list(sites) if sites is not None else list(Site.objects.active_on(at.date()))
        assignment = employee.project_assignments.active_on(at.date()).select_related("site").order_by("-start_date", "-id").first()
        assigned = assignment.site if assignment else None

        if lat is None or lng is None:
            return GeofenceResult(
                status=self.GPS_UNAVAILABLE,
                message="Location was not available on the device, so this punch could not be verified against any work site.",
                assigned_site=assigned, accuracy=accuracy,
            )

        nearest, nearest_distance = None, None
        for site in sites:
            d = distance_meters(lat, lng, site.latitude, site.longitude)
            if nearest_distance is None or d < nearest_distance:
                nearest, nearest_distance = site, d
        within = nearest is not None and nearest_distance <= float(nearest.geofence_radius_m)

        if accuracy is not None and accuracy > self.min_accuracy_meters():
            tail = f" (nearest: {nearest.name}, {nearest_distance:,.0f} m away)." if nearest else "."
            return GeofenceResult(
                status=self.LOW_ACCURACY,
                message=f"GPS accuracy was ±{accuracy:,.0f} m, too poor to reliably confirm the work site{tail}",
                site=nearest if within else None, nearest=nearest, distance=nearest_distance, within=within,
                assigned_site=assigned, accuracy=accuracy,
            )

        if not within:
            how_far = f"about {nearest_distance:,.0f} m from {nearest.name}" if nearest else "outside every registered work site"
            return GeofenceResult(
                status=self.OUTSIDE_AUTHORIZED_AREA,
                message=f"Outside the authorized attendance area — {how_far}.",
                nearest=nearest, distance=nearest_distance, within=False, assigned_site=assigned, accuracy=accuracy,
            )

        alternate = assigned is not None and assigned.id != nearest.id
        return GeofenceResult(
            status=self.AUTHORIZED_ALTERNATE_LOCATION if alternate else self.VERIFIED_LOCATION,
            message=(
                f"At {nearest.name}, which is an authorized location, but not the assigned project ({assigned.name})."
                if alternate else f"Inside the {nearest.name} geofence."
            ),
            site=nearest, nearest=nearest, distance=nearest_distance, within=True, assigned_site=assigned, accuracy=accuracy,
        )

    def blocks(self, result: GeofenceResult) -> bool:
        """Only strict mode refuses to record an exception; warning/approval keep the record."""
        return self.mode() == "strict" and result.is_exception()

    def initial_verification_status(self, result: GeofenceResult):
        if not result.is_exception():
            return None
        return "pending" if self.mode() == "approval" else None

    @classmethod
    def label(cls, status) -> str:
        return cls.LABELS.get(status, "Not checked")
