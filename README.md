# BT-Attendance (Django)

The BT-Attendance system ported from Laravel/PHP to **Django 5.2 / Python 3.11**, feature for feature, on the
same database schema, the same Tailwind v4 + Alpine.js front end, and the same role matrix. This is the
`django` branch; the original Laravel implementation stays on `main` (last commit
`FINALIZED/PHP-LOANS&PAYSLIPS&MOBILE`).

See `ARCHITECTURE.md` for the full system design; every module described there exists here with the same
name (its Laravel file paths refer to `main`, §29 maps them to Django). This file covers only what is
different because it is Django.

## Layout

| Django app      | What it holds                                                                     | Laravel equivalent                         |
|-----------------|-----------------------------------------------------------------------------------|--------------------------------------------|
| `core`          | Settings/Notification models, dashboard, template tags, `seed`, `migrate_from_laravel` | `App\Support`, `DashboardController`, Blade components |
| `accounts`      | `User` (table `users`), login, profile, passwords, User Management, RBAC          | Breeze auth, `UserController`, `RoleMatrix`, spatie |
| `employees`     | Employees, schedules, sites (geofences), project assignments, import/export       | `EmployeeController`, `SiteController`, `ProjectAssignmentController` |
| `attendance`    | Clock in/out, geofence service, monitor, timesheet, soft-copy PNG                 | `AttendanceController`, `GeofenceService`   |
| `leaveot`       | Leave, early-out, overtime requests and approvals                                 | `LeaveController`, `OvertimeController`     |
| `payroll`       | Periods, calculator, runner (pay-day reminder), rates, loans / missing items      | `PayrollController`, `PayrollCalculator`, `PayrollRunner` |
| `checkpoints`   | Campaigns, dispatcher, verifier, reviewer, employee side                          | `CheckpointCampaignController`, `Services\Checkpoint\*` |

Templates live in `templates/` (one folder per module, `layouts/app.html` is the shell, `ui/` holds the
reusable components that were Blade `<x-…>` components). `static/src/app.css` is the *same* stylesheet as the
Laravel app; Vite builds it to `static/dist/`.

## Running it

```bash
python3.11 -m venv venv && ./venv/bin/pip install -r requirements.txt
npm install && npm run build          # Tailwind + Alpine + Leaflet → static/dist
cp .env.example .env                  # then edit SECRET_KEY / DATABASE_URL
./venv/bin/python manage.py migrate
./venv/bin/python manage.py seed      # roles, permissions, HQ + a project site, leave types, demo accounts
./venv/bin/python manage.py runserver
```

Demo accounts (password `password`): `brite-admin` (Super Admin), `brite-dev` (Developer), `brite-hr` (Admin/HR),
`brite-tech` (Employee). `seed --no-demo` creates only the reference data.

Scheduled work — run both every minute from cron (or a systemd timer); each is idempotent:

```
* * * * *  cd /srv/bt-attendance && ./venv/bin/python manage.py checkpoints_tick   # start scheduled campaigns, expire lapsed ones
* * * * *  cd /srv/bt-attendance && ./venv/bin/python manage.py payroll_tick       # roll periods forward, pay-day reminder at 07:00
```

Production: `DEBUG=False`, a real `SECRET_KEY`, `ALLOWED_HOSTS`, `DATABASE_URL` (MySQL/Postgres/SQLite), then
`manage.py collectstatic` and `gunicorn btattendance.wsgi`. WhiteNoise serves the built assets; uploads live in
`media/public` (selfies, profile photos — served at `/storage/…`) and `media/private` (checkpoint photos — only
through the permission-checked `/checkpoint-photos/<id>` view).

## Reusing the existing Laravel database

Every model maps to the Laravel table with the Laravel column names, so the production data can be adopted
in place instead of exported:

```bash
DATABASE_URL=mysql://user:pass@host/bt_attendance ./venv/bin/python manage.py migrate_from_laravel --dry-run
DATABASE_URL=mysql://user:pass@host/bt_attendance ./venv/bin/python manage.py migrate_from_laravel
```

It creates Django's own tables (sessions, groups, permissions), adds the three auth columns Django needs on
`users`, creates the two tables that are new (`app_notifications`, the user↔group links) and maps spatie roles to
Django groups. Passwords need no conversion: hashes are raw `$2y$` bcrypt in both directions, so the same
`users` table can be read by the PHP app, this app and — under the planned single sign-on — BT-Inventory.

## Tests

```bash
./venv/bin/python manage.py test          # 98 tests: auth, attendance, leave/OT, employees/sites, payroll, checkpoints
```

`tests/base.py` seeds the same data as `manage.py seed`; every test class logs in with `self.client.force_login`.

## Django-specific conventions

* **Permissions** — the 20 permission names from `RoleMatrix` are Django `Permission` rows on the unmanaged
  `accounts.AppPermission` model; roles are Groups. `user.can("run payroll")` in Python, `{% if can.run_payroll %}`
  in templates, `@permission_required("a", "b")` (any-of) on views.
* **Time** — `USE_TZ = False`, `TIME_ZONE = "Asia/Manila"`: naive local datetimes exactly like the Laravel app,
  so the shared tables never mix aware/naive values. Use `timezone.now()` / `timezone.now().date()`; never
  `timezone.localdate()`.
* **Form redisplay** — validation failures stash `form_errors` / `form_old` in the session and redirect back
  (Laravel's `withErrors()->withInput()`); views pass a merged `v` dict (`core.support.form_values`) so templates
  read `{{ v.first_name }}` whether it is a create or an edit.
* **Passing data to Alpine** — `{{ rows|js }}` (Laravel's `@js`): safe inside a double-quoted `x-data` attribute.
* **htmx for partial updates** — `static/vendor/htmx.min.js` (2.0, one vendored script, no npm) is loaded by
  `layouts/app.html`, which also sends Django's CSRF token on every htmx request (`hx-headers` on `<body>`) and
  keeps back/forward as plain page loads. htmx does the server round-trips; Alpine keeps pure client state
  (toggles, modals, the camera page, map pickers). One view serves both the page and the fragment:

  ```python
  from core.htmx import is_htmx, render_partial
  ctx = _monitor_context(request.GET)
  if is_htmx(request):
      return render_partial(request, "attendance/_monitor_results.html", ctx, oob=[("attendance/_monitor_summary.html", ctx)])
  return render(request, "attendance/monitor.html", ctx)
  ```

  `render_partial` appends the `oob` fragments (their roots carry `hx-swap-oob`) and the pending flash messages
  (`ui/flash.html`, swapped into `#flash`), so an htmx action shows the same banner a redirect would. Fragments
  are `_name.html` files next to the page template. `core.middleware.HtmxRedirectMiddleware` turns any redirect
  (login expiry, temporary-password gate) into an `HX-Redirect`, and a failed request shows a banner from
  the layout's `htmx:responseError` listener. Permission decorators stay on the view, so the fragment path is
  gated exactly like the page. Converted so far: the Attendance Log (`attendance/monitor`) — date stepper,
  live search, and the location / overtime decisions swap a single row.
* **Money** — `{{ amount|money }}` renders `₱12,500.00` and negatives as `−₱250.00`.
