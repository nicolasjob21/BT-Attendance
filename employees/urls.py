from django.urls import path

from . import views

urlpatterns = [
    path("employees", views.index, name="employees.index"),
    path("employees/create", views.create, name="employees.create"),
    path("employees/store", views.store, name="employees.store"),
    path("employees/export", views.export, name="employees.export"),
    path("employees/import", views.import_form, name="employees.import"),
    path("employees/import/store", views.import_store, name="employees.import.store"),
    path("employees/import/template", views.import_template, name="employees.import.template"),
    path("employees/<int:pk>/edit", views.edit, name="employees.edit"),
    path("employees/<int:pk>", views.update, name="employees.update"),
    path("employees/<int:pk>/status", views.toggle_status, name="employees.status"),
    path("employees/<int:pk>/assignments", views.assignment_store, name="employees.assignments.store"),
    path("employees/<int:pk>/assignments/<int:assignment_id>/end", views.assignment_end, name="employees.assignments.end"),
    path("settings/sites", views.sites_index, name="sites.index"),
    path("settings/sites/create", views.sites_create, name="sites.create"),
    path("settings/sites/store", views.sites_store, name="sites.store"),
    path("settings/sites/resolve-link", views.sites_resolve_link, name="sites.resolve-link"),
    path("settings/sites/<int:pk>/edit", views.sites_edit, name="sites.edit"),
    path("settings/sites/<int:pk>", views.sites_update, name="sites.update"),
    path("settings/sites/<int:pk>/status", views.sites_status, name="sites.status"),
]
