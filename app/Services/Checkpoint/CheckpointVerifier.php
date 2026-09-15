<?php

namespace App\Services\Checkpoint;

use App\Models\Checkpoint;
use App\Models\CheckpointCampaign;
use App\Models\Employee;
use App\Models\Site;
use App\Services\GeofenceService;
use App\Support\DataUrlPhoto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Server-side validation of a checkpoint submission.
 *
 * Nothing the client computed is trusted: the server clock is the official
 * time, the window is the campaign's shared start/deadline, the coordinates
 * are re-checked against every active attendance location, and the row is
 * locked so a double-tap cannot submit twice. A failed attempt (outside the
 * fence, no GPS) may be retried while the window is open — only a
 * successful response closes the checkpoint for that employee.
 */
class CheckpointVerifier
{
    public function __construct(
        private GeofenceService $geofence,
        private CheckpointPhoto $photos,
        private CheckpointAudit $audit,
    ) {}

    /**
     * @param  array{latitude:?float, longitude:?float, accuracy:?float, photo:string, client_timestamp:?string, network_status:?string}  $input
     *
     * @throws ValidationException when the submission cannot be accepted at all
     */
    public function submit(Checkpoint $checkpoint, Employee $employee, array $input, ?Carbon $now = null): Checkpoint
    {
        $now ??= Carbon::now();

        if (! isset($input['photo']) || DataUrlPhoto::decode((string) $input['photo']) === null) {
            throw ValidationException::withMessages(['photo' => Checkpoint::RESULTS['photo_missing'].' — take a live photo before submitting.']);
        }

        // Pre-checks run outside the transaction so a refusal (and its audit
        // entry / attempt record) is persisted even though we throw.
        $cp = $checkpoint->fresh(['campaign', 'site']);
        $campaign = $cp->campaign;

        $participant = $cp->employee_id === $employee->id
            && $campaign->participants()->where('employee_id', $employee->id)->exists();
        if (! $participant) {
            $this->audit->checkpoint($cp, 'rejected_submission', $employee->user, ['reason' => 'unauthorized_employee']);
            throw ValidationException::withMessages(['checkpoint' => Checkpoint::RESULTS['unauthorized_employee'].'.']);
        }

        if ($cp->isCompleted()) {
            $this->audit->checkpoint($cp, 'rejected_submission', $employee->user, ['reason' => 'duplicate_submission']);
            throw ValidationException::withMessages(['checkpoint' => Checkpoint::RESULTS['duplicate_submission'].' — you have already completed this checkpoint.']);
        }

        if ($campaign->status === CheckpointCampaign::PAUSED) {
            throw ValidationException::withMessages(['checkpoint' => Checkpoint::RESULTS['checkpoint_paused'].' — HR has paused this checkpoint; wait for it to resume.']);
        }

        // Official window check on the SERVER clock.
        if (! $campaign->acceptsSubmissions($now)) {
            $cp->update([
                'submission_attempts' => $cp->submission_attempts + 1,
                'last_attempt_at' => $now,
                'last_attempt_result' => 'checkpoint_expired',
                'client_timestamp' => $this->clientTime($input['client_timestamp'] ?? null),
                'network_status' => $input['network_status'] ?? null,
            ]);
            $this->audit->checkpoint($cp, 'late_submission', $employee->user, ['at' => $now->toDateTimeString()]);
            throw ValidationException::withMessages(['checkpoint' => Checkpoint::RESULTS['checkpoint_expired'].' — the deadline was '.$campaign->expires_at?->format('g:i A').'. You can add an explanation for HR.']);
        }

        return DB::transaction(function () use ($checkpoint, $employee, $input, $now) {
            /** @var Checkpoint $cp */
            $cp = Checkpoint::whereKey($checkpoint->id)->lockForUpdate()->with(['campaign', 'site'])->firstOrFail();

            // Re-check under the lock: a concurrent request may have completed it.
            if ($cp->isCompleted()) {
                throw ValidationException::withMessages(['checkpoint' => Checkpoint::RESULTS['duplicate_submission'].'.']);
            }

            $lat = $input['latitude'] ?? null;
            $lng = $input['longitude'] ?? null;
            $acc = $input['accuracy'] ?? null;

            $outcome = $this->evaluate($cp, $employee, $lat, $lng, $acc, $now);

            $photoPath = $this->photos->store($cp, (string) $input['photo'], $outcome['gps_label']);
            if ($photoPath === null) {
                throw ValidationException::withMessages(['photo' => Checkpoint::RESULTS['photo_missing'].'.']);
            }

            $accepted = $outcome['status'] === Checkpoint::RESPONDED;

            $cp->update([
                'status' => $outcome['status'],
                'verification_result' => $accepted ? $outcome['verification'] : null,
                'failure_reason' => $accepted ? null : $outcome['reason'],
                'validation_message' => $outcome['message'],
                'submission_attempts' => $cp->submission_attempts + 1,
                'last_attempt_at' => $now,
                'last_attempt_result' => $outcome['reason'] ?? 'verified_presence',
                'submitted_at' => $accepted ? $now : null,
                'server_timestamp' => $now,
                'client_timestamp' => $this->clientTime($input['client_timestamp'] ?? null),
                'network_status' => $input['network_status'] ?? 'online',
                'latitude' => $lat,
                'longitude' => $lng,
                'gps_accuracy_meters' => $acc,
                'distance_from_site_meters' => $outcome['distance'],
                'matched_site_id' => $outcome['matched_site_id'],
                'within_geofence' => $outcome['within'],
                'photo_path' => $photoPath,
                'issue_reported' => $accepted ? null : $cp->issue_reported,
            ]);

            $this->audit->checkpoint($cp, $accepted ? 'submitted' : 'attempt_failed', $employee->user, [
                'result' => $outcome['reason'] ?? 'verified_presence',
                'distance_m' => $outcome['distance'],
                'attempt' => $cp->submission_attempts,
            ]);

            return $cp;
        });
    }

    /**
     * Decide the outcome from the fix. Same policy as attendance (every active
     * site is authorized), then requires the fix inside THIS campaign's site.
     *
     * @return array{status:string, verification:?string, reason:?string, message:string, distance:?float, within:?bool, matched_site_id:?int, gps_label:string}
     */
    public function evaluate(Checkpoint $cp, Employee $employee, ?float $lat, ?float $lng, ?float $acc, Carbon $now): array
    {
        $site = $cp->site;
        $campaignDistance = ($lat !== null && $lng !== null && $site)
            ? round($this->geofence->distanceMeters($lat, $lng, (float) $site->latitude, (float) $site->longitude), 2)
            : null;

        if ($lat === null || $lng === null) {
            return [
                'status' => Checkpoint::GPS_UNAVAILABLE, 'verification' => null, 'reason' => 'gps_unavailable',
                'message' => 'Location was not available on the device, so presence at '.($site?->name ?? 'the site').' could not be verified. You may retry while the checkpoint is open.',
                'distance' => null, 'within' => null, 'matched_site_id' => null, 'gps_label' => 'Unavailable',
            ];
        }

        $sites = Site::query()->activeOn($now)->get();
        if ($site && ! $sites->contains('id', $site->id)) {
            $sites->push($site);
        }
        $result = $this->geofence->evaluate($employee, $lat, $lng, $acc, $sites, $now);
        $withinCampaignSite = $site && $campaignDistance !== null && $campaignDistance <= (float) $site->geofence_radius_m;
        $dist = number_format((float) $campaignDistance);

        if ($withinCampaignSite) {
            if ($result->status === GeofenceService::LOW_ACCURACY) {
                return [
                    'status' => Checkpoint::RESPONDED, 'verification' => Checkpoint::COMPLETED_LOW_ACCURACY, 'reason' => 'low_gps_accuracy',
                    'message' => "Completed inside the {$site->name} geofence ({$dist} m from centre), but GPS accuracy was ±".number_format((float) $acc).' m.',
                    'distance' => $campaignDistance, 'within' => true, 'matched_site_id' => $site->id,
                    'gps_label' => 'Low accuracy ±'.number_format((float) $acc).' m',
                ];
            }

            return [
                'status' => Checkpoint::RESPONDED, 'verification' => Checkpoint::COMPLETED, 'reason' => null,
                'message' => "Verified presence — inside the {$site->name} geofence ({$dist} m from centre).",
                'distance' => $campaignDistance, 'within' => true, 'matched_site_id' => $site->id,
                'gps_label' => "Verified · {$dist} m",
            ];
        }

        if ($result->status === GeofenceService::LOW_ACCURACY) {
            return [
                'status' => Checkpoint::GPS_UNAVAILABLE, 'verification' => null, 'reason' => 'low_gps_accuracy',
                'message' => 'GPS accuracy was ±'.number_format((float) $acc)." m and the fix landed {$dist} m from {$site?->name} — too weak to confirm presence. Retry for a better fix.",
                'distance' => $campaignDistance, 'within' => false, 'matched_site_id' => $result->site?->id,
                'gps_label' => 'Low accuracy ±'.number_format((float) $acc).' m',
            ];
        }

        if ($result->within && $result->site) {
            return [
                'status' => Checkpoint::PENDING_REVIEW, 'verification' => null, 'reason' => 'alternate_location',
                'message' => "At {$result->site->name} (an authorized location), {$dist} m from {$site?->name}. Needs HR review.",
                'distance' => $campaignDistance, 'within' => false, 'matched_site_id' => $result->site->id,
                'gps_label' => 'Alt. site · '.$result->site->name,
            ];
        }

        return [
            'status' => Checkpoint::OUTSIDE_GEOFENCE, 'verification' => null, 'reason' => 'outside_geofence',
            'message' => "Outside the {$site?->name} geofence — about {$dist} m from the site centre (radius ".(int) ($site?->geofence_radius_m ?? 0).' m).',
            'distance' => $campaignDistance, 'within' => false, 'matched_site_id' => null,
            'gps_label' => "Outside · {$dist} m",
        ];
    }

    private function clientTime(?string $iso): ?Carbon
    {
        if (! $iso) {
            return null;
        }
        try {
            return Carbon::parse($iso);
        } catch (\Throwable) {
            return null;
        }
    }
}
