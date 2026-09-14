<?php

namespace App\Notifications;

use App\Models\AttendanceLog;
use App\Services\GeofenceService;
use Illuminate\Notifications\Notification;

/**
 * Sent to HR/approvers when an employee clocks in or out outside every
 * authorized geofence (or with no / weak GPS) so the punch can be reviewed.
 */
class LocationExceptionFlagged extends Notification
{
    public function __construct(public AttendanceLog $log) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $log = $this->log->loadMissing('employee');
        $name = $log->employee?->full_name ?? 'An employee';
        $verb = $log->log_type === 'time_in' ? 'clocked in' : 'clocked out';
        $status = strtolower(GeofenceService::label($log->location_status));

        return [
            'kind' => 'request',
            'title' => 'Attendance location needs review',
            'message' => "{$name} {$verb} at {$log->logged_at->format('g:i A')} — {$status}."
                . ($log->location_reason ? " Reason: “{$log->location_reason}”" : ''),
            'url' => route('attendance.monitor', ['date' => $log->logged_at->toDateString()]),
        ];
    }
}
