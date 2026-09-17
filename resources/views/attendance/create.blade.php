<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Clock In / Out</h1>
    </x-slot>
    <x-slot name="immersive">1</x-slot>

    {{--
        Camera-first layout (TimeMark-style): the live preview fills the stage and
        everything else — map preview, identity/time block, capture ring — floats
        over it. On phones the stage bleeds to the viewport edges; on larger
        screens it becomes a tall rounded panel.
    --}}
    <div x-data="clockCapture()" class="sm:mx-auto sm:max-w-3xl">
        <form method="POST" action="{{ route('attendance.store') }}" @submit="submitting = true"
              class="relative isolate flex h-dvh flex-col overflow-hidden bg-black text-white sm:h-[calc(100dvh-7.5rem)] sm:min-h-[640px] sm:rounded-2xl sm:shadow-2xl sm:ring-1 sm:ring-white/10">
            @csrf
            <input type="hidden" name="log_type" x-model="logType">
            <input type="hidden" name="latitude" x-model="lat">
            <input type="hidden" name="longitude" x-model="lng">
            <input type="hidden" name="gps_accuracy" x-model="accuracy">
            <input type="hidden" name="photo" x-model="photo">

            {{-- ── Camera stage ─────────────────────────────────────────── --}}
            <video x-ref="video" x-show="!photo" autoplay playsinline muted
                   class="absolute inset-0 h-full w-full object-cover"></video>
            <img x-show="photo" :src="photo" alt="Captured selfie" class="absolute inset-0 h-full w-full object-cover">
            <canvas x-ref="canvas" class="hidden"></canvas>

            <div x-show="cameraError && !photo" x-cloak class="absolute inset-0 flex items-center justify-center bg-slate-900 p-6 text-center">
                <div class="max-w-xs space-y-3">
                    <svg class="mx-auto h-10 w-10 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M9.5 5.5H8a2 2 0 00-2 2v0M4 8.5V17a2 2 0 002 2h11.5M20 15.5V9a2 2 0 00-2-2h-1.5l-1-2h-5"/></svg>
                    <p class="text-sm text-slate-300" x-text="cameraError"></p>
                    <button type="button" @click="startCamera()" class="rounded-full bg-white/10 px-4 py-1.5 text-xs font-semibold text-white hover:bg-white/20">Try again</button>
                </div>
            </div>

            {{-- Scrims so overlays stay legible on any background --}}
            <div class="pointer-events-none absolute inset-x-0 top-0 h-32 bg-linear-to-b from-black/70 to-transparent"></div>
            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-1/2 bg-linear-to-t from-black/60 via-black/30 to-transparent"></div>

            {{-- ── Top bar ──────────────────────────────────────────────── --}}
            <div class="relative z-20 flex items-center justify-between gap-2 px-3 pt-3">
                <a href="{{ route('attendance.index') }}" aria-label="Back to my attendance"
                   class="grid h-9 w-9 place-items-center rounded-full bg-black/50 backdrop-blur hover:bg-black/70">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </a>

                {{-- Time In / Time Out segmented pill --}}
                <div class="flex rounded-full bg-black/55 p-1 backdrop-blur ring-1 ring-white/15">
                    <button type="button" @click="logType = 'time_in'"
                            :class="logType === 'time_in' ? 'bg-accent-500 text-white shadow' : 'text-white/80 hover:text-white'"
                            class="rounded-full px-3 py-1 font-display text-[11px] font-bold uppercase tracking-wider transition-colors">Time In</button>
                    <button type="button" @click="logType = 'time_out'"
                            :class="logType === 'time_out' ? 'bg-accent-500 text-white shadow' : 'text-white/80 hover:text-white'"
                            class="rounded-full px-3 py-1 font-display text-[11px] font-bold uppercase tracking-wider transition-colors">Time Out</button>
                </div>

                {{-- Location status chip --}}
                <button type="button" @click="mapExpanded = !mapExpanded" :title="statusText()"
                        class="flex h-9 items-center gap-1.5 rounded-full bg-black/50 px-2.5 text-xs font-semibold backdrop-blur hover:bg-black/70">
                    <span class="h-2 w-2 rounded-full" :class="dotColor()"></span>
                    <span class="hidden sm:inline" x-text="shortStatus()"></span>
                    <svg class="h-4 w-4 sm:hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6-5.2-6-10a6 6 0 1112 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/></svg>
                </button>
            </div>

            {{-- ── Map preview (tap to expand) ───────────────────────────── --}}
            <div class="relative z-20 mt-2 px-3 transition-all duration-300" :class="mapExpanded ? 'w-full' : 'w-36'">
                <div class="overflow-hidden rounded-lg bg-slate-800 shadow-lg ring-2 ring-white/80 transition-all duration-300"
                     :class="mapExpanded ? 'h-64' : 'h-28'">
                    <div x-ref="map" class="h-full w-full" @click="if (!mapExpanded) { mapExpanded = true; $nextTick(() => refreshMap()); }"></div>
                </div>
                <div class="pointer-events-none absolute inset-x-3 bottom-0 flex items-end justify-between p-1.5">
                    <span class="rounded-md bg-black/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider backdrop-blur" :class="textColor()" x-text="mapCaption()"></span>
                    <button type="button" x-show="mapExpanded" @click.stop="mapExpanded = false; $nextTick(() => refreshMap())"
                            class="pointer-events-auto rounded-md bg-black/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-white backdrop-blur">Close</button>
                </div>
            </div>

            <div class="flex-1"></div>

            {{-- ── Identity / time / location block ─────────────────────── --}}
            <div class="relative z-20 space-y-2 px-4 pb-2">
                <div class="space-y-1.5" style="text-shadow: 0 1px 3px rgba(0,0,0,.8)">
                    <div class="inline-flex overflow-hidden rounded shadow-lg" style="text-shadow:none">
                        <span class="bg-accent-500 px-2 py-0.5 font-display text-xs font-bold text-white" x-text="logType === 'time_in' ? 'Time In' : 'Time Out'"></span>
                        <span class="flex items-baseline gap-1 bg-white px-2 py-0.5 text-gray-900">
                            <span class="font-display text-lg font-extrabold tabular-nums leading-none" x-text="clockHM"></span>
                            <span class="text-[10px] font-bold text-brand-600" x-text="clockAP"></span>
                        </span>
                    </div>

                    <div class="border-l-[3px] border-brand-300 pl-2 text-xs leading-snug">
                        <p class="font-semibold" x-text="clockDate"></p>
                        <p class="line-clamp-1 text-white/90" x-text="addressText()"></p>
                        <p class="text-[10px] text-white/60" x-show="lat">
                            <span x-text="lat"></span>, <span x-text="lng"></span><span x-show="accuracy !== ''"> · ±<span x-text="accuracy"></span> m</span>
                        </p>
                        <p class="mt-0.5 flex items-center gap-1 text-[11px] font-medium" :class="textColor()">
                            <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/></svg>
                            <span x-text="verificationText()"></span>
                        </p>
                    </div>
                </div>

                {{-- Exception sheet: outside every geofence, weak fix, or no GPS --}}
                <div x-show="isException()" x-cloak
                     class="space-y-1.5 rounded-lg bg-slate-900/90 p-2.5 text-[11px] text-white shadow-xl ring-1 backdrop-blur"
                     :class="strict ? 'ring-rose-400/70' : 'ring-accent-400/70'">
                    <p class="text-xs font-semibold" :class="strict ? 'text-rose-300' : 'text-accent-300'" x-text="exceptionTitle()"></p>
                    <p class="text-white/80">
                        <template x-if="strict"><span>Punches outside every authorized area are not accepted — move inside a site and retry.</span></template>
                        <template x-if="!strict"><span>If you're at an approved temporary location or GPS is inaccurate, add a reason — HR will be notified<span x-show="mode === 'approval'"> and this punch stays <strong>pending</strong> until approved</span>.</span></template>
                    </p>
                    <template x-if="!strict">
                        <textarea name="location_reason" x-model="reason" rows="1" maxlength="500" :required="isException()"
                                  placeholder="Reason for HR — e.g. at client's warehouse today; GPS drifts indoors"
                                  class="w-full rounded-md border-white/20 bg-white/10 text-xs text-white placeholder:text-white/40 focus:border-brand-300 focus:ring-brand-300">{{ old('location_reason') }}</textarea>
                    </template>
                    @error('location_reason') <p class="text-rose-300">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="getLocation()" class="rounded-full border border-white/30 px-3 py-1 font-semibold hover:bg-white/10">Retry GPS</button>
                        <a href="{{ route('leave.early.create') }}" class="rounded-full border border-white/30 px-3 py-1 font-semibold hover:bg-white/10">Contact HR</a>
                    </div>
                </div>

                @error('photo') <p class="rounded-lg bg-rose-600/90 px-3 py-2 text-xs font-medium">{{ $message }}</p> @enderror
                @error('latitude') <p class="rounded-lg bg-rose-600/90 px-3 py-2 text-xs font-medium">{{ $message }}</p> @enderror
            </div>

            {{-- ── Controls ─────────────────────────────────────────────── --}}
            <div class="relative z-20 bg-black/35 pt-1.5 backdrop-blur-sm" style="padding-bottom: max(env(safe-area-inset-bottom), 0.375rem)">
                <p class="min-h-[14px] text-center text-[10px] transition-colors" :class="canSubmit() ? 'text-emerald-300' : (nudged ? 'text-accent-300 font-semibold' : 'text-white/60')" x-text="submitHint()"></p>

                <div class="mt-0.5 grid grid-cols-3 items-center px-6">
                    {{-- Left: retake thumbnail / retry GPS --}}
                    <div class="flex justify-start">
                        <button type="button" x-show="photo" @click="retake()" class="flex flex-col items-center gap-0.5 text-[10px] font-medium text-white/80 hover:text-white">
                            <img :src="photo" alt="" class="h-10 w-10 rounded-md object-cover ring-2 ring-white/70">
                            Retake
                        </button>
                        <button type="button" x-show="!photo" @click="getLocation()" class="flex flex-col items-center gap-0.5 text-[10px] font-medium text-white/80 hover:text-white">
                            <span class="grid h-10 w-10 place-items-center rounded-full bg-white/10 ring-1 ring-white/20">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M20 9A8 8 0 006 5.3M4 15a8 8 0 0014 3.7"/></svg>
                            </span>
                            Retry GPS
                        </button>
                    </div>

                    {{-- Centre: capture ring → confirm button --}}
                    <div class="flex justify-center">
                        <button type="button" x-show="!photo" @click="capture()" :disabled="!!cameraError" aria-label="Capture photo"
                                class="grid h-16 w-16 place-items-center rounded-full border-4 border-white bg-transparent transition active:scale-95 disabled:opacity-40">
                            <span class="h-12 w-12 rounded-full bg-white/90"></span>
                        </button>
                        <button type="submit" x-show="photo" x-cloak :disabled="submitting"
                                @click="if (!canSubmit()) { $event.preventDefault(); nudge(); }"
                                :class="canSubmit() ? '' : 'opacity-60'"
                                class="grid h-16 w-16 place-items-center rounded-full border-4 border-white bg-linear-to-br from-brand-500 to-accent-500 shadow-lg transition active:scale-95 disabled:cursor-not-allowed">
                            <svg x-show="!submitting" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <svg x-show="submitting" class="h-6 w-6 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        </button>
                    </div>

                    {{-- Right: recenter map --}}
                    <div class="flex justify-end">
                        <button type="button" @click="recenter()" :disabled="!lat" class="flex flex-col items-center gap-0.5 text-[10px] font-medium text-white/80 hover:text-white disabled:opacity-40">
                            <span class="grid h-10 w-10 place-items-center rounded-full bg-white/10 ring-1 ring-white/20">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6-5.2-6-10a6 6 0 1112 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/></svg>
                            </span>
                            Locate
                        </button>
                    </div>
                </div>

                <p class="mt-0.5 text-center font-display text-xs font-bold" x-text="photo ? (logType === 'time_in' ? 'Confirm Time In' : 'Confirm Time Out') : 'Take selfie'"></p>

                {{-- Supporting navigation --}}
                <nav class="mt-1.5 flex items-center justify-center gap-5 border-t border-white/10 pt-1.5 text-[10px] font-semibold uppercase tracking-wider text-white/60">
                    <a href="{{ route('attendance.index') }}" class="hover:text-white">History</a>
                    <span class="text-white">Camera</span>
                    <a href="{{ route('leave.early.create') }}" class="hover:text-white">Early leave</a>
                    @can('view team reports')
                        <a href="{{ route('attendance.monitor') }}" class="hover:text-white">Monitor</a>
                    @endcan
                </nav>
            </div>
        </form>
    </div>

    <script>
        function clockCapture() {
            return {
                logType: @json($nextAction),
                sites: @json($sites),
                assignedSiteId: @json($assignedSiteId),
                mode: @json($geofenceMode),          // warning | approval | strict
                strict: @json($geofenceMode === 'strict'),
                minAccuracy: @json($minAccuracy),    // metres; worse than this = low_accuracy
                lat: '', lng: '', accuracy: '',
                photo: '',
                reason: @json(old('location_reason', '')),
                stream: null,
                cameraError: '',
                submitting: false,
                mapExpanded: false,

                // Live Philippine-time clock; frozen at the moment of capture.
                clockDate: '', clockHM: '', clockAP: '', clockTimer: null,

                // Location status (client-side preview; the server is the source of truth).
                // checking | verified_location | authorized_alternate_location |
                // outside_authorized_area | low_accuracy | gps_unavailable
                locStatus: 'checking',
                geoError: '',
                matchedSite: null, nearestSiteName: '', nearestDistance: null,

                // Leaflet state
                map: null, userMarker: null, accCircle: null, siteLayers: {},
                watchId: null, centeredOnce: false, checkTimer: null, nudged: false,

                init() {
                    this.tickClock();
                    this.clockTimer = setInterval(() => { if (!this.photo) this.tickClock(); }, 1000);
                    this.initMap();
                    this.getLocation();
                    this.startCamera();
                },

                // Current Philippine time, independent of the device's own timezone.
                tickClock() {
                    const d = new Date();
                    this.clockDate = d.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', timeZone: 'Asia/Manila' });
                    const t = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'Asia/Manila' });
                    [this.clockHM, this.clockAP] = t.split(' ');
                },

                initMap() {
                    // Default view until a GPS fix arrives (main office, Sampaloc)
                    this.map = L.map(this.$refs.map, { zoomControl: false, attributionControl: false })
                                .setView([14.6108, 121.0049], 15);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(this.map);
                    setTimeout(() => this.map.invalidateSize(), 200);

                    // Every ACTIVE attendance location: a marker at the saved centre and
                    // a circle of its configured radius. The assigned project is
                    // highlighted; the office is a distinct tone. Display-only — no
                    // tap-to-set, so the pin can't be faked.
                    this.sites.forEach((s) => {
                        const assigned = s.id === this.assignedSiteId;
                        const office = s.type === 'office';
                        const color = assigned ? '#ea6c44' : (office ? '#2563eb' : '#4a9bb5');
                        const circle = L.circle([+s.latitude, +s.longitude], {
                            radius: +s.geofence_radius_m,
                            color, weight: assigned ? 2 : 1.5,
                            fillColor: assigned ? '#f7a88a' : (office ? '#93c5fd' : '#7ec8e3'),
                            fillOpacity: 0.2,
                        }).addTo(this.map);
                        L.marker([+s.latitude, +s.longitude], { icon: this.siteIcon(color), keyboard: false })
                            .addTo(this.map)
                            .bindTooltip(s.name, { direction: 'top', offset: [0, -6] });
                        this.siteLayers[s.id] = circle;
                    });

                    const focus = this.sites.find(s => s.id === this.assignedSiteId);
                    if (focus) {
                        this.map.setView([+focus.latitude, +focus.longitude], 16);
                    } else if (this.sites.length) {
                        this.map.fitBounds(L.featureGroup(Object.values(this.siteLayers)).getBounds().pad(0.2));
                    }
                },

                refreshMap() {
                    setTimeout(() => {
                        this.map.invalidateSize();
                        if (this.lat) this.map.setView([parseFloat(this.lat), parseFloat(this.lng)], this.mapExpanded ? 17 : 16);
                    }, 320); // after the CSS resize transition
                },

                siteIcon(color) {
                    return L.divIcon({
                        className: '',
                        html: '<div style="width:12px;height:12px;border-radius:9999px;background:' + color + ';border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.25)"></div>',
                        iconSize: [12, 12], iconAnchor: [6, 6],
                    });
                },

                pinIcon() {
                    return L.divIcon({
                        className: '',
                        html: '<div style="width:18px;height:18px;border-radius:9999px;background:#ea6c44;border:3px solid #fff;box-shadow:0 0 0 2px rgba(0,0,0,.25)"></div>',
                        iconSize: [18, 18], iconAnchor: [9, 9],
                    });
                },

                getLocation() {
                    this.geoError = '';
                    if (!navigator.geolocation) {
                        this.locStatus = 'gps_unavailable';
                        this.geoError = 'Geolocation is not supported by this browser.';
                        return;
                    }
                    if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                        this.locStatus = 'gps_unavailable';
                        this.geoError = 'Location needs HTTPS.';
                        return;
                    }
                    this.locStatus = 'checking';
                    clearTimeout(this.checkTimer);
                    this.checkTimer = setTimeout(() => {
                        if (this.locStatus === 'checking' && !this.lat) {
                            this.locStatus = 'gps_unavailable';
                            this.geoError = 'No location fix yet — allow location access or tap Retry GPS.';
                        }
                    }, 25000);
                    if (this.watchId !== null) navigator.geolocation.clearWatch(this.watchId);
                    // watchPosition keeps refining the fix for better accuracy
                    this.watchId = navigator.geolocation.watchPosition(
                        (pos) => this.setLocation(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy),
                        (err) => {
                            if (this.lat) return; // keep the last good fix
                            this.locStatus = 'gps_unavailable';
                            this.geoError = 'Location unavailable: ' + err.message + '.';
                        },
                        { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 }
                    );
                },

                // Live GPS fix only. acc = accuracy in metres. The marker is
                // non-draggable — the employee can't reposition it by hand.
                setLocation(lat, lng, acc) {
                    if (this.photo && this.lat) return; // freeze the evidence once the shot is taken (but accept a late first fix)
                    this.lat = (+lat).toFixed(7);
                    this.lng = (+lng).toFixed(7);
                    this.accuracy = (acc != null) ? Math.round(acc) : '';
                    this.geoError = '';
                    const pt = [lat, lng];

                    if (!this.userMarker) {
                        this.userMarker = L.marker(pt, { draggable: false, keyboard: false, icon: this.pinIcon() }).addTo(this.map);
                    } else {
                        this.userMarker.setLatLng(pt);
                    }

                    if (acc != null) {
                        if (!this.accCircle) {
                            this.accCircle = L.circle(pt, { radius: acc, color: '#ea6c44', weight: 1, fillColor: '#f7a88a', fillOpacity: 0.15 }).addTo(this.map);
                        } else {
                            this.accCircle.setLatLng(pt).setRadius(acc);
                        }
                    }

                    this.evaluateGeofence(lat, lng, acc);

                    if (!this.centeredOnce) {
                        this.centeredOnce = true;
                        this.map.setView(pt, 16);
                    }
                },

                // Metres between two lat/lng points (haversine) — mirrors the server.
                distanceMeters(lat1, lng1, lat2, lng2) {
                    const R = 6371000, rad = Math.PI / 180;
                    const dLat = (lat2 - lat1) * rad, dLng = (lng2 - lng1) * rad;
                    const a = Math.sin(dLat / 2) ** 2 +
                        Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLng / 2) ** 2;
                    return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
                },

                // Compare the fix against ALL active locations (not just the assigned
                // project) and derive the same status the server will.
                evaluateGeofence(lat, lng, acc) {
                    let best = null, bestDist = Infinity;
                    this.sites.forEach((s) => {
                        const d = this.distanceMeters(lat, lng, +s.latitude, +s.longitude);
                        if (d < bestDist) { bestDist = d; best = s; }
                    });
                    this.nearestSiteName = best ? best.name : '';
                    this.nearestDistance = best ? Math.round(bestDist) : null;
                    const inside = !!best && bestDist <= +best.geofence_radius_m;
                    this.matchedSite = inside ? best : null;

                    if (acc != null && acc > this.minAccuracy) {
                        this.locStatus = 'low_accuracy';
                    } else if (!inside) {
                        this.locStatus = 'outside_authorized_area';
                    } else if (this.assignedSiteId && best.id !== this.assignedSiteId) {
                        this.locStatus = 'authorized_alternate_location';
                    } else {
                        this.locStatus = 'verified_location';
                    }
                },

                isException() {
                    return ['outside_authorized_area', 'low_accuracy', 'gps_unavailable'].includes(this.locStatus);
                },

                // ── Copy for the overlays ────────────────────────────────
                addressText() {
                    if (this.locStatus === 'checking') return 'Locating…';
                    if (this.locStatus === 'gps_unavailable') return this.geoError || 'Location unavailable';
                    if (this.matchedSite) return this.matchedSite.name + (this.matchedSite.address ? ' · ' + this.matchedSite.address : '');
                    if (this.nearestSiteName) return this.nearestDistance + ' m from ' + this.nearestSiteName;
                    return 'No registered work site nearby';
                },

                verificationText() {
                    switch (this.locStatus) {
                        case 'verified_location': return 'Verified location · inside ' + this.matchedSite.name;
                        case 'authorized_alternate_location': return 'Authorized alternate location (assigned elsewhere)';
                        case 'outside_authorized_area': return 'Outside authorized area — needs HR review';
                        case 'low_accuracy': return 'Low GPS accuracy (±' + this.accuracy + ' m) — retry for a better fix';
                        case 'gps_unavailable': return 'Location not verified';
                    }
                    return 'Checking location…';
                },

                shortStatus() {
                    return { checking: 'Locating', verified_location: 'Inside site', authorized_alternate_location: 'Alt. site',
                             outside_authorized_area: 'Outside area', low_accuracy: 'Low accuracy', gps_unavailable: 'No GPS' }[this.locStatus] || '';
                },

                mapCaption() {
                    if (this.locStatus === 'checking') return 'Locating…';
                    if (this.locStatus === 'gps_unavailable') return 'No GPS';
                    if (this.matchedSite) return 'Inside ' + this.matchedSite.name;
                    return this.nearestDistance !== null ? 'Outside · ' + this.nearestDistance + ' m' : 'Outside';
                },

                statusText() { return this.verificationText(); },

                textColor() {
                    switch (this.locStatus) {
                        case 'verified_location': return 'text-emerald-300';
                        case 'authorized_alternate_location': return 'text-brand-300';
                        case 'outside_authorized_area': return 'text-rose-300';
                        case 'low_accuracy': return 'text-accent-300';
                        case 'gps_unavailable': return 'text-rose-300';
                    }
                    return 'text-white/70';
                },

                dotColor() {
                    switch (this.locStatus) {
                        case 'verified_location': return 'bg-emerald-400';
                        case 'authorized_alternate_location': return 'bg-brand-300';
                        case 'outside_authorized_area': case 'gps_unavailable': return 'bg-rose-400';
                        case 'low_accuracy': return 'bg-accent-400';
                    }
                    return 'bg-white/60 animate-pulse';
                },

                exceptionTitle() {
                    if (this.locStatus === 'gps_unavailable') return 'We could not get your location.';
                    if (this.locStatus === 'low_accuracy') return 'GPS reading is not accurate enough to confirm the site.';
                    return 'You are outside the authorized attendance area.';
                },

                submitHint() {
                    if (this.submitting) return 'Submitting…';
                    if (!this.photo) return this.cameraError ? 'Camera unavailable.' : '';
                    if (this.locStatus === 'checking') return 'Waiting for your location — allow access or tap “Retry GPS”.';
                    if (this.isException()) {
                        if (this.strict) return 'You must be inside an authorized work site to clock ' + (this.logType === 'time_in' ? 'in.' : 'out.');
                        if (!this.reason.trim()) return 'Add a short reason for HR, then confirm.';
                        return 'This punch will be flagged for HR review.';
                    }
                    return 'Looks good — tap to confirm.';
                },

                // Tapped confirm while not ready: highlight the reason and focus the
                // reason box if that's what's missing.
                nudge() {
                    this.nudged = true;
                    setTimeout(() => this.nudged = false, 2500);
                    if (this.isException() && !this.strict && !this.reason.trim()) {
                        this.$root.querySelector('[name=location_reason]')?.focus();
                    }
                },

                recenter() {
                    if (this.lat) this.map.setView([parseFloat(this.lat), parseFloat(this.lng)], this.mapExpanded ? 18 : 17);
                },

                async startCamera() {
                    this.cameraError = '';
                    // Ask for a frame shaped like the stage so the preview needs
                    // little or no cropping (phones honour this in portrait).
                    const stage = this.$refs.video.parentElement;
                    const portrait = stage.clientHeight > stage.clientWidth;
                    const video = {
                        facingMode: 'user',
                        width: { ideal: portrait ? 1080 : 1280 },
                        height: { ideal: portrait ? 1440 : 720 },
                        aspectRatio: { ideal: stage.clientWidth / stage.clientHeight },
                    };
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({ video, audio: false });
                        this.$refs.video.srcObject = this.stream;
                    } catch (e) {
                        this.cameraError = 'Camera unavailable: ' + e.message + '. On production this requires HTTPS.';
                    }
                },

                stopCamera() {
                    if (this.stream) {
                        this.stream.getTracks().forEach(t => t.stop());
                        this.stream = null;
                    }
                },

                capture() {
                    const video = this.$refs.video;
                    if (!video || !video.videoWidth) { this.cameraError = 'Camera not ready yet.'; return; }
                    const canvas = this.$refs.canvas;

                    // The preview fills the stage (object-cover), so save exactly what
                    // was on screen: crop the frame to the stage's aspect, centred —
                    // a 16:9 laptop feed in a portrait stage is trimmed at the sides,
                    // a phone's 3:4 feed is barely touched.
                    const stage = video.parentElement;
                    const stageRatio = stage.clientWidth / stage.clientHeight;
                    let sw = video.videoWidth, sh = video.videoHeight;
                    if (sw / sh > stageRatio) sw = Math.round(sh * stageRatio);
                    else sh = Math.round(sw / stageRatio);
                    const sx = Math.round((video.videoWidth - sw) / 2);
                    const sy = Math.round((video.videoHeight - sh) / 2);

                    canvas.width = sw;
                    canvas.height = sh;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
                    this.tickClock(); // freeze the displayed time at the moment of capture
                    this.photo = canvas.toDataURL('image/jpeg', 0.8);
                    this.stopCamera();
                },

                retake() {
                    this.photo = '';
                    this.tickClock();
                    this.startCamera();
                },

                canSubmit() {
                    if (!this.photo) return false;
                    if (this.locStatus === 'checking') return false;
                    if (this.isException()) {
                        // Strict mode: block a submit we know the server will reject.
                        if (this.strict) return false;
                        // Otherwise HR needs the employee's explanation.
                        return this.reason.trim().length > 0;
                    }
                    return !!(this.lat && this.lng);
                },
            };
        }
    </script>
</x-app-layout>
