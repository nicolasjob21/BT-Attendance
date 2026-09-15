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
 * time, the coordinates are re-checked against every active attendance
 * location with GeofenceService, and the row is locked so a double-tap or a
 * replay cannot submit twice.
 */
class CheckpointVerifier
{
    public function __construct(
        private GeofenceService $geofence,
        private CheckpointPhoto $photos,
        private CheckpointAudit $audit,
        private CheckpointDispatcher $dispatcher,
    ) {}

    /**
     * @param  array{latitude:?float, longitude:?float, accuracy:?float, photo:string, client_timestamp:?string, network_status:?string}  $input
     *
     * @throws ValidationException when the submission cannot be accepted at all
     */
    public function submit(Checkpoint $checkpoint, Employee $employee, array $input, ?Carbon $now = null): Checkpoint
    {
        $now ??= Carbon::now();

        // Photo must be a decodable live capture before we touch the row.
        if (! isset($input['photo']) || DataUrlPhoto::decode((string) $input['photo']) === null) {
            throw ValidationException::withMessages(['photo' => Checkpoint::RESULTS['photo_missing'].' — take a live photo before submitting.']);
        }

        return DB::transaction(function () use ($checkpoint, $employee, $input, $now) {
            /** @var Checkpoint $cp */
            $cp = Checkpoint::whereKey($checkpoint->id)->lockForUpdate()->with(['campaign', 'site'])->firstOrFail();

            // Unauthorized: not the employee's own checkpoint, or no longer a participant.
            $participant = $cp->employee_id === $employee->id
                && $cp->campaign->participants()->where('employee_id', $employee->id)->exists();
            if (! $participant) {
                $this->audit->checkpoint($cp, 'rejected_submission', $employee->user, ['reason' => 'unauthorized_employee']);
                throw ValidationException::withMessages(['checkpoint' => Checkpoint::RESULTS['unauthorized_employee'].'.']);
            }

            if ($cp->campaign->status !== CheckpointCampaign::ACTIVE) {
                throw ValidationException::withMessages(['checkpoint' => 'This checkpoint campaign is no longer active.']);
            }

            if (in_array($cp->verification_status, [Checkpoint::VERIFIED, Checkpoint::FAILED, Checkpoint::PENDING_REVIEW, Checkpoint::SUBMITTED], true)) {
                $this->audit->checkpoint($cp, 'rejected_submission', $employee->user, ['reason' => 'duplicate_submission']);
                throw ValidationException::withMessages(['checkpoint' => Checkpoint::RESULTS['duplicate_submission'].' — this checkpoint was already submitted.']);
            }

            if ($cp->verification_status !== Checkpoint::OPEN || $cp->isExpired($now)) {
                if ($cp->verification_status === Checkpoint::OPEN) {
                    // The sweep has not run yet: record the late attempt as expired.
                    $cp->update([
                        'verification_status' => Checkpoint::EXPIRED,
                        'failure_reason' => 'checkpoint_expired',
                        'validation_message' => 'A submission arrived at '.$now->format('g:i:s A').', after the checkpoint expired at '.$cp->expires_at?->format('g:i A').'.',
                        'review_status' => 'pending',
                        'server_timestamp' => $now,
                        'client_timestamp' => $this->clientTime($input['client_timestamp'] ?? null),
                        'network_status' => $input['network_status'] ?? null,
                    ]);
                    $this->audit->checkpoint($cp, 'late_submission', $employee->user);
                    $this->dispatcher->notifyReviewers($cp);
                }
                throw ValidationException::withMessages(['checkpoint' => Checkpoint::RESULTS['checkpoint_expired'].' — the response window has closed. You may add an explanation for HR.']);
            }

            $lat = $input['latitude'] ?? null;
            $lng = $input['longitude'] ?? null;
            $acc = $input['accuracy'] ?? null;

            $outcome = $this->evaluate($cp, $employee, $lat, $lng, $acc, $now);

            $photoPath = $this->photos->store($cp, (string) $input['photo'], $outcome['gps_label']);
            if ($photoPath === null) {
                throw ValidationException::withMessages(['photo' => Checkpoint::RESULTS['photo_missing'].'.']);
            }

            $cp->update([
                'verification_status' => $outcome['status'],
                'failure_reason' => $outcome['reason'],
                'validation_message' => $outcome['message'],
                'submitted_at' => $now,
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
                'review_status' => $outcome['status'] === Checkpoint::VERIFIED ? null : 'pending',
            ]);

            $this->audit->checkpoint($cp, 'submitted', $employee->user, [
                'result' => $outcome['reason'] ?? 'verified_presence',
                'distance_m' => $outcome['distance'],
            ]);

            if ($cp->isException()) {
                $this->dispatcher->notifyReviewers($cp);
            }

            return $cp;
        });
    }

    /**
     * Decide the checkpoint outcome from the fix. Uses the same policy as
     * attendance (every active site counts as authorized), then requires the
     * fix to be inside THIS campaign's project site for a clean "verified".
     *
     * @return array{status:string, reason:?string, message:string, distance:?float, within:?bool, matched_site_id:?int, gps_label:string}
     */
    public function evaluate(Checkpoint $cp, Employee $employee, ?float $lat, ?float $lng, ?float $acc, Carbon $now): array
    {
        $site = $cp->site;
        $campaignDistance = ($lat !== null && $lng !== null && $site)
            ? round($this->geofence->distanceMeters($lat, $lng, (float) $site->latitude, (float) $site->longitude), 2)
            : null;

        if ($lat === null || $lng === null) {
            return [
                'status' => Checkpoint::PENDING_REVIEW, 'reason' => 'gps_unavailable',
                'message' => 'Location was not available on the device, so presence at '.($site?->name ?? 'the site').' could not be verified.',
                'distance' => null, 'within' => null, 'matched_site_id' => null, 'gps_label' => 'Unavailable',
            ];
        }

        $sites = Site::query()->activeOn($now)->get();
        if ($site && ! $sites->contains('id', $site->id)) {
            $sites->push($site); // the campaign site is authoritative even if its window lapsed
        }
        $result = $this->geofence->evaluate($employee, $lat, $lng, $acc, $sites, $now);
        $withinCampaignSite = $site && $campaignDistance !== null && $campaignDistance <= (float) $site->geofence_radius_m;

        if ($result->status === GeofenceService::LOW_ACCURACY) {
            return [
                'status' => Checkpoint::PENDING_REVIEW, 'reason' => 'low_gps_accuracy',
                'message' => 'GPS accuracy was ±'.number_format((float) $acc).' m — too weak to confirm presence'
                    .($withinCampaignSite ? ' (fix landed inside '.$site->name.').' : ' ('.number_format((float) $campaignDistance).' m from '.$site?->name.').'),
                'distance' => $campaignDistance, 'within' => $withinCampaignSite,
                'matched_site_id' => $result->site?->id, 'gps_label' => 'Low accuracy ±'.number_format((float) $acc).' m',
            ];
        }

        if ($withinCampaignSite) {
            return [
                'status' => Checkpoint::VERIFIED, 'reason' => null,
                'message' => 'Verified presence — inside the '.$site->name.' geofence ('.number_format((float) $campaignDistance).' m from centre).',
                'distance' => $campaignDistance, 'within' => true,
                'matched_site_id' => $site->id, 'gps_label' => 'Verified · '.number_format((float) $campaignDistance).' m',
            ];
        }

        if ($result->within && $result->site) {
            // Inside another authorized location (e.g. head office). Not what
            // the campaign is checking for, but not an unexplained absence either.
            return [
                'status' => Checkpoint::PENDING_REVIEW, 'reason' => 'outside_geofence',
                'message' => 'At '.$result->site->name.' (an authorized location), '.number_format((float) $campaignDistance).' m from '.$site?->name.'.',
                'distance' => $campaignDistance, 'within' => false,
                'matched_site_id' => $result->site->id, 'gps_label' => 'Alt. site · '.$result->site->name,
            ];
        }

        return [
            'status' => Checkpoint::FAILED, 'reason' => 'outside_geofence',
            'message' => 'Outside the '.($site?->name ?? 'site').' geofence — about '.number_format((float) $campaignDistance).' m from the site centre (radius '.(int) ($site?->geofence_radius_m ?? 0).' m).',
            'distance' => $campaignDistance, 'within' => false,
            'matched_site_id' => null, 'gps_label' => 'Outside · '.number_format((float) $campaignDistance).' m',
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
