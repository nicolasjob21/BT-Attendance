from django.urls import path

from . import views

urlpatterns = [
    path("leave", views.leave_index, name="leave.index"),
    path("leave/create", views.leave_create, name="leave.create"),
    path("leave/store", views.leave_store, name="leave.store"),
    path("leave/early", views.early_create, name="leave.early.create"),
    path("leave/early/store", views.early_store, name="leave.early.store"),
    path("leave/<int:pk>/approve", views.leave_approve, name="leave.approve"),
    path("leave/<int:pk>/deny", views.leave_deny, name="leave.deny"),
    path("overtime", views.overtime_index, name="overtime.index"),
    path("overtime/create", views.overtime_create, name="overtime.create"),
    path("overtime/store", views.overtime_store, name="overtime.store"),
    path("overtime/<int:pk>/approve", views.overtime_approve, name="overtime.approve"),
    path("overtime/<int:pk>/deny", views.overtime_deny, name="overtime.deny"),
]
