# BT-Attendance — Complete System Flow

> A comprehensive documentation of the BT-Attendance system architecture, workflows, and technical implementation.

---

## Table of Contents

1. [Tech Stack](#1-tech-stack)
2. [System Architecture](#2-system-architecture)
3. [Authentication Flow](#3-authentication-flow)
4. [Dashboard](#4-dashboard)
5. [Clock In/Out Flow](#5-clock-inout-flow)
6. [Multi-Site Geofencing & Attendance Location Rules](#6-multi-site-geofencing--attendance-location-rules)
7. [Attendance Management](#7-attendance-management)
8. [Leave Management](#8-leave-management)
9. [Overtime Management](#9-overtime-management)
10. [Shift & Schedule Management](#10-shift--schedule-management)
11. [Employee Management](#11-employee-management)
12. [Payroll System](#12-payroll-system)
13. [Profile Management](#13-profile-management)
14. [Roles & Permissions](#14-roles--permissions)
15. [Notifications](#15-notifications)
16. [Route Map](#16-route-map)
17. [Database Schema](#17-database-schema)
18. [Support Classes & Services](#18-support-classes--services)
19. [Configuration](#19-configuration)

---

## 1. Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | Laravel 11+ |
| **Auth Scaffolding** | Laravel Breeze |
| **RBAC** | Spatie Laravel-Permission |
| **Frontend** | Blade + Alpine.js |
| **Maps** | Leaflet.js + OpenStreetMap |
| **Image Processing** | Intervention Image |
| **Spreadsheet** | PhpSpreadsheet |
| **Database** | MySQL (22 migrations) |

---

## 2. System Architecture

### High-Level Flow

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐
│   Browser   │────▶│  Laravel App │────▶│   MySQL     │
│  (Alpine.js)│◀────│  (PHP 8.2+)  │◀────│   Database  │
└─────────────┘     └──────────────┘     └─────────────┘
       │                   │
       │                   │
       ▼                   ▼
┌─────────────┐     ┌──────────────┐
│  Camera API │     │  Storage     │
│  GPS API    │     │  (Selfies)   │
│  Leaflet.js │     │              │
└─────────────┘     └──────────────┘
```

### Module Overview

```
BT-Attendance
├── Authentication (Breeze)
├── Dashboard
├── Attendance
│   ├── Clock In/Out (Camera + GPS + Geofence)
│   ├── Personal History
│   ├── HR Monitor
│   └── Soft Copy Generation
├── Leave Management
│   ├── Regular Leave Requests
│   └── Early Leave (Mid-Shift)
├── Overtime Management
│   ├── Auto-Calculated OT
│   └── HR Verification (13h+)
├── Payroll
│   ├── Semi-Monthly Periods
│   ├── Auto-Calculation
│   └── Payslip Generation
├── Employee Management
│   ├── CRUD Operations
│   └── Bulk Import (Excel)
└── Settings
    ├── Schedules
    ├── Sites (Geofences)
    ├── Leave Types
    └── Contribution Rates
```

---

## 3. Authentication Flow

### Files Involved

| File | Purpose |
|------|---------|
| `routes/auth.php` | Guest & auth route groups |
| `app/Http/Controllers/Auth/*` | 8 Breeze controllers |
| `app/Http/Requests/Auth/LoginRequest.php` | Throttled login validation |
| `resources/views/auth/*.blade.php` | Auth views |

### Flow Diagram

```
                    ┌──────────────┐
                    │   Visitor    │
                    └──────┬───────┘
                           │
              ┌────────────┼────────────┐
              ▼            ▼            ▼
        ┌──────────┐ ┌──────────┐ ┌──────────┐
        │  Login   │ │ Register │ │  Forgot  │
        │  Form    │ │  Form    │ │ Password │
        └────┬─────┘ └────┬─────┘ └────┬─────┘
             │            │            │
             ▼            ▼            ▼
        ┌──────────┐ ┌──────────┐ ┌──────────┐
        │  LoginRequest│ │RegisteredUser│ │PasswordReset│
        │  (throttled) │ │Controller    │ │LinkController│
        └────┬─────┘ └────┬─────┘ └────┬─────┘
             │            │            │
             ▼            ▼            ▼
        ┌──────────┐ ┌──────────┐ ┌──────────┐
        │ Validate │ │ Create   │ │ Send     │
        │ Credentials│ │ User +  │ │ Reset    │
        │          │ │ Login    │ │ Email    │
        └────┬─────┘ └────┬─────┘ └────┬─────┘
             │            │            │
             ▼            ▼            ▼
        ┌──────────┐ ┌──────────┐ ┌──────────┐
        │Dashboard │ │Dashboard │ │ Reset    │
        │ Redirect │ │ Redirect │ │ Form     │
        └──────────┘ └──────────┘ └──────────┘
```

### Key Operations

| Operation | Method | Route | Description |
|-----------|--------|-------|-------------|
| Login | `POST` | `/login` | Authenticates user, regenerates session |
| Register | `POST` | `/register` | Creates User + auto-login |
| Forgot Password | `POST` | `/forgot-password` | Sends reset link via email |
| Reset Password | `POST` | `/reset-password` | Updates password with token |
| Verify Email | `GET` | `/verify-email/{id}/{hash}` | Signed URL verification |
| Logout | `POST` | `/logout` | Invalidates session |

### Middleware

- `guest` — Blocks authenticated users from auth pages
- `auth` — Requires authentication
- `verified` — Requires email verification
- `signed` + `throttle:6,1` — On email verification links

---

## 4. Dashboard

### Files Involved

| File | Purpose |
|------|---------|
| `app/Http/Controllers/DashboardController.php` | Data aggregation |
| `resources/views/dashboard.blade.php` | Dashboard UI |

### What's Displayed

```
┌─────────────────────────────────────────────────┐
│                                                 │
│  Good morning, Juan!                            │
│  Admin • 8:30 AM – 5:30 PM                      │
│                                                 │
│  ┌─────────────────────────────────────────┐   │
│  │         CLOCK IN NOW                    │   │
│  │         (or CLOCK OUT if already in)    │   │
│  └─────────────────────────────────────────┘   │
│                                                 │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐
│  │  TODAY   │ │  PENDING │ │  PENDING │ │APPROVALS │
│  │Clocked In│ │  LEAVES  │ │ OT REQ   │ │    3     │
│  │ 9:02 AM  │ │    2     │ │    1     │ │  pending │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘
│                                                 │
│  ┌─────────────────────────────────────────┐   │
│  │  ACTIVE EMPLOYEES: 12    PAYROLL: Open  │   │
│  └─────────────────────────────────────────┘   │
│                                                 │
│  QUICK ACTIONS                                  │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐       │
│  │ Clock In │ │  File    │ │  File    │       │
│  │ / Out    │ │  Leave   │ │   OT     │       │
│  └──────────┘ └──────────┘ └──────────┘       │
│                                                 │
└─────────────────────────────────────────────────┘
```

### Stat Tiles

| Tile | Shows | Condition |
|------|-------|-----------|
| **Today** | Clocked in/out status + time | Always |
| **My Pending Leave** | Count of pending leave requests | Always |
| **My Pending OT** | Count of pending OT requests | Always |
| **Approvals** | Pending approvals count | If `canApprove` |
| **Active Employees** | Count + "Manage" link | If `canManage` |
| **Current Payroll** | Period label + status | If `canPayroll` |

### Quick Actions

1. **Clock In / Out** → `attendance.create`
2. **File Leave** → `leave.create`
3. **File Overtime** → `overtime.create`

---

## 5. Clock In/Out Flow

### Files Involved

| File | Purpose |
|------|---------|
| `app/Http/Controllers/AttendanceController.php` | `create()`, `store()`, `softCopy()` |
| `app/Services/AttendanceSoftCopy.php` | PNG generation |
| `app/Support/Geo.php` | Haversine distance |
| `app/Services/GeofenceService.php` | Server-side evaluation against all active sites → `GeofenceResult` |
| `resources/views/attendance/create.blade.php` | Camera + GPS capture UI |
| `config/attendance.php` | `geofence_mode` + `min_gps_accuracy_m` |

### Complete Journey

```
┌─────────────────────────────────────────────────────────────┐
│                    DASHBOARD                                │
│                    ┌─────────────┐                          │
│                    │ Clock In/Out│                          │
│                    │   Button    │                          │
│                    └──────┬──────┘                          │
└───────────────────────────┼─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                 GET /attendance                              │
│                 AttendanceController@create                  │
│                                                             │
│  1. Load employee profile (abort 403 if none)              │
│  2. Determine nextAction (time_in or time_out)             │
│  3. Load all ACTIVE Sites + employee's active assignment    │
│  4. Read geofence_mode config                               │
│  5. Return attendance.create view                           │
└───────────────────────────┼─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                 ATTENDANCE CREATE VIEW                       │
│                 (Alpine.js clockCapture())                   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  TIME IN │ TIME OUT    ← Toggle (defaults to next)  │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │                                                     │   │
│  │              CAMERA FEED                            │   │
│  │         (facingMode: 'user')                        │   │
│  │                                                     │   │
│  └─────────────────────────────────────────────────────┘   │
│  [ Capture Photo ]  or  [ Retake ]                          │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │              LEAFLET MAP                            │   │
│  │  • User pin (non-draggable)                         │   │
│  │  • Geofence circles (registered sites)              │   │
│  │  • Accuracy ring                                    │   │
│  └─────────────────────────────────────────────────────┘   │
│  📍 At Brite-Tech Office (±15m)  [Recenter]                │
│  14.5995, 120.9842                                         │
│                                                             │
│  [        CONFIRM TIME IN / TIME OUT        ]               │
│  Ready — tap Confirm Time In.                               │
│                                                             │
└───────────────────────────┼─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                 POST /attendance                             │
│                 AttendanceController@store                   │
│                                                             │
│  1. Validate: log_type, latitude, longitude, photo         │
│  2. Server-side geofence check                              │
│     └─ strict: reject · approval: pending · warning: flag   │
│  3. Decode base64 photo → store to storage                  │
│  4. Create AttendanceLog record                             │
│  5. Redirect to attendance.index with flash                 │
│     └─ flash: softcopy_log_id, softcopy_type               │
└───────────────────────────┼─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                 ATTENDANCE INDEX                             │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Download your soft copy?                           │   │
│  │  [ Download ]  [ Dismiss ]                          │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  (Soft copy = branded PNG with selfie, time, location)     │
└─────────────────────────────────────────────────────────────┘
```

### Client-Side Components

#### Camera Capture

```javascript
// Start camera
navigator.mediaDevices.getUserMedia({
    video: { facingMode: 'user' },  // Front camera
    audio: false
});

// Capture photo
ctx.drawImage(video, 0, 0);
stampTimestamp(ctx, width, height);  // Burns PH time onto image
photo = canvas.toDataURL('image/jpeg', 0.8);
```

#### GPS Tracking

```javascript
// Watch position (continuous updates)
navigator.geolocation.watchPosition(
    (pos) => setLocation(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy),
    (err) => { /* error handling */ },
    { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 }
);
```

#### Geofence Evaluation (Client-Side Mirror)

```javascript
// For each registered site
sites.forEach((s) => {
    const d = distanceMeters(lat, lng, s.latitude, s.longitude);
    if (d < bestDist) { bestDist = d; best = s; }
});
onSite = bestDist <= best.geofence_radius_m;
```

### Submit Button Logic

```javascript
canSubmit() {
    if (!(this.photo && this.lat && this.lng)) return false;
    if (this.enforceGeofence && this.sites.length && !this.onSite) return false;
    return true;
}
```

### Server-Side Validation

```php
// Geofence check
$site = $this->matchGeofence($lat, $lng);
if (config('attendance.enforce_geofence') && $sites->isNotEmpty() && !$site) {
    return back()->withErrors(['latitude' => "You are {$distance}m from the nearest site..."]);
}

// Photo storage
$decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $photo));
$path = "attendance/{$employee->id}/" . now()->format('Y-m-d_His') . '_' . Str::random(8) . '.jpg';
Storage::disk('public')->put($path, $decoded);
```

### Soft Copy Generation

**File:** `app/Services/AttendanceSoftCopy.php`

Generates a 700×704px branded PNG containing:
- Company logo
- Reference code (e.g., `ATT-123-IN`)
- TIME IN / TIME OUT pill
- Captured selfie photo
- Employee details (name, ID, date, status)
- OpenStreetMap tile with red pin at coordinates
- Distance from nearest site
- Geofence status
- Generation timestamp

---

## 6. Multi-Site Geofencing & Attendance Location Rules

### Purpose

BT-Attendance must support a company with a permanent main office and multiple changing project sites. Employees may be assigned to a project, temporarily unassigned, or working at the main office.

The system must therefore separate:

1. **Authorized attendance locations** — places where attendance may be recorded.
2. **Employee project assignments** — the project site an employee is currently assigned to.

An employee does not need an active project assignment to record attendance at the main office.

### Authorized Attendance Locations

Each active location has a virtual geofence circle.

| Location Type | Example | Behavior |
|---|---|---|
| Main Office | Sampaloc, Manila | Permanent authorized attendance location |
| Project Site | Project Site A, Quezon City | Active while the project is operating |
| Temporary/Alternate Site | Training venue or temporary work area | Optional authorized location with an activation period |

Suggested fields:

```text
AttendanceLocation
------------------
id
name
type                  # office, project_site, temporary
address
latitude
longitude
radius_meters
status                # active, inactive, completed
active_from
active_until
created_by
updated_by
```

The main office should remain active unless management intentionally disables it. Completed project sites should be deactivated rather than deleted so historical attendance records remain accurate.

### Employee Project Assignments

```text
EmployeeProjectAssignment
-------------------------
id
employee_id
project_site_id
start_date
end_date
status                # active, ended, cancelled
assignment_notes
```

An employee may have no active assignment. This is valid for:

- Admin personnel
- Technical personnel waiting for deployment
- Employees reporting to the office
- Employees between projects
- Employees temporarily assigned to office work

### Location Validation Rule

When an employee clocks in or clocks out:

```text
1. Request the employee's GPS coordinates.
2. Load all active authorized attendance locations.
3. Calculate the distance from the employee to each location.
4. Find the nearest location.
5. Determine whether the employee is inside any location's radius.
6. If inside a location, record that location.
7. Compare the location with the employee's active project assignment.
8. If outside every location, show a warning and start the exception flow.
```

The system must check **all active authorized locations**, not only the employee's assigned project.

### Main Office Rule

The main office is a valid attendance location for every authorized employee.

```text
Employee has no project assignment
        +
Employee is inside Main Office geofence
        =
Attendance allowed
```

Example:

| Employee | Active Assignment | Current Location | Result |
|---|---|---|---|
| Admin A | None | Main Office | Verified attendance |
| Technical A | None | Main Office | Verified attendance |
| Employee B | Project Site A | Project Site A | Verified attendance |
| Employee C | Project Site A | Main Office | Authorized alternate location |
| Employee D | Project Site A | Project Site B | Authorized alternate location or warning, based on policy |
| Employee E | None | Outside all locations | Outside authorized area |

### Assigned Site vs. Authorized Location

Being assigned to Project Site A does not automatically mean the employee is prohibited from attending at the main office.

The system should record both values:

```text
attendance_location = Main Office
assigned_project_site = Project Site A
location_status = authorized_alternate_location
```

This allows HR to distinguish between:

- Attendance at the assigned project
- Attendance at the main office
- Attendance at another authorized project
- Attendance outside all authorized areas

### Location Status Values

Recommended statuses:

| Status | Meaning | Default Behavior |
|---|---|---|
| `verified_location` | Inside an active authorized location | Allow attendance |
| `authorized_alternate_location` | Inside an authorized location other than the assigned project | Allow and record warning/informational status |
| `outside_authorized_area` | Outside every active location geofence | Show warning and require exception handling |
| `gps_unavailable` | GPS permission denied, unavailable, or timed out | Do not claim location verification; use manual correction flow |
| `low_accuracy` | GPS accuracy is too poor for reliable validation | Show warning and request a better location reading |

### Geofence Warning Flow

The first version should not automatically block every employee who is outside a circle. GPS can be inaccurate around buildings, dense urban areas, and construction sites.

```text
Employee clicks TIME IN / TIME OUT
                ↓
Get GPS coordinates and accuracy
                ↓
Check all active authorized locations
                ↓
Inside any geofence?
        ┌───────┴────────┐
       YES               NO
        ↓                 ↓
Identify location     Show warning:
        ↓             "You are outside
Compare assignment    the authorized area."
        ↓                 ↓
Allow attendance      Offer:
and record status     - Retry GPS
                      - Submit attendance
                        correction/reason
                      - Contact HR/Admin
```

Suggested warning message:

> You are currently outside the authorized attendance area. Please verify your location. If you are working at an approved temporary location or the GPS reading is inaccurate, submit a correction request.

### Strictness Configuration

The system should support configurable behavior:

```text
geofence_mode:
    warning       # Allow attendance but mark as exception
    approval      # Save as pending verification
    strict        # Block attendance outside all authorized areas
```

Recommended default:

```text
geofence_mode = warning
```

This avoids losing attendance records because of GPS inaccuracies while still notifying HR about exceptions.

### Attendance Data to Record

Each attendance log should preserve the location evidence used during validation:

```text
AttendanceLog
-------------
employee_id
attendance_location_id
assigned_project_site_id
log_type
logged_at
latitude
longitude
gps_accuracy_meters
distance_from_location_meters
within_geofence
location_status
location_validation_message
photo_path
verification_status
verified_by
verified_at
```

The recorded `attendance_location_id` must refer to the location identified at the time of attendance. Historical records must not change when a project site is later completed or its coordinates/radius are edited.

### Project Completion Flow

```text
Project Site A is completed
        ↓
HR/Admin marks Project Site A as completed/inactive
        ↓
Its geofence is no longer available for new attendance
        ↓
Historical attendance remains unchanged
        ↓
Employees are assigned to Project Site B
        ↓
Project Site B is activated
        ↓
Employees can attend at Project Site B
```

The main office remains available throughout this process.

### Recommended Database Relationships

```text
AttendanceLocation
        │
        ├── has many EmployeeProjectAssignments
        └── has many AttendanceLogs

Employee
        │
        ├── has many EmployeeProjectAssignments
        └── has many AttendanceLogs

AttendanceLog
        ├── belongs to Employee
        ├── belongs to AttendanceLocation
        └── optionally references assigned Project Site
```

### Security and Validation Requirements

- GPS coordinates must be validated server-side; client-side checks are only a visual preview.
- The client must not be able to choose an arbitrary attendance location without server verification.
- The server should calculate distance using the stored coordinates and the Haversine formula.
- Store GPS accuracy and validation status for audit purposes.
- Do not delete locations referenced by historical attendance logs.
- Require HTTPS because browser GPS and camera access generally require a secure context.
- Treat GPS as location evidence, not absolute proof of physical presence.
- Provide an HR correction/approval workflow for legitimate exceptions.


---

## 7. Attendance Management

### Files Involved

| File | Purpose |
|------|---------|
| `app/Http/Controllers/AttendanceController.php` | `index()`, `monitor()`, `verify()` |
| `app/Support/WorkSessions.php` | Session pairing logic |
| `app/Support/WorkHours.php` | Regular/OT split |
| `resources/views/attendance/index.blade.php` | Personal history |
| `resources/views/attendance/monitor.blade.php` | HR monitor |

### Personal History (`GET /attendance/logs`)

```
┌─────────────────────────────────────────────────────────────┐
│  MY ATTENDANCE                                              │
│                                                             │
│  ┌─────┬─────────────────┬──────────┬──────────┬────────┐  │
│  │Photo│ Date & Time     │ Type     │ Location │Copy    │  │
│  ├─────┼─────────────────┼──────────┼──────────┼────────┤  │
│  │ 📷  │ Jul 15, 9:02 AM │ Time In  │ 📍 Link  │ ⬇️ PNG │  │
│  │ 📷  │ Jul 15, 6:15 PM │ Time Out │ 📍 Link  │ ⬇️ PNG │  │
│  │ 📷  │ Jul 14, 8:58 AM │ Time In  │ 📍 Link  │ ⬇️ PNG │  │
│  │ 📷  │ Jul 14, 5:45 PM │ Time Out │ 📍 Link  │ ⬇️ PNG │  │
│  └─────┴─────────────────┴──────────┴──────────┴────────┘  │
│                                                             │
│  (Paginated 20 per page, latest first)                     │
└─────────────────────────────────────────────────────────────┘
```

### HR Monitor (`GET /attendance/monitor`)

**Permission Required:** `view team reports`

```
┌─────────────────────────────────────────────────────────────┐
│  ATTENDANCE MONITOR                                         │
│                                                             │
│  ◀ Jul 15, 2026 ▶  [Today]    Search: [___________]       │
│                                                             │
│  Present: 8  |  Absent: 2  |  Rest Day: No                 │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ EMPLOYEE        │ TIME IN  │ TIME OUT │ HOURS │STATUS│   │
│  ├─────────────────┼──────────┼──────────┼───────┼──────┤   │
│  │ Juan Dela Cruz  │ 9:02 AM  │ 6:15 PM  │ 9.2h  │ ✅   │   │
│  │ 001             │ 📷 📍    │ 📷 📍    │       │      │   │
│  ├─────────────────┼──────────┼──────────┼───────┼──────┤   │
│  │ Maria Santos    │ 8:45 AM  │ —        │ —     │ ⏳   │   │
│  │ 002             │ 📷 📍    │ No out   │       │      │   │
│  ├─────────────────┼──────────┼──────────┼───────┼──────┤   │
│  │ Pedro Reyes     │ —        │ —        │ —     │ ❌   │   │
│  │ 003             │ Absent   │ Absent   │       │      │   │
│  └─────────────────┴──────────┴──────────┴───────┴──────┘   │
│                                                             │
│  ⚠️ Days ≥13h show "Needs HR verification" with            │
│     approve/reject form (requires remarks)                  │
└─────────────────────────────────────────────────────────────┘
```

### OT Verification (`POST /attendance/{log}/verify`)

- **Permission:** `approve requests`
- Only attaches to `time_out` logs
- Sets `ot_verification_status` (approved/rejected)
- Sends `RequestReviewed` notification to employee

### Work Session Pairing

**File:** `app/Support/WorkSessions.php`

```
Raw Logs:                    Paired Sessions:
┌──────────────────┐        ┌──────────────────────────────┐
│ 9:00 AM - Time In│   ──▶  │ In: 9:00 AM                  │
│ 6:00 PM - Time Out│       │ Out: 6:00 PM  (9h worked)    │
│ 9:05 AM - Time In│        ├──────────────────────────────┤
│ 5:45 PM - Time Out│       │ In: 9:05 AM                  │
└──────────────────┘        │ Out: 5:45 PM  (8.67h worked) │
                            └──────────────────────────────┘
```

### Work Hours Split

**File:** `app/Support/WorkHours.php`

```
Worked: 10 hours (600 minutes)

Split:
├── Regular: 8h (480 min)
└── Overtime: 2h (120 min)

On Rest Day (Weekend):
└── All hours = Overtime
```

---

## 8. Leave Management

### Files Involved

| File | Purpose |
|------|---------|
| `app/Http/Controllers/LeaveController.php` | All leave operations |
| `app/Models/LeaveRequest.php` | Leave request model |
| `app/Models/LeaveType.php` | Leave type model |
| `resources/views/leave/index.blade.php` | Leave list |
| `resources/views/leave/create.blade.php` | File leave form |
| `resources/views/leave/early.blade.php` | Early leave form |

### Leave Types (Seeded)

| Code | Name | Default Days/Year | Paid |
|------|------|-------------------|------|
| SIL | Service Incentive Leave | 5 | Yes |
| VL | Vacation Leave | 0 | Yes |
| SL | Sick Leave | 0 | Yes |
| ML | Maternity Leave | 105 | Yes |
| PL | Paternity Leave | 7 | Yes |
| SPL | Solo Parent Leave | 7 | Yes |
| SLW | Special Leave for Women | 60 | Yes |
| BL | Bereavement Leave | 3 | Yes |

### Regular Leave Request Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    FILE LEAVE                                │
│                    GET /leave/create                         │
│                                                             │
│  Leave Type:    [Sick Leave ▼]                              │
│  Day Portion:   (○ Full Day  ○ Half AM  ○ Half PM)         │
│  Date From:     [2026-07-15]                                │
│  Date To:       [2026-07-15]  (auto-filled for full day)   │
│  Reason:        [________________]                          │
│                                                             │
│  [ Submit Leave Request ]                                    │
└───────────────────────────┼─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    POST /leave                               │
│                    LeaveController@store                     │
│                                                             │
│  1. Validate: leave_type_id, day_portion, dates, reason    │
│  2. Calculate days:                                         │
│     • Half day: 0.5 days, date_to = date_from              │
│     • Full day: diffInDays + 1                              │
│  3. Create LeaveRequest (status: pending)                   │
│  4. Notify all users with "approve requests" permission    │
│  5. Redirect to leave.index                                 │
└───────────────────────────┼─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    APPROVAL                                  │
│                    POST /leave/{leave}/approve               │
│                                                             │
│  1. Permission: approve requests                            │
│  2. Update status → approved                                │
│  3. Set approved_by, approved_at                            │
│  4. Send RequestReviewed notification to employee           │
└─────────────────────────────────────────────────────────────┘
```

### Early Leave (Mid-Shift Sick)

```
┌─────────────────────────────────────────────────────────────┐
│                    EARLY LEAVE                               │
│                    GET /leave/early                          │
│                                                             │
│  Requested Time Out:  [02:00 PM]                            │
│  Reason:             [Feeling unwell...]                    │
│                                                             │
│  [ Submit Early Leave ]                                      │
└───────────────────────────┼─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  Auto-tagged as:                                            │
│  • Leave Type: Sick Leave (SL)                              │
│  • Day Portion: Half PM                                     │
│  • Days: 0.5                                                │
│  • is_early_leave: true                                     │
└─────────────────────────────────────────────────────────────┘
```

### Leave Index Views

**Employee View:**
- Shows own requests only
- Status badges: Pending, Approved, Denied

**Approver View:**
- Shows all requests
- Approve/Deny buttons with optional remarks

---

## 9. Overtime Management

### Files Involved

| File | Purpose |
|------|---------|
| `app/Http/Controllers/OvertimeController.php` | All OT operations |
| `app/Models/OvertimeRequest.php` | OT request model |
| `app/Support/WorkHours.php` | OT calculation |
| `app/Support/WorkSessions.php` | Session pairing |
| `resources/views/overtime/index.blade.php` | OT list |
| `resources/views/overtime/create.blade.php` | File OT form |

### OT Types & Multipliers

| Type | Multiplier | When |
|------|-----------|------|
| `regular` | 1.25 (125%) | Weekday (Mon–Fri) |
| `rest_day` | 1.30 (130%) | Weekend (Sat–Sun) |
| `holiday` | 2.00 (200%) | Regular holiday |

### OT Request Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    FILE OVERTIME                             │
│                    GET /overtime/create                      │
│                                                             │
│  Date:  [2026-07-15]                                        │
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  LIVE PREVIEW (fetched via AJAX)                    │   │
│  │                                                     │   │
│  │  Date: Jul 15, 2026 (Tuesday)                       │   │
│  │  Type: Regular Weekday (125%)                        │   │
│  │  Calculated Hours: 2.5h                              │   │
│  │  Message: Based on your attendance: 9:02 AM – 6:15 PM│   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  [ Submit Overtime Request ]                                 │
└───────────────────────────┼─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    POST /overtime                            │
│                    OvertimeController@store                  │
│                                                             │
│  1. Employee selects date ONLY                              │
│  2. Hours/type AUTO-CALCULATED server-side:                 │
│     │                                                       │
│     ├─ Load attendance logs for date                        │
│     ├─ Pair into sessions via WorkSessions                  │
│     ├─ Calculate overtime:                                  │
│     │   ├─ Fixed schedule: actual_out − scheduled_out       │
│     │   ├─ Flexible weekday: worked − 8h                    │
│     │   └─ Weekend: all hours (rest day OT)                 │
│     └─ Requires clock-out to exist                          │
│                                                             │
│  3. Create OvertimeRequest                                  │
│  4. Notify approvers                                        │
└─────────────────────────────────────────────────────────────┘
```

### OT Calculation Examples

```
Example 1: Fixed Schedule (8:30 AM – 5:30 PM)
┌─────────────────────────────────────────────┐
│ Actual: 8:30 AM – 7:30 PM (11 hours)       │
│ Scheduled out: 5:30 PM                      │
│ OT = 7:30 PM − 5:30 PM = 2 hours           │
└─────────────────────────────────────────────┘

Example 2: Flexible Schedule (Weekday)
┌─────────────────────────────────────────────┐
│ Worked: 10 hours                            │
│ Standard: 8 hours                           │
│ OT = 10 − 8 = 2 hours                      │
└─────────────────────────────────────────────┘

Example 3: Weekend (Rest Day)
┌─────────────────────────────────────────────┐
│ Worked: 6 hours                             │
│ All hours = OT (rest day)                   │
│ OT = 6 hours × 130%                        │
└─────────────────────────────────────────────┘
```

### OT Verification (13h+ Threshold)

```
┌─────────────────────────────────────────────────────────────┐
│  ATTENDANCE MONITOR                                         │
│                                                             │
│  Juan Dela Cruz                                             │
│  Jul 15: 9:00 AM – 10:30 PM (13.5 hours)                  │
│                                                             │
│  ⚠️ Needs HR verification                                   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Remarks: [________________________________]         │   │
│  │ [ ✓ Approve ]  [ ✗ Reject ]                        │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

---

## 10. Shift & Schedule Management

### Files Involved

| File | Purpose |
|------|---------|
| `app/Models/Schedule.php` | Schedule model |
| `database/migrations/*_create_schedules_table.php` | Schema |
| `database/seeders/DatabaseSeeder.php` | Seeded schedules |

### Schedule Schema

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Schedule name |
| `time_in` | time (nullable) | Start time (null for flexible) |
| `time_out` | time (nullable) | End time (null for flexible) |
| `grace_minutes` | int | Late grace period |
| `is_flexible` | boolean | Flexible schedule flag |

### Seeded Schedules

| Name | Time In | Time Out | Grace | Flexible |
|------|---------|----------|-------|----------|
| Admin (8:30 AM – 5:30 PM) | 08:30 | 17:30 | 15 min | No |
| Technical (Flexible) | — | — | — | Yes |

### Schedule Impact

```
Fixed Schedule:
├── Late detection: clock_in > time_in + grace_minutes
├── Early out detection: clock_out < time_out
├── OT calculation: actual_out − scheduled_out
└── Absence deduction: expected_days − worked_days

Flexible Schedule:
├── No late/early detection
├── OT calculation: worked − 8h
└── No absence deduction
```

---

## 11. Employee Management

### Files Involved

| File | Purpose |
|------|---------|
| `app/Http/Controllers/EmployeeController.php` | CRUD + import |
| `app/Models/Employee.php` | Employee model |
| `resources/views/employees/*.blade.php` | Employee views |
| `employee-import-template.xlsx` | Import template |

### Employee Schema

| Field | Type | Description |
|-------|------|-------------|
| `user_id` | FK (nullable) | Link to auth account |
| `employee_no` | string | Auto: `EMP-{0001}` |
| `first_name` | string | First name |
| `last_name` | string | Last name |
| `email` | string | Email address |
| `phone` | string | Phone number |
| `employee_type` | enum | `admin` or `technical` |
| `schedule_id` | FK | Work schedule |
| `supervisor_id` | FK (self) | Supervisor |
| `monthly_salary` | decimal | Monthly salary |
| `daily_rate` | decimal | Monthly / 22 |
| `date_hired` | date | Hire date |
| `status` | enum | `active`, `inactive`, `on_leave` |

### CRUD Operations

#### Create Employee

```
┌─────────────────────────────────────────────────────────────┐
│  ADD EMPLOYEE                                               │
│                                                             │
│  Employee Information                                       │
│  ├─ First Name: [________]                                  │
│  ├─ Last Name:  [________]                                  │
│  ├─ Email:      [________]                                  │
│  ├─ Phone:      [________]                                  │
│  ├─ Type:       [Admin ▼]                                   │
│  ├─ Schedule:   [8:30 AM – 5:30 PM ▼]                      │
│  ├─ Supervisor: [________ ▼]                                │
│  ├─ Monthly Salary: [________]                              │
│  └─ Date Hired: [________]                                  │
│                                                             │
│  User Account                                               │
│  ├─ Role:       [Employee ▼]                                │
│  └─ Password:   [________] (auto-generated if blank)        │
│                                                             │
│  [ Create Employee ]                                         │
└───────────────────────────┼─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│  CREATED IN TRANSACTION:                                    │
│  1. Create User (with role)                                 │
│  2. Create Employee (linked to User)                        │
│  3. Auto-generate temp password if not provided             │
│  4. Role assignment limited by safeRole()                   │
└─────────────────────────────────────────────────────────────┘
```

#### Bulk Import

```
┌─────────────────────────────────────────────────────────────┐
│  1. Download Template                                       │
│     GET /employees/import/template                          │
│     → employee-import-template.xlsx                         │
│                                                             │
│  2. Fill Template                                           │
│     Columns: first_name, last_name, email, phone,          │
│              employee_type, schedule, monthly_salary,       │
│              date_hired                                     │
│                                                             │
│  3. Upload                                                  │
│     POST /employees/import                                  │
│     → Creates User + Employee per row                       │
│     → Default password for all                              │
│     → Assigns 'employee' role                               │
│     → Reports created/skipped counts + errors               │
└─────────────────────────────────────────────────────────────┘
```

### Role Assignment Security

```php
// Only superadmin can assign hr or superadmin roles
function safeRole($role) {
    if (in_array($role, ['hr', 'superadmin'])) {
        if (!auth()->user()->hasRole('superadmin')) {
            return 'employee';  // Fallback
        }
    }
    return $role;
}
```

---

## 12. Payroll System

### Files Involved

| File | Purpose |
|------|---------|
| `app/Http/Controllers/PayrollController.php` | Payroll operations |
| `app/Services/PayrollCalculator.php` | Calculation engine |
| `app/Models/PayrollPeriod.php` | Payroll periods |
| `app/Models/PayrollItem.php` | Per-employee calculations |
| `app/Models/Payslip.php` | Payslip records |
| `app/Models/ContributionRate.php` | SSS/PhilHealth/Pag-IBIG rates |
| `config/payroll.php` | Configuration |

### Payroll Periods

```
Semi-Monthly Periods:
├── First Half:  1st – 15th
└── Second Half: 16th – end of month

Status Flow:
┌──────┐    ┌────────────┐    ┌─────────┐
│ Open │──▶ │ Processing │──▶ │ Closed  │
└──────┘    └────────────┘    └─────────┘
```

### Calculation Flow

```
┌─────────────────────────────────────────────────────────────┐
│  PAYROLL CALCULATION                                        │
│  PayrollCalculator::calculate(employee, period)             │
│                                                             │
│  For each active employee in period:                        │
│                                                             │
│  1. BASIC PAY                                               │
│     └─ monthly_salary / 2 (semi-monthly)                    │
│                                                             │
│  2. ABSENCES (fixed-schedule only)                          │
│     └─ (expected_days − worked_days − approved_full_leave) │
│        × daily_rate                                         │
│                                                             │
│  3. HALF-DAY DEDUCTION                                      │
│     └─ approved_half_days × daily_rate × 0.5               │
│                                                             │
│  4. OVERTIME PAY                                            │
│     └─ Σ(approved OT hours × hourly_rate × multiplier)     │
│        • Regular weekday: 125%                              │
│        • Rest day: 130%                                     │
│        • Holiday: 200%                                      │
│                                                             │
│  5. GOVERNMENT CONTRIBUTIONS (employee share)               │
│     ├─ SSS: 5% (range ₱5K–₱35K)                           │
│     ├─ PhilHealth: 2.5% (range ₱10K–₱100K)                │
│     └─ Pag-IBIG: 1% if ≤₱1,500; 2% above (cap ₱200)      │
│                                                             │
│  6. WITHHOLDING TAX                                         │
│     └─ Currently 0 (TRAIN-law brackets placeholder)        │
│                                                             │
│  7. NET PAY                                                 │
│     └─ Gross − Total Deductions                             │
└─────────────────────────────────────────────────────────────┘
```

### Contribution Rates

#### SSS (Social Security System)

| Salary Range | Employee Rate | Employer Rate |
|-------------|---------------|---------------|
| ₱5,000 – ₱35,000 | 5% | 8.5% |

#### PhilHealth

| Salary Range | Employee Rate | Employer Rate |
|-------------|---------------|---------------|
| ₱10,000 – ₱100,000 | 2.5% | 2.5% |

#### Pag-IBIG (HDMF)

| Salary Range | Employee Rate | Employer Rate |
|-------------|---------------|---------------|
| ≤ ₱1,500 | 1% | 2% |
| > ₱1,500 | 2% | 2% |
| **Monthly Cap** | **₱200** | **₱200** |

### Payslip View

```
┌─────────────────────────────────────────────────────────────┐
│  PAYSLIP                                                    │
│  Juan Dela Cruz — EMP-0001                                  │
│  Period: Jul 1 – Jul 15, 2026                               │
│                                                             │
│  EARNINGS                        DEDUCTIONS                 │
│  ├─ Basic Pay:        ₱25,000   ├─ SSS:          ₱1,250   │
│  ├─ Overtime:         ₱2,187.50 ├─ PhilHealth:    ₱625    │
│  └─ Gross Pay:        ₱27,187.50├─ Pag-IBIG:      ₱200    │
│                                 └─ Total Deductions: ₱2,075│
│                                                             │
│  NET PAY: ₱25,112.50                                        │
│                                                             │
│  [ Print Payslip ]                                           │
└─────────────────────────────────────────────────────────────┘
```

---

## 13. Profile Management

### Files Involved

| File | Purpose |
|------|---------|
| `app/Http/Controllers/ProfileController.php` | Profile operations |
| `app/Http/Requests/ProfileUpdateRequest.php` | Validation |
| `resources/views/profile/edit.blade.php` | Profile edit |
| `resources/views/profile/partials/*.blade.php` | Partials |

### Operations

```
┌─────────────────────────────────────────────────────────────┐
│  PROFILE MANAGEMENT                                         │
│                                                             │
│  1. UPDATE PROFILE                                          │
│     PATCH /profile                                          │
│     ├─ Name: [________]                                     │
│     ├─ Email: [________] (resets email_verified_at)         │
│     └─ Profile Photo: [Upload] [Remove]                     │
│                                                             │
│  2. UPDATE PASSWORD                                         │
│     PUT /password                                           │
│     ├─ Current Password: [________]                         │
│     ├─ New Password: [________]                             │
│     └─ Confirm Password: [________]                         │
│                                                             │
│  3. DELETE ACCOUNT                                          │
│     DELETE /profile                                         │
│     └─ Requires password confirmation                       │
│         └─ Logs out, deletes user + photo                   │
└─────────────────────────────────────────────────────────────┘
```

---

## 14. Roles & Permissions

### Permissions (10 Total)

| Permission | Description |
|-----------|-------------|
| `clock attendance` | Self-service punch |
| `request leave` | File leave requests |
| `request overtime` | File OT requests |
| `view own payslip` | See own payslips |
| `approve requests` | Approve/deny leave & OT |
| `view team reports` | Attendance monitor |
| `manage employees` | CRUD employees |
| `run payroll` | Generate payroll |
| `manage settings` | Schedules, sites, leave types |
| `manage users` | Accounts & roles |

### Roles

| Role | Permissions |
|------|------------|
| **employee** | clock attendance, request leave, request overtime, view own payslip |
| **hr** | All self-service + approve requests, view team reports, manage employees, run payroll, manage settings |
| **superadmin** | All permissions |

### Middleware Enforcement

```
Route Protection:
├── auth + verified — All app routes
├── permission:view team reports — Attendance monitor
├── permission:approve requests — Leave/OT approve/deny, attendance verify
├── permission:manage employees — Employee CRUD + import
├── permission:run payroll — Payroll generation, salary history
└── permission:manage settings — System configuration
```

### Role Hierarchy

```
┌─────────────────────────────────────────┐
│            SUPERADMIN                    │
│         (All Permissions)                │
├─────────────────────────────────────────┤
│                 HR                       │
│    approve requests, view team reports   │
│    manage employees, run payroll         │
│    manage settings                       │
├─────────────────────────────────────────┤
│              EMPLOYEE                    │
│    clock attendance, request leave       │
│    request overtime, view own payslip    │
└─────────────────────────────────────────┘
```

---

## 15. Notifications

### Files Involved

| File | Purpose |
|------|---------|
| `app/Notifications/ApprovalRequested.php` | Request notification |
| `app/Notifications/RequestReviewed.php` | Decision notification |
| `app/Http/Controllers/NotificationController.php` | Read management |

### Notification Types

#### ApprovalRequested

```
Trigger: Employee files leave/OT request
Sent to: Users with "approve requests" permission
Channel: Database

Data:
├── kind: "request"
├── title: "New {leave/overtime} request"
├── message: "{Employee Name} — {summary}"
└── url: Review page URL
```

#### RequestReviewed

```
Trigger: Approver approves/denies request
Sent to: Employee whose request was decided
Channel: Database

Data:
├── kind: "approved" | "rejected"
├── title: "{Leave/Overtime} {status}"
├── message: Details of decision
└── url: Index page URL
```

### Notification Flow

```
┌─────────────────────────────────────────────────────────────┐
│  1. Employee files request                                  │
│     └─ Creates ApprovalRequested notification               │
│         └─ Sent to all approvers                            │
│                                                             │
│  2. Approver sees notification in dropdown                  │
│     └─ Clicks to review page                                │
│         └─ NotificationController@open marks as read        │
│                                                             │
│  3. Approver decides                                        │
│     └─ Creates RequestReviewed notification                 │
│         └─ Sent to employee                                 │
│                                                             │
│  4. Employee sees notification                              │
│     └─ Clicks to view decision                              │
└─────────────────────────────────────────────────────────────┘
```

---

## 16. Route Map

### Guest Routes (`routes/auth.php`)

| Method | URI | Controller | Purpose |
|--------|-----|------------|---------|
| `GET` | `/` | Closure | Welcome or redirect |
| `GET` | `/register` | RegisteredUserController@create | Registration form |
| `POST` | `/register` | RegisteredUserController@store | Process registration |
| `GET` | `/login` | AuthenticatedSessionController@create | Login form |
| `POST` | `/login` | AuthenticatedSessionController@store | Process login |
| `GET` | `/forgot-password` | PasswordResetLinkController@create | Forgot password |
| `POST` | `/forgot-password` | PasswordResetLinkController@store | Send reset email |
| `GET` | `/reset-password/{token}` | NewPasswordController@create | Reset form |
| `POST` | `/reset-password` | NewPasswordController@store | Process reset |
| `GET` | `/verify-email` | EmailVerificationPromptController | Verification prompt |
| `GET` | `/verify-email/{id}/{hash}` | VerifyEmailController | Verify email |
| `POST` | `/email/verification-notification` | EmailVerificationNotificationController@store | Resend verification |
| `GET` | `/confirm-password` | ConfirmablePasswordController@show | Password confirm |
| `POST` | `/confirm-password` | ConfirmablePasswordController@store | Confirm password |
| `PUT` | `/password` | PasswordController@update | Update password |
| `POST` | `/logout` | AuthenticatedSessionController@destroy | Logout |

### Authenticated Routes (`routes/web.php`)

| Method | URI | Controller | Middleware | Purpose |
|--------|-----|------------|------------|---------|
| `GET` | `/dashboard` | DashboardController@index | auth,verified | Dashboard |
| `GET` | `/notifications/{id}` | NotificationController@open | auth,verified | Mark read + redirect |
| `POST` | `/notifications/read-all` | NotificationController@readAll | auth,verified | Mark all read |
| `GET` | `/attendance` | AttendanceController@create | auth,verified | Clock in/out screen |
| `POST` | `/attendance` | AttendanceController@store | auth,verified | Save punch |
| `GET` | `/attendance/logs` | AttendanceController@index | auth,verified | Personal history |
| `GET` | `/attendance/{id}/softcopy/{type}` | AttendanceController@softCopy | auth,verified | Download soft copy |
| `GET` | `/attendance/monitor` | AttendanceController@monitor | auth,verified,`view team reports` | HR monitor |
| `GET` | `/leave` | LeaveController@index | auth,verified | Leave list |
| `GET` | `/leave/create` | LeaveController@create | auth,verified | File leave form |
| `POST` | `/leave` | LeaveController@store | auth,verified | Submit leave |
| `GET` | `/leave/early` | LeaveController@earlyCreate | auth,verified | Early leave form |
| `POST` | `/leave/early` | LeaveController@earlyStore | auth,verified | Submit early leave |
| `GET` | `/overtime` | OvertimeController@index | auth,verified | OT list |
| `GET` | `/overtime/create` | OvertimeController@create | auth,verified | File OT form |
| `GET` | `/overtime/preview` | OvertimeController@preview | auth,verified | Live OT calculation |
| `POST` | `/overtime` | OvertimeController@store | auth,verified | Submit OT |
| `GET` | `/payroll/item/{item}` | PayrollController@show | auth,verified | View payslip |
| `POST` | `/leave/{leave}/approve` | LeaveController@approve | auth,verified,`approve requests` | Approve leave |
| `POST` | `/leave/{leave}/deny` | LeaveController@deny | auth,verified,`approve requests` | Deny leave |
| `POST` | `/overtime/{overtime}/approve` | OvertimeController@approve | auth,verified,`approve requests` | Approve OT |
| `POST` | `/overtime/{overtime}/deny` | OvertimeController@deny | auth,verified,`approve requests` | Deny OT |
| `POST` | `/attendance/{log}/verify` | AttendanceController@verify | auth,verified,`approve requests` | Verify long-day OT |
| `POST` | `/attendance/{log}/verify-location` | AttendanceController@verifyLocation | auth,verified,`approve requests` | Approve/reject an out-of-area, no-GPS or low-accuracy punch |
| `GET` | `/employees` | EmployeeController@index | auth,verified,`manage employees` | Employee list |
| `GET` | `/employees/create` | EmployeeController@create | auth,verified,`manage employees` | Add employee form |
| `POST` | `/employees` | EmployeeController@store | auth,verified,`manage employees` | Create employee |
| `GET` | `/employees/import` | EmployeeController@importForm | auth,verified,`manage employees` | Import form |
| `GET` | `/employees/import/template` | EmployeeController@importTemplate | auth,verified,`manage employees` | Download template |
| `POST` | `/employees/import` | EmployeeController@import | auth,verified,`manage employees` | Process import |
| `GET` | `/employees/{employee}/edit` | EmployeeController@edit | auth,verified,`manage employees` | Edit form |
| `PUT` | `/employees/{employee}` | EmployeeController@update | auth,verified,`manage employees` | Update employee |
| `PATCH` | `/employees/{employee}/status` | EmployeeController@toggleStatus | auth,verified,`manage employees` | Toggle status |
| `POST` | `/employees/{employee}/assignments` | ProjectAssignmentController@store | auth,verified,`manage employees` | Assign / reassign to a project site (ends the previous assignment) |
| `PATCH` | `/employees/{employee}/assignments/{assignment}/end` | ProjectAssignmentController@end | auth,verified,`manage employees` | End or cancel an assignment |
| `GET` | `/settings/sites` | SiteController@index | auth,verified,`manage settings` | Attendance locations list |
| `GET` | `/settings/sites/create` | SiteController@create | auth,verified,`manage settings` | Add location form (map picker) |
| `POST` | `/settings/sites` | SiteController@store | auth,verified,`manage settings` | Create location |
| `GET` | `/settings/sites/{site}/edit` | SiteController@edit | auth,verified,`manage settings` | Edit location |
| `PUT` | `/settings/sites/{site}` | SiteController@update | auth,verified,`manage settings` | Update location |
| `PATCH` | `/settings/sites/{site}/status` | SiteController@setStatus | auth,verified,`manage settings` | Activate / deactivate / complete (never delete) |
| `GET` | `/payroll` | PayrollController@index | auth,verified,`run payroll` | Payroll list |
| `POST` | `/payroll/{period}/generate` | PayrollController@generate | auth,verified,`run payroll` | Run payroll |
| `GET` | `/employees/{employee}/salary-history` | PayrollController@salaryHistory | auth,verified,`run payroll` | Salary history |
| `GET` | `/profile` | ProfileController@edit | auth,verified | Edit profile |
| `PATCH` | `/profile` | ProfileController@update | auth,verified | Update profile |
| `DELETE` | `/profile` | ProfileController@destroy | auth,verified | Delete account |

---

## 17. Database Schema

### Tables (25 Migrations)

| Table | Purpose |
|-------|---------|
| `users` | Auth accounts (name, email, password, profile_photo_path) |
| `employees` | HR profiles (linked to users) |
| `schedules` | Work schedules |
| `sites` | Authorized attendance locations (main office, project sites, temporary venues) with geofence, `type`, `status` and an optional activation window — this is the "AttendanceLocation" of section 6 |
| `employee_project_assignments` | Employee-to-project assignment history |
| `attendance_logs` | Clock in/out events with GPS + photo |
| `leave_types` | Leave categories |
| `leave_requests` | Employee leave filings |
| `overtime_requests` | Employee OT filings |
| `payroll_periods` | Semi-monthly cutoffs |
| `payroll_items` | Per-employee payroll computations |
| `payslips` | Generated payslip records |
| `contribution_rates` | SSS/PhilHealth/Pag-IBIG brackets |
| `notifications` | Database notifications |
| `cache` | Laravel cache |
| `jobs` | Laravel queue jobs |
| `permission_roles` | Spatie RBAC |
| `permissions` | Spatie permissions |
| `roles` | Spatie roles |
| `model_has_permissions` | Spatie model permissions |
| `model_has_roles` | Spatie model roles |
| `role_has_permissions` | Spatie role permissions |
| `personal_access_tokens` | Sanctum tokens |

### Entity Relationship Diagram

```
┌─────────────┐         ┌─────────────┐
│    users    │         │  schedules  │
├─────────────┤         ├─────────────┤
│ id          │         │ id          │
│ name        │         │ name        │
│ email       │         │ time_in     │
│ password    │         │ time_out    │
│ profile_    │         │ grace_      │
│ photo_path  │         │ minutes     │
└──────┬──────┘         │ is_flexible │
       │                └──────┬──────┘
       │ 1:1                   │ 1:N
       ▼                       │
┌─────────────┐                │
│  employees  │◄───────────────┘
├─────────────┤
│ id          │
│ user_id     │
│ employee_no │
│ first_name  │
│ last_name   │
│ email       │
│ phone       │
│ employee_   │
│ type        │
│ schedule_id │
│ supervisor_ │ ◄─── self-referencing
│ id          │
│ monthly_    │
│ salary      │
│ daily_rate  │
│ date_hired  │
│ status      │
└──────┬──────┘
       │
       ├──── 1:N ────▶ ┌─────────────────┐
       │                │ attendance_logs  │
       │                ├─────────────────┤
       │                │ id              │
       │                │ employee_id     │
       │                │ site_id         │
       │                │ log_type        │
       │                │ logged_at       │
       │                │ latitude        │
       │                │ longitude       │
       │                │ distance_m      │
       │                │ within_geofence │
       │                │ photo_path      │
       │                │ synced_offline  │
       │                │ ot_verification_│
       │                │ status          │
       │                │ ot_remarks      │
       │                │ ot_verified_by  │
       │                │ ot_verified_at  │
       │                └─────────────────┘
       │
       ├──── 1:N ────▶ ┌─────────────────┐
       │                │ leave_requests  │
       │                ├─────────────────┤
       │                │ id              │
       │                │ employee_id     │
       │                │ leave_type_id   │
       │                │ day_portion     │
       │                │ days            │
       │                │ date_from       │
       │                │ date_to         │
       │                │ reason          │
       │                │ status          │
       │                │ approved_by     │
       │                │ approved_at     │
       │                │ is_early_leave  │
       │                └─────────────────┘
       │
       ├──── 1:N ────▶ ┌─────────────────┐
       │                │overtime_requests│
       │                ├─────────────────┤
       │                │ id              │
       │                │ employee_id     │
       │                │ site_id         │
       │                │ ot_date         │
       │                │ hours           │
       │                │ ot_type         │
       │                │ status          │
       │                │ approved_by     │
       │                │ approved_at     │
       │                └─────────────────┘
       │
       └──── 1:N ────▶ ┌─────────────────┐
                        │  payroll_items  │
                        ├─────────────────┤
                        │ id              │
                        │ employee_id     │
                        │ payroll_period_ │
                        │ id              │
                        │ basic_pay       │
                        │ overtime_pay    │
                        │ night_diff_pay  │
                        │ holiday_pay     │
                        │ gross_pay       │
                        │ late_undertime_ │
                        │ deduction       │
                        │ absences_       │
                        │ deduction       │
                        │ half_day_       │
                        │ deduction       │
                        │ sss_deduction   │
                        │ philhealth_     │
                        │ deduction       │
                        │ pagibig_        │
                        │ deduction       │
                        │ withholding_tax │
                        │ total_deductions│
                        │ net_pay         │
                        └─────────────────┘
                                │
                                │ 1:1
                                ▼
                        ┌─────────────────┐
                        │    payslips     │
                        ├─────────────────┤
                        │ id              │
                        │ payroll_item_id │
                        │ generated_at    │
                        └─────────────────┘

┌─────────────┐         ┌─────────────────┐
│    sites    │         │   leave_types   │
├─────────────┤         ├─────────────────┤
│ id          │         │ id              │
│ name        │         │ name            │
│ client_name │         │ code            │
│ address     │         │ default_days_   │
│ latitude    │         │ per_year        │
│ longitude   │         │ is_paid         │
│ geofence_   │         └─────────────────┘
│ radius_m    │
│ is_head-    │         ┌─────────────────┐
│ quarters    │         │contribution_    │
└─────────────┘         │rates            │
                        ├─────────────────┤
                        │ id              │
                        │ contribution_   │
                        │ type            │
                        │ min_salary      │
                        │ max_salary      │
                        │ employee_rate   │
                        │ employer_rate   │
                        │ ec_amount       │
                        │ effective_year  │
                        └─────────────────┘
```

---

## 18. Support Classes & Services

### WorkSessions (`app/Support/WorkSessions.php`)

```php
// Pair raw logs into sessions
WorkSessions::pair($logs);
// Returns: [['in' => log, 'out' => log|null], ...]

// Filter to sessions starting on a date
WorkSessions::startingOn($sessions, $date);

// Total minutes across closed sessions
WorkSessions::workedMinutes($sessions);

// Check for open sessions (no clock-out)
WorkSessions::hasOpen($sessions);

// Get last closed session's time-out
WorkSessions::closingOut($sessions);
```

### WorkHours (`app/Support/WorkHours.php`)

```php
// Standard workday (8h = 480 min)
WorkHours::standardMinutes(); // 480

// Split into regular vs OT
WorkHours::split($minutes, $restDay);
// Returns: ['regular' => int, 'overtime' => int]

// Check if needs HR verification (≥13h)
WorkHours::needsVerification($minutes); // bool

// Human-readable label
WorkHours::label($minutes); // "8h 30m"
```

### Geo (`app/Support/Geo.php`)

```php
// Haversine distance between two points
Geo::distanceMeters($lat1, $lng1, $lat2, $lng2);
// Returns: float (metres)
```

### AttendanceSoftCopy (`app/Services/AttendanceSoftCopy.php`)

```php
// Generate branded PNG
AttendanceSoftCopy::png($log);
// Returns: string (PNG binary)

// Get status label
AttendanceSoftCopy::status($log);
// Returns: "Early In" | "On Time" | "Late" | "Overtime" | "Undertime" | "Regular"

// Get reference code
AttendanceSoftCopy::reference($log);
// Returns: "ATT-123-IN" or "ATT-123-OUT"
```

### PayrollCalculator (`app/Services/PayrollCalculator.php`)

```php
// Full payroll computation
PayrollCalculator::calculate($employee, $period);
// Returns: PayrollItem with all earnings/deductions populated
```

### GeofenceService (`app/Services/GeofenceService.php`)

```php
// Evaluate a punch against every active site + the employee's assignment.
// Returns a GeofenceResult: status (verified_location | authorized_alternate_location |
// outside_authorized_area | gps_unavailable | low_accuracy), matched site, nearest
// site, distance, and the column values to stamp on the attendance log.
$result = app(GeofenceService::class)->evaluate($employee, $lat, $lng, $accuracy);
$geofence->blocks($result);                    // true only in strict mode for exceptions
$geofence->initialVerificationStatus($result); // 'pending' in approval mode, else null

// Single-site check (distance + inside?)
GeofenceService::check($site, $lat, $lng);
// Returns: ['distance' => float, 'within' => bool]
```

---

## 19. Configuration

### `config/attendance.php`

```php
return [
    // What happens when a punch lands outside EVERY active geofence:
    //   warning   record it, flag it for HR (default)
    //   approval  record it as "pending" until HR approves
    //   strict    reject it
    // ATTENDANCE_ENFORCE_GEOFENCE=true (legacy) maps to "strict".
    'geofence_mode' => env('ATTENDANCE_GEOFENCE_MODE', 'warning'),

    // A GPS fix with a worse accuracy radius than this is "low_accuracy".
    'min_gps_accuracy_m' => env('ATTENDANCE_MIN_GPS_ACCURACY_M', 100),
];
```

### `config/payroll.php`

```php
return [
    // Regular time cap (hours)
    'standard_workday_hours' => env('PAYROLL_STANDARD_WORKDAY_HOURS', 8),

    // HR verification threshold (hours)
    'ot_verification_hours' => env('PAYROLL_OT_VERIFICATION_HOURS', 13),
];
```

---

## Appendix A: Key Business Rules

### Attendance

1. **Double clock-in**: System closes the open session when a second `time_in` arrives without intervening `time_out`
2. **Overnight shifts**: Window extends to next day's end-of-day to pair sessions correctly
3. **Camera requires HTTPS**: `getUserMedia` requires secure context
4. **GPS requires HTTPS**: `watchPosition` requires secure context on non-localhost
5. **Photo timestamp**: Philippine time burned into captured image (tamper-evident)

### Leave

1. **Half-day leave**: Can be filed without a leave type (reason carries context)
2. **Early leave**: Always half-day PM sick leave
3. **Payroll impact**: Approved half days = `0.5 × daily_rate` deduction

### Overtime

1. **Requested in advance**: Employee files the date, planned window and a required reason (what the OT is for); Admin/HR approves or denies with remarks
2. **Hours never typed**: Actual hours are derived from attendance once the employee clocks out, capped at the approved planned hours
3. **Type auto-classified**: From date (weekday vs weekend vs holiday)
4. **Paid one cutoff in arrears**: OT worked 1–15 is paid on the 16–end payroll; OT worked 16–end is paid on the next month's 1–15 payroll (`PayrollPeriod::overtimeWindow()`)

### Payroll

1. **Semi-monthly**: Basic pay = monthly / 2
2. **Absences**: Fixed-schedule only (expected − worked − approved leave)
3. **Government contributions**: Employee share split 50/50 per cutoff

---

## Appendix B: Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_URL` | `http://localhost` | Application URL |
| `DB_HOST` | `127.0.0.1` | Database host |
| `DB_PORT` | `3306` | Database port |
| `DB_DATABASE` | `bt_attendance` | Database name |
| `DB_USERNAME` | `root` | Database user |
| `DB_PASSWORD` | `""` | Database password |
| `ATTENDANCE_GEOFENCE_MODE` | `warning` | `warning` / `approval` / `strict` — behaviour for punches outside every active geofence |
| `ATTENDANCE_MIN_GPS_ACCURACY_M` | `100` | GPS accuracy (m) worse than this is flagged `low_accuracy` |
| `ATTENDANCE_ENFORCE_GEOFENCE` | `false` | Legacy — `true` is treated as `ATTENDANCE_GEOFENCE_MODE=strict` |
| `PAYROLL_STANDARD_WORKDAY_HOURS` | `8` | Regular hours cap |
| `PAYROLL_OT_VERIFICATION_HOURS` | `13` | OT verification threshold |

---

*Document generated for BT-Attendance system.*


### 6.8 Visible Virtual Geofence Circle in Leaflet

The attendance map must display a visible virtual circle around each active attendance location. This circle represents the site’s configured geofence radius and allows employees to understand whether their current GPS position is inside or outside the authorized area.

#### Employee Map Experience

When the employee opens the attendance page:

1. The selected site is displayed using a Leaflet marker.
2. A circle is drawn around the site marker using the site’s configured radius.
3. The employee’s current GPS position is displayed using a separate location marker.
4. The employee can visually compare their current position with the geofence circle.
5. The interface displays the current location status:
   - **Inside the authorized site area**
   - **Outside the authorized site area**
   - **Checking location**
   - **GPS unavailable**
   - **Low GPS accuracy**

The circle must be centered on the saved site coordinates:

```text
attendance_location.latitude
attendance_location.longitude
attendance_location.radius_meters
```

#### Leaflet Implementation

The frontend can use `L.circle()` to draw the geofence:

```javascript
const sitePosition = [
    attendanceLocation.latitude,
    attendanceLocation.longitude
];

const siteRadius = attendanceLocation.radius_meters;

L.marker(sitePosition)
    .addTo(map)
    .bindPopup(attendanceLocation.name);

const siteCircle = L.circle(sitePosition, {
    radius: siteRadius,
    color: '#2563eb',
    fillColor: '#3b82f6',
    fillOpacity: 0.2,
    weight: 2
}).addTo(map);
```

The employee’s GPS marker should be updated whenever a new location is received:

```javascript
let employeeMarker = null;

function updateEmployeeLocation(latitude, longitude) {
    const employeePosition = [latitude, longitude];

    if (!employeeMarker) {
        employeeMarker = L.marker(employeePosition)
            .addTo(map)
            .bindPopup('Your current location');
    } else {
        employeeMarker.setLatLng(employeePosition);
    }

    const distance = map.distance(
        employeePosition,
        sitePosition
    );

    const isInside = distance <= siteRadius;

    const statusElement = document.getElementById('location-status');

    if (isInside) {
        statusElement.textContent =
            'You are inside the authorized site area.';
        statusElement.className = 'inside';
    } else {
        statusElement.textContent =
            'You are outside the authorized site area.';
        statusElement.className = 'outside';
    }
}
```

#### Multiple Active Locations

If multiple authorized attendance locations are active, the map may display a circle for each location: Main Office, active project sites, and temporary sites. Each circle must use its own coordinates and radius. The employee’s position must be compared against all active circles, not only the employee’s assigned project site.

#### Visual Status Rules

| Status | Suggested visual behavior |
|---|---|
| Inside authorized area | Display a positive status message and normal circle |
| Outside authorized area | Display a warning message and retain the circle |
| Checking location | Display a loading message while GPS is being obtained |
| GPS unavailable | Display a location-permission or GPS error |
| Low GPS accuracy | Display a warning requesting the employee to retry |

The visual circle is intended to guide the employee before submitting attendance. It must not replace server-side validation. Laravel must independently calculate the distance using the stored coordinates and radius when the attendance record is submitted.
