from django.conf import settings
from django.db import models

from employees.models import Employee, Site


class AttendanceLog(models.Model):
    TIME_IN = "time_in"
    TIME_OUT = "time_out"

    employee = models.ForeignKey(Employee, on_delete=models.CASCADE, related_name="attendance_logs")
    site = models.ForeignKey(Site, null=True, blank=True, on_delete=models.SET_NULL, related_name="attendance_logs")
    assigned_site = models.ForeignKey(Site, null=True, blank=True, on_delete=models.SET_NULL, related_name="+")
    log_type = models.CharField(max_length=10)
    logged_at = models.DateTimeField()
    latitude = models.DecimalField(max_digits=10, decimal_places=7, null=True, blank=True)
    longitude = models.DecimalField(max_digits=10, decimal_places=7, null=True, blank=True)
    distance_m = models.DecimalField(max_digits=10, decimal_places=2, null=True, blank=True)
    gps_accuracy_m = models.DecimalField(max_digits=10, decimal_places=2, null=True, blank=True)
    within_geofence = models.BooleanField(default=False)
    location_status = models.CharField(max_length=40, null=True, blank=True)
    location_validation_message = models.CharField(max_length=255, null=True, blank=True)
    location_reason = models.TextField(null=True, blank=True)
    location_verification_status = models.CharField(max_length=20, null=True, blank=True)  # pending | approved | rejected
    location_remarks = models.TextField(null=True, blank=True)
    location_verified_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="location_verified_by")
    location_verified_at = models.DateTimeField(null=True, blank=True)
    photo_path = models.CharField(max_length=2048, null=True, blank=True)
    synced_offline = models.BooleanField(default=False)
    ot_verification_status = models.CharField(max_length=20, null=True, blank=True)
    ot_remarks = models.TextField(null=True, blank=True)
    ot_verified_by = models.ForeignKey(settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+", db_column="ot_verified_by")
    ot_verified_at = models.DateTimeField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True, null=True)
    updated_at = models.DateTimeField(auto_now=True, null=True)

    class Meta:
        db_table = "attendance_logs"
        ordering = ["logged_at"]

    @property
    def is_time_in(self):
        return self.log_type == self.TIME_IN

    def has_location_exception(self):
        from .geofence import GeofenceService

        return self.location_status in (GeofenceService.OUTSIDE_AUTHORIZED_AREA, GeofenceService.GPS_UNAVAILABLE, GeofenceService.LOW_ACCURACY)

    @property
    def photo_url(self):
        return f"{settings.MEDIA_URL}{self.photo_path}" if self.photo_path else None
