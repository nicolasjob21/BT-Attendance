<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date_hired' => 'date',
            'monthly_salary' => 'decimal:2',
            'daily_rate' => 'decimal:2',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function projectAssignments(): HasMany
    {
        return $this->hasMany(EmployeeProjectAssignment::class)->latest('start_date');
    }

    /**
     * The project assignment in force today, if any. Null is a valid state
     * (office staff, between projects) and still allows main-office attendance.
     */
    public function activeAssignment(): HasOne
    {
        return $this->hasOne(EmployeeProjectAssignment::class)
            ->ofMany(['start_date' => 'max', 'id' => 'max'], fn ($q) => $q->activeOn());
    }

    /** Site the employee is currently deployed to, or null. */
    public function assignedSite(): ?Site
    {
        return $this->activeAssignment?->site;
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }
}
