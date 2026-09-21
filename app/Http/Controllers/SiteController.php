<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

/**
 * Authorized attendance locations (main office, project sites, temporary
 * venues). Sites are never deleted — a finished project is marked completed so
 * every historical punch still points at the place it was recorded.
 */
class SiteController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->string('status')->toString();

        $sites = Site::query()
            ->withCount([
                'assignments as active_assignments_count' => fn ($q) => $q->activeOn(),
                'attendanceLogs',
            ])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByRaw("type = 'office' desc")
            ->orderByRaw("status = 'active' desc")
            ->orderBy('name')
            ->get();

        $all = Site::query()->withCount(['assignments as active_assignments_count' => fn ($q) => $q->activeOn()])->get();
        $stats = [
            'live' => $all->filter(fn ($s) => $s->status === 'active' && $s->type !== 'office')->count(),
            'deployed' => (int) $all->sum('active_assignments_count'),
            'ending' => $all->filter(fn ($s) => $s->status === 'active' && $s->active_until && $s->active_until->between(now(), now()->addDays(14)))->count(),
            'finished' => $all->filter(fn ($s) => $s->status !== 'active')->count(),
        ];

        return view('sites.index', compact('sites', 'status', 'stats'));
    }

    /**
     * Turn a Google Maps link into coordinates. Short links (maps.app.goo.gl,
     * goo.gl/maps) only reveal the place after a redirect, which the browser
     * cannot follow cross-origin — so the server does it and returns the
     * coordinates found in the final URL.
     */
    public function resolveLink(Request $request)
    {
        $url = trim((string) $request->query('url', ''));
        abort_unless(preg_match('#^https?://#i', $url), 422, 'Not a link.');

        $final = $url;
        try {
            $response = Http::withOptions(['allow_redirects' => ['track_redirects' => true, 'max' => 5]])
                ->timeout(8)->withUserAgent('BT-Attendance/1.0')->get($url);
            // Guzzle lists every hop in this header; the last one is the real place URL.
            $hops = array_filter(array_map('trim', explode(', ', (string) $response->header('X-Guzzle-Redirect-History'))));
            $final = $hops ? end($hops) : ((string) ($response->transferStats?->getEffectiveUri() ?? $url));
        } catch (\Throwable) {
            // Fall through and try to parse the original URL.
        }

        $coords = self::coordsFromUrl($final) ?? self::coordsFromUrl($url);

        return response()->json(['ok' => (bool) $coords, 'url' => $final, 'lat' => $coords[0] ?? null, 'lng' => $coords[1] ?? null]);
    }

    /** @return array{0: float, 1: float}|null */
    public static function coordsFromUrl(string $url): ?array
    {
        $url = urldecode($url);
        $patterns = [
            '/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/',          // place pin: ...!3d14.61!4d121.00
            '/[?&](?:q|query|ll|center|destination)=(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/', // ?q=14.61,121.00
            '/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/',               // .../@14.61,121.00,17z
            '/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', // plain "14.61, 121.00"
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $url, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                    return [$lat, $lng];
                }
            }
        }

        return null;
    }

    public function create()
    {
        return view('sites.create');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        $site = Site::create($data);

        return redirect()->route('sites.index')->with('status', "{$site->name} added as an attendance location.");
    }

    public function edit(Site $site)
    {
        $site->loadCount([
            'assignments as active_assignments_count' => fn ($q) => $q->activeOn(),
            'attendanceLogs',
        ]);

        return view('sites.edit', compact('site'));
    }

    public function update(Request $request, Site $site)
    {
        $data = $this->validated($request, $site);
        $data['updated_by'] = $request->user()->id;

        $site->update($data);

        return redirect()->route('sites.index')->with('status', "{$site->name} updated.");
    }

    /**
     * Change a site's lifecycle status. Completing / deactivating a project
     * closes the geofence for new punches and ends any active assignments to
     * it; history is untouched.
     */
    public function setStatus(Request $request, Site $site)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Site::STATUSES))],
        ]);

        $site->update(['status' => $data['status'], 'updated_by' => $request->user()->id]);

        $ended = 0;
        if ($data['status'] !== 'active') {
            $ended = $site->assignments()->activeOn()->get()->each->end()->count();
        }

        $label = strtolower(Site::STATUSES[$data['status']]);
        $note = $ended ? " {$ended} active assignment(s) ended." : '';

        return back()->with('status', "{$site->name} is now {$label}.{$note}");
    }

    private function validated(Request $request, ?Site $site = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('sites', 'name')->ignore($site?->id)],
            'type' => ['required', Rule::in(array_keys(Site::TYPES))],
            'client_name' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'geofence_radius_m' => ['required', 'integer', 'min:20', 'max:5000'],
            'status' => ['required', Rule::in(array_keys(Site::STATUSES))],
            'active_from' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date', 'after_or_equal:active_from'],
        ]);

        $data['is_headquarters'] = $data['type'] === 'office';

        return $data;
    }
}
