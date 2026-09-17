@php $site = $site ?? null; @endphp

<div x-data="sitePicker({
        lat: {{ json_encode(old('latitude', $site?->latitude ?? 14.6108)) }},
        lng: {{ json_encode(old('longitude', $site?->longitude ?? 121.0049)) }},
        radius: {{ json_encode((int) old('geofence_radius_m', $site?->geofence_radius_m ?? 150)) }},
        fresh: {{ json_encode(! $site && ! old('latitude')) }},
     })" class="grid gap-5 sm:grid-cols-2">

    <div class="sm:col-span-2">
        <label for="name" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Location name</label>
        <input type="text" id="name" name="name" value="{{ old('name', $site?->name) }}" required placeholder="e.g. Project Site A — Quezon City"
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
        @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="type" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Type</label>
        <select id="type" name="type" required
                class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
            @foreach(\App\Models\Site::TYPES as $value => $label)
                <option value="{{ $value }}" @selected(old('type', $site?->type ?? 'project_site') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">The main office is always a valid place to clock in, even for staff assigned to a project.</p>
        @error('type') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="client_name" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Client <span class="text-gray-400 dark:text-slate-500">(optional)</span></label>
        <input type="text" id="client_name" name="client_name" value="{{ old('client_name', $site?->client_name) }}"
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
    </div>

    <div class="sm:col-span-2">
        <label for="address" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Address <span class="text-gray-400 dark:text-slate-500">(optional)</span></label>
        <input type="text" id="address" name="address" value="{{ old('address', $site?->address) }}"
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
    </div>

    {{-- Map picker: click or drag to set the centre; the circle previews the geofence --}}
    <div class="sm:col-span-2">
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Geofence centre &amp; radius</label>
        <div class="overflow-hidden rounded-xs border border-gray-200 dark:border-hair">
            <div x-ref="map" class="relative z-0 h-72 w-full bg-gray-100 dark:bg-deep"></div>
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 dark:border-hair bg-gray-50 dark:bg-slate-800/60 px-3 py-2 text-xs text-gray-500 dark:text-slate-400">
                <span>Click the map or drag the pin to set the centre. <span x-show="fresh" class="text-amber-700 dark:text-amber-300">Pin is at a default position — move it to the real site.</span></span>
                <button type="button" @click="useMyLocation()" class="btn-app btn-xs btn-outline-brand">Use my current location</button>
            </div>
        </div>
    </div>

    <div>
        <label for="latitude" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Latitude</label>
        <input type="number" step="0.0000001" min="-90" max="90" id="latitude" name="latitude" x-model.lazy="lat" @change="syncFromInputs()" required
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500">
        @error('latitude') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="longitude" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Longitude</label>
        <input type="number" step="0.0000001" min="-180" max="180" id="longitude" name="longitude" x-model.lazy="lng" @change="syncFromInputs()" required
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500">
        @error('longitude') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="geofence_radius_m" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Geofence radius (metres)</label>
        <input type="number" step="10" min="20" max="5000" id="geofence_radius_m" name="geofence_radius_m" x-model.number="radius" @input="syncFromInputs()" required
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500">
        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">150–300 m suits most sites; go wider for large construction areas where GPS drifts.</p>
        @error('geofence_radius_m') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="status" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Status</label>
        <select id="status" name="status" required
                class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500">
            @foreach(\App\Models\Site::STATUSES as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $site?->status ?? 'active') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Only <em>active</em> locations accept new punches.</p>
    </div>

    <div>
        <label for="active_from" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Active from <span class="text-gray-400 dark:text-slate-500">(optional)</span></label>
        <input type="date" id="active_from" name="active_from" value="{{ old('active_from', $site?->active_from?->format('Y-m-d')) }}"
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500 dark:[color-scheme:dark]">
    </div>
    <div>
        <label for="active_until" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Active until <span class="text-gray-400 dark:text-slate-500">(optional)</span></label>
        <input type="date" id="active_until" name="active_until" value="{{ old('active_until', $site?->active_until?->format('Y-m-d')) }}"
               class="w-full rounded-xs border-gray-300 dark:border-slate-600 text-sm focus:border-brand-500 focus:ring-brand-500 dark:[color-scheme:dark]">
        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">Handy for temporary venues (training, a one-week deployment).</p>
        @error('active_until') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </div>
</div>

@once
<script>
    function sitePicker(initial) {
        return {
            lat: (+initial.lat).toFixed(7),
            lng: (+initial.lng).toFixed(7),
            radius: initial.radius,
            fresh: initial.fresh,
            map: null, marker: null, circle: null,

            init() {
                const pt = [parseFloat(this.lat), parseFloat(this.lng)];
                this.map = L.map(this.$refs.map).setView(pt, this.fresh ? 12 : 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19, attribution: '&copy; OpenStreetMap contributors',
                }).addTo(this.map);
                setTimeout(() => this.map.invalidateSize(), 200);

                this.marker = L.marker(pt, { draggable: true }).addTo(this.map);
                this.circle = L.circle(pt, {
                    radius: this.radius, color: '#4a9bb5', weight: 2, fillColor: '#7ec8e3', fillOpacity: 0.18,
                }).addTo(this.map);

                this.marker.on('drag', (e) => this.setPoint(e.target.getLatLng()));
                this.map.on('click', (e) => this.setPoint(e.latlng));
            },

            setPoint(ll) {
                this.fresh = false;
                this.lat = (+ll.lat).toFixed(7);
                this.lng = (+ll.lng).toFixed(7);
                this.marker.setLatLng(ll);
                this.circle.setLatLng(ll);
            },

            syncFromInputs() {
                const lat = parseFloat(this.lat), lng = parseFloat(this.lng);
                if (!isNaN(lat) && !isNaN(lng)) {
                    this.fresh = false;
                    const pt = [lat, lng];
                    this.marker.setLatLng(pt);
                    this.circle.setLatLng(pt);
                    this.map.panTo(pt);
                }
                if (this.radius > 0) this.circle.setRadius(this.radius);
            },

            useMyLocation() {
                if (!navigator.geolocation) return;
                navigator.geolocation.getCurrentPosition((pos) => {
                    this.setPoint({ lat: pos.coords.latitude, lng: pos.coords.longitude });
                    this.map.setView([pos.coords.latitude, pos.coords.longitude], 17);
                }, () => alert('Could not read your location — allow location access and try again.'), { enableHighAccuracy: true, timeout: 15000 });
            },
        };
    }
</script>
@endonce
