<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class AttendanceLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'distance_m' => 'decimal:2',
            'within_geofence' => 'boolean',
            'synced_offline' => 'boolean',
            'ot_verified_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** HR user who verified an unusually long (13h+) day. */
    public function otVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ot_verified_by');
    }
}
