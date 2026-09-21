from django.urls import path

from . import views

urlpatterns = [
    path("attendance", views.create, name="attendance.create"),
    path("attendance/store", views.store, name="attendance.store"),
    path("attendance/logs", views.index, name="attendance.index"),
    path("attendance/monitor", views.monitor, name="attendance.monitor"),
    path("attendance/timesheet/<int:pk>", views.timesheet, name="attendance.timesheet"),
    path("attendance/<int:pk>/softcopy/<str:kind>", views.soft_copy, name="attendance.softcopy"),
    path("attendance/<int:pk>/verify", views.verify, name="attendance.verify"),
    path("attendance/<int:pk>/verify-location", views.verify_location, name="attendance.verify-location"),
]
