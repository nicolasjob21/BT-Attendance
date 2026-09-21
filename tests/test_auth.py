from django.urls import reverse

from accounts.models import User

from .base import AppTestCase


class LoginTest(AppTestCase):
    def test_username_and_password_sign_in(self):
        r = self.client.post(reverse("login"), {"username": "brite-tech", "password": "password"})
        self.assertRedirects(r, reverse("dashboard"), fetch_redirect_response=False)
        self.tech.refresh_from_db()
        self.assertIsNotNone(self.tech.last_login_at)

    def test_wrong_password_is_rejected(self):
        r = self.client.post(reverse("login"), {"username": "brite-tech", "password": "nope"})
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "These credentials do not match")

    def test_disabled_account_cannot_sign_in(self):
        self.tech.disabled_at = "2026-01-01 00:00:00"
        self.tech.save()
        r = self.client.post(reverse("login"), {"username": "brite-tech", "password": "password"})
        self.assertEqual(r.status_code, 200)
        self.assertContains(r, "disabled")

    def test_temporary_password_forces_a_change_before_anything_else(self):
        self.tech.set_temporary_password("temp12345")
        self.tech.save()
        self.client.post(reverse("login"), {"username": "brite-tech", "password": "temp12345"})
        r = self.client.get(reverse("leave.index"))
        self.assertContains(r, "temporary password")  # modal on top of every page
        r = self.client.post(reverse("leave.store"), {"leave_type_id": 1, "day_portion": "full", "date_from": "2026-10-05", "date_to": "2026-10-05"})
        self.assertEqual(r.status_code, 302)
        self.assertEqual(self.client.session.get("temporary_password_error"), "Set a new password to continue.")
        r = self.client.post(reverse("password.temporary"), {"current_password": "temp12345", "password": "brandnew123", "password_confirmation": "brandnew123"})
        self.assertEqual(r.status_code, 302)
        self.tech.refresh_from_db()
        self.assertFalse(self.tech.must_change_password)
        self.assertEqual(self.client.get(reverse("leave.index")).status_code, 200)

    def test_logout(self):
        self.login(self.tech)
        r = self.client.post(reverse("logout"))
        self.assertRedirects(r, reverse("login"), fetch_redirect_response=False)
        self.assertEqual(self.client.get(reverse("dashboard")).status_code, 302)


class ProfileTest(AppTestCase):
    def test_update_own_name_and_username(self):
        self.login(self.tech)
        r = self.client.post(reverse("profile.update"), {"name": "Tech Nical", "username": "brite-nical", "email": "tech@brite-tsi.com"})
        self.assertRedirects(r, reverse("profile.edit"), fetch_redirect_response=False)
        self.tech.refresh_from_db()
        self.assertEqual((self.tech.name, self.tech.username), ("Tech Nical", "brite-nical"))

    def test_username_must_follow_company_pattern(self):
        self.login(self.tech)
        self.client.post(reverse("profile.update"), {"name": "T", "username": "Bad Name!", "email": "tech@brite-tsi.com"})
        self.tech.refresh_from_db()
        self.assertEqual(self.tech.username, "brite-tech")

    def test_change_password(self):
        self.login(self.tech)
        self.client.post(reverse("password.update"), {"current_password": "password", "password": "another123", "password_confirmation": "another123"})
        self.tech.refresh_from_db()
        self.assertTrue(self.tech.check_password("another123"))


class UserManagementTest(AppTestCase):
    def test_superadmin_changes_role_and_disables_account(self):
        self.login(self.admin)
        r = self.client.post(reverse("users.update", args=[self.tech.id]), {"name": self.tech.name, "username": "brite-tech", "email": "tech@brite-tsi.com", "role": "admin"})
        self.assertEqual(r.status_code, 302)
        self.tech.refresh_from_db()
        self.assertEqual(self.tech.role, "admin")
        self.client.post(reverse("users.disabled", args=[self.tech.id]))
        self.tech.refresh_from_db()
        self.assertIsNotNone(self.tech.disabled_at)
        self.client.post(reverse("users.disabled", args=[self.tech.id]))
        self.tech.refresh_from_db()
        self.assertIsNone(self.tech.disabled_at)

    def test_employee_cannot_manage_users(self):
        self.login(self.tech)
        self.assertEqual(self.client.get(reverse("users.index")).status_code, 403)

    def test_soft_delete_and_restore(self):
        self.login(self.admin)
        self.client.post(reverse("users.destroy", args=[self.hr.id]), {"password": "password"})
        self.assertFalse(User.objects.filter(pk=self.hr.id).exists())
        self.assertTrue(User.all_objects.filter(pk=self.hr.id).exists())
        self.client.post(reverse("users.restore", args=[self.hr.id]))
        self.assertTrue(User.objects.filter(pk=self.hr.id).exists())

    def test_superadmin_account_is_locked(self):
        self.login(self.dev)
        r = self.client.post(reverse("users.update", args=[self.admin.id]), {"name": "x", "username": "brite-admin", "email": "admin@brite-tsi.com", "role": "employee"})
        self.admin.refresh_from_db()
        self.assertEqual(self.admin.role, "superadmin")
