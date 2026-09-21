<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    public const PAYOUT_METHODS = ['card' => 'Card (direct to card)', 'cash' => 'Cash (payslip in envelope)'];

    public function paysByCard(): bool
    {
        return $this->payout_method === 'card';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
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

    /**
     * Free-text search: "job", "nicolas", "job nicolas", "EMP-0017", an email
     * or a username all work. Every word typed must match somewhere, so
     * "job nicolas" finds Job Nicolas but not Job Reyes.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $words = preg_split('/\s+/', trim((string) $term), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($words as $word) {
            $query->where(function (Builder $q) use ($word) {
                $like = "%{$word}%";
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('employee_no', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('username', 'like', $like)->orWhere('name', 'like', $like));
            });
        }

        return $query;
    }

    /** Site the employee is currently deployed to, or null. */
    public function assignedSite(): ?Site
    {
        return $this->activeAssignment?->site;
    }

    /**
     * The Checkpoint module is per-project: only employees deployed to a site
     * that has a checkpoint campaign set up for them should see it at all
     * (sidebar link, live alert popup). Reassigning someone off that project
     * takes it away again; their past results stay visible if they open the
     * page directly, this only controls whether it is offered.
     */
    public function hasCheckpointAccess(): bool
    {
        $site = $this->assignedSite();

        if (! $site) {
            return false;
        }

        return CheckpointCampaign::where('project_site_id', $site->id)
            ->whereHas('employees', fn ($q) => $q->whereKey($this->id))
            ->exists();
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

    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class);
    }
}
