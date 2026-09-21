"""
Point DATABASE_URL at the existing BT-Attendance (Laravel) database and run this once.

Every business table keeps its Laravel name and columns, so the data is reused in
place. What this command adds is only what Django itself needs:

  1. Django's own tables (sessions, content types, auth groups/permissions, migrations).
  2. The columns Django's auth layer expects on `users` (last_login, is_superuser, …).
  3. Any table or column a Django model has that the Laravel schema does not
     (e.g. app_notifications, the group / permission link tables).
  4. Roles: spatie `model_has_roles` rows → Django groups, so `user.role` works.

Idempotent — safe to run again after pulling a newer version.
"""

from django.apps import apps
from django.core.management import call_command
from django.core.management.base import BaseCommand
from django.db import connection

from accounts import rbac
from accounts.models import User

OUR_APPS = ["core", "accounts", "employees", "attendance", "leaveot", "payroll", "checkpoints"]


def _add_column(editor, model, field):
    """ALTER TABLE … ADD COLUMN. SQLite's editor would otherwise rebuild the whole table (and trip over the auth M2M tables)."""
    if connection.vendor != "sqlite":
        editor.add_field(model, field)
        return
    sql, _ = editor.column_sql(model, field, include_default=False)
    default = editor.effective_default(field)
    if default is not None:
        sql += f" DEFAULT {editor.quote_value(default)}"
    editor.execute(f"ALTER TABLE {editor.quote_name(model._meta.db_table)} ADD COLUMN {editor.quote_name(field.column)} {sql}")


class Command(BaseCommand):
    help = "Adapt an existing Laravel BT-Attendance database so this Django app can run on it."

    def add_arguments(self, parser):
        parser.add_argument("--dry-run", action="store_true", help="Only report what would change.")

    def handle(self, *args, **options):
        dry = options["dry_run"]
        existing = set(connection.introspection.table_names())
        if "users" not in existing or "employees" not in existing:
            self.stderr.write("This does not look like a BT-Attendance database (no users/employees tables). Use `manage.py migrate` for a fresh install.")
            return

        # 1. Django's own tables, then mark our app migrations as applied without touching the Laravel tables.
        if not dry:
            call_command("migrate", "contenttypes", verbosity=0)
            call_command("migrate", "auth", verbosity=0)
            call_command("migrate", "sessions", verbosity=0)
            for app in OUR_APPS:
                call_command("migrate", app, fake=True, verbosity=0)
        self.stdout.write("Django system tables ready; app migrations faked.")

        # 2 + 3. Missing tables and columns, model by model.
        with connection.schema_editor() as editor:
            for app in OUR_APPS:
                for model in apps.get_app_config(app).get_models(include_auto_created=True):
                    if not model._meta.managed:
                        continue
                    table = model._meta.db_table
                    if table not in existing:
                        self.stdout.write(f"  + table {table}")
                        if not dry:
                            editor.create_model(model)
                        existing.add(table)
                        continue
                    with connection.cursor() as cursor:
                        have = {c.name for c in connection.introspection.get_table_description(cursor, table)}
                    for field in model._meta.local_fields:
                        if field.column not in have:
                            self.stdout.write(f"  + column {table}.{field.column}")
                            if not dry:
                                _add_column(editor, model, field)
                    for field in model._meta.local_many_to_many:
                        through = field.remote_field.through
                        if through._meta.auto_created and through._meta.db_table not in existing:
                            self.stdout.write(f"  + table {through._meta.db_table}")
                            if not dry:
                                editor.create_model(through)
                            existing.add(through._meta.db_table)

        if dry:
            self.stdout.write(self.style.WARNING("Dry run — nothing changed."))
            return

        # 4. Permissions and roles.
        rbac.sync_defaults()
        moved = 0
        if {"roles", "model_has_roles"} <= existing:
            with connection.cursor() as cursor:
                cursor.execute(
                    "SELECT mhr.model_id, r.name FROM model_has_roles mhr JOIN roles r ON r.id = mhr.role_id "
                    "WHERE mhr.model_type LIKE '%User'"
                )
                rows = cursor.fetchall()
            by_id = {u.id: u for u in User.all_objects.all()}
            for user_id, role in rows:
                user = by_id.get(user_id)
                if user and role in rbac.ROLES:
                    user.set_role(role)
                    moved += 1
        # Laravel never stored these; Django's auth expects them to exist.
        User.all_objects.filter(last_login__isnull=True).update(last_login=None)
        self.stdout.write(self.style.SUCCESS(f"Done. {moved} user role(s) mapped to Django groups. Laravel `$2y$` password hashes keep working as-is."))
