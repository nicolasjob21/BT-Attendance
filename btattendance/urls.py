from django.conf import settings
from django.conf.urls.static import static
from django.contrib import admin
from django.urls import include, path

from core import views as core_views

urlpatterns = [
    path("", core_views.home, name="home"),
    path("dashboard", core_views.dashboard, name="dashboard"),
    path("notifications/<int:pk>", core_views.notification_open, name="notifications.open"),
    path("notifications/read-all", core_views.notifications_read_all, name="notifications.read-all"),
    path("", include("accounts.urls")),
    path("", include("attendance.urls")),
    path("", include("leaveot.urls")),
    path("", include("employees.urls")),
    path("", include("payroll.urls")),
    path("", include("checkpoints.urls")),
    path("admin/", admin.site.urls),
]
if settings.DEBUG:
    urlpatterns += static(settings.MEDIA_URL, document_root=settings.MEDIA_ROOT)
