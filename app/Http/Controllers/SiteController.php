<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
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

        return view('sites.index', compact('sites', 'status'));
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
