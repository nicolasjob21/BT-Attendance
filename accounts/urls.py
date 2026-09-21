from django.contrib.auth import views as auth_views
from django.urls import path

from . import views

urlpatterns = [
    path("login", views.LoginView.as_view(), name="login"),
    path("logout", views.logout_view, name="logout"),
    path("forgot-password", auth_views.PasswordResetView.as_view(template_name="auth/password_reset_form.html", success_url="/forgot-password/sent", email_template_name="auth/password_reset_email.txt"), name="password.request"),
    path("forgot-password/sent", auth_views.PasswordResetDoneView.as_view(template_name="auth/password_reset_done.html"), name="password.sent"),
    path("reset-password/<uidb64>/<token>", auth_views.PasswordResetConfirmView.as_view(template_name="auth/password_reset_confirm.html", success_url="/reset-password/done"), name="password.reset"),
    path("reset-password/done", auth_views.PasswordResetCompleteView.as_view(template_name="auth/password_reset_complete.html"), name="password.done"),
    path("password", views.password_update, name="password.update"),
    path("password/temporary", views.password_temporary, name="password.temporary"),
    path("profile", views.profile_edit, name="profile.edit"),
    path("profile/update", views.profile_update, name="profile.update"),
    path("profile/delete", views.profile_destroy, name="profile.destroy"),
    path("users", views.users_index, name="users.index"),
    path("users/presence", views.users_presence, name="users.presence"),
    path("users/<int:pk>/edit", views.users_edit, name="users.edit"),
    path("users/<int:pk>", views.users_update, name="users.update"),
    path("users/<int:pk>/disabled", views.users_toggle_disabled, name="users.disabled"),
    path("users/<int:pk>/delete", views.users_destroy, name="users.destroy"),
    path("users/<int:pk>/restore", views.users_restore, name="users.restore"),
]
