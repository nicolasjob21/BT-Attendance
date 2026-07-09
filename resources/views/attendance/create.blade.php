<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Clock In / Out</h1>
    </x-slot>

    <div class="mx-auto max-w-2xl" x-data="clockCapture()" x-init="init()">
        <form method="POST" action="{{ route('attendance.store') }}" @submit="submitting = true">
            @csrf
            <input type="hidden" name="log_type" x-model="logType">
            <input type="hidden" name="latitude" x-model="lat">
            <input type="hidden" name="longitude" x-model="lng">
            <input type="hidden" name="photo" x-model="photo">

            <div class="overflow-hidden card">
                {{-- Action toggle --}}
                <div class="flex border-b border-gray-200 dark:border-slate-700">
                    <button type="button" @click="logType = 'time_in'"
                            :class="logType === 'time_in' ? 'bg-linear-to-r from-brand-600 to-accent-500 text-white' : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/60'"
                            class="flex-1 py-3 text-sm font-semibold transition-colors">Time In</button>
                    <button type="button" @click="logType = 'time_out'"
                            :class="logType === 'time_out' ? 'bg-linear-to-r from-brand-600 to-accent-500 text-white' : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/60'"
                            class="flex-1 py-3 text-sm font-semibold transition-colors">Time Out</button>
                </div>

                <div class="space-y-5 p-5">
                    {{-- Camera / selfie --}}
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Selfie verification</label>
                        <div class="relative aspect-[4/3] overflow-hidden rounded-xs bg-gray-900">
                            <video x-ref="video" x-show="!photo" autoplay playsinline muted class="h-full w-full object-cover"></video>
                            <img x-show="photo" :src="photo" alt="Captured selfie" class="h-full w-full object-cover">
                            <canvas x-ref="canvas" class="hidden"></canvas>

                            <div x-show="cameraError" x-cloak class="absolute inset-0 flex items-center justify-center p-4 text-center text-sm text-gray-300">
                                <span x-text="cameraError"></span>
                            </div>
                        </div>
                        <div class="mt-2 flex gap-2">
                            <button type="button" x-show="!photo" @click="capture()"
                                    class="rounded-xs bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">
                                Capture photo
                            </button>
                            <button type="button" x-show="photo" @click="retake()"
                                    class="rounded-xs border border-gray-300 dark:border-slate-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700/60">
                                Retake
                            </button>
                        </div>
                    </div>

                    {{-- Location --}}
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-slate-200">Location</label>
                        <div class="overflow-hidden rounded-xs border border-gray-200 dark:border-hair">
                            {{-- Leaflet map --}}
                            <div x-ref="map" class="h-60 w-full bg-gray-100 dark:bg-deep"></div>

                            {{-- Status bar --}}
                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 dark:border-hair bg-gray-50 dark:bg-slate-800/60 px-3 py-2 text-sm">
                                <template x-if="lat">
                                    <div class="flex items-center gap-2" :class="onSite ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
                                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/></svg>
                                        <span x-show="sites.length && onSite">At <span x-text="nearestSiteName"></span> <span class="text-gray-500 dark:text-slate-400">(±<span x-text="accuracy"></span> m)</span></span>
                                        <span x-show="sites.length && !onSite">Outside work area <span class="text-gray-500 dark:text-slate-400" x-show="nearestDistance !== null">(<span x-text="nearestDistance"></span> m away)</span></span>
                                        <span x-show="!sites.length">Located <span class="text-gray-500 dark:text-slate-400">(±<span x-text="accuracy"></span> m)</span></span>
                                    </div>
                                </template>
                                <template x-if="!lat">
                                    <span class="text-gray-500 dark:text-slate-400" x-text="geoStatus"></span>
                                </template>
                                <button type="button" x-show="lat" @click="recenter()"
                                        class="inline-flex items-center gap-1 rounded-xs border border-brand-400/60 px-2 py-1 text-xs font-medium text-brand-600 dark:text-brand-300 hover:bg-brand-400/10">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v8m4-4H8M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Recenter
                                </button>
                                <button type="button" x-show="!lat" @click="getLocation()"
                                        class="inline-flex items-center gap-1 rounded-xs border border-brand-400/60 px-2 py-1 text-xs font-medium text-brand-600 dark:text-brand-300 hover:bg-brand-400/10">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M20 9A8 8 0 006 5.3M4 15a8 8 0 0014 3.7"/></svg>
                                    Retry location
                                </button>
                            </div>

                            {{-- Coordinates (recorded for HR review) --}}
                            <template x-if="lat">
                                <div class="border-t border-gray-200 dark:border-hair px-3 py-2 text-xs text-gray-500 dark:text-slate-400">
                                    <span x-text="lat"></span>, <span x-text="lng"></span>
                                </div>
                            </template>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">The pin follows your device's live GPS location — it can't be moved by hand. Make sure location access is allowed and that you're physically at your work site before clocking in.</p>
                    </div>

                    @error('photo') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
                    @error('latitude') <p class="text-sm text-rose-600">Location is required — allow location access.</p> @enderror

                    <button type="submit" :disabled="!canSubmit() || submitting"
                            class="w-full rounded-xs bg-linear-to-r from-brand-600 to-accent-500 py-3 text-sm font-semibold text-white transition-colors hover:from-brand-700 hover:to-accent-600 disabled:cursor-not-allowed disabled:opacity-50">
                        <span x-show="!submitting" x-text="logType === 'time_in' ? 'Confirm Time In' : 'Confirm Time Out'"></span>
                        <span x-show="submitting">Submitting…</span>
                    </button>
                    <p class="text-center text-xs" :class="canSubmit() ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400 dark:text-slate-500'" x-text="submitHint()"></p>
                </div>
            </div>
        </form>

        {{-- Feeling unwell mid-shift? File an early-leave / sick request. --}}
        <p class="mt-4 text-center text-sm text-gray-500 dark:text-slate-400">
            Feeling unwell?
            <a href="{{ route('leave.early.create') }}" class="font-medium text-amber-700 hover:underline dark:text-amber-300">Request to go home early / sick leave</a>
        </p>
    </div>

    <script>
        function clockCapture() {
            return {
                logType: @json($nextAction),
                sites: @json($sites),
                enforceGeofence: @json($enforceGeofence),
                lat: '', lng: '', accuracy: '',
                photo: '',
                stream: null,
                geoStatus: 'Requesting location…',
                cameraError: '',
                submitting: false,

                // Geofence state (client-side hint; the server is the source of truth)
                onSite: false, nearestSiteName: '', nearestDistance: null,

                // Leaflet state
                map: null, userMarker: null, accCircle: null,
                watchId: null, centeredOnce: false,

                init() {
                    this.initMap();
                    this.getLocation();
                    this.startCamera();
                },

                initMap() {
                    // Default view until a GPS fix arrives (Manila)
                    this.map = L.map(this.$refs.map, { zoomControl: true, attributionControl: true })
                                .setView([14.5995, 120.9842], 12);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; OpenStreetMap contributors',
                    }).addTo(this.map);
                    // Leaflet needs a resize tick once the container is laid out
                    setTimeout(() => this.map.invalidateSize(), 200);

                    // Draw each registered work site's geofence so the employee can
                    // see the area they must stand inside. The map is display-only —
                    // there is no tap-to-set or drag, so the pin can't be faked.
                    this.sites.forEach((s) => {
                        L.circle([s.latitude, s.longitude], {
                            radius: s.geofence_radius_m,
                            color: '#4a9bb5', weight: 1,
                            fillColor: '#7ec8e3', fillOpacity: 0.12,
                        }).addTo(this.map).bindTooltip(s.name, { permanent: false });
                    });
                },

                getLocation() {
                    if (!navigator.geolocation) {
                        this.geoStatus = 'Geolocation is not supported by this browser.';
                        return;
                    }
                    if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                        this.geoStatus = 'Location needs a secure page (HTTPS). Open the app over https to clock in.';
                        return;
                    }
                    this.geoStatus = 'Requesting location… allow the permission prompt.';
                    if (this.watchId !== null) navigator.geolocation.clearWatch(this.watchId);
                    // watchPosition keeps refining the fix for better accuracy
                    this.watchId = navigator.geolocation.watchPosition(
                        (pos) => this.setLocation(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy),
                        (err) => { this.geoStatus = 'Location unavailable: ' + err.message + '. Allow location access, then tap “Retry location”.'; },
                        { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 }
                    );
                },

                submitHint() {
                    if (this.submitting) return 'Submitting…';
                    if (!this.photo) return 'Capture a selfie to continue.';
                    if (!this.lat || !this.lng) return 'Waiting for your location — allow access or tap “Retry location”.';
                    if (this.enforceGeofence && this.sites.length && !this.onSite) {
                        return 'You must be at a registered work site to clock ' + (this.logType === 'time_in' ? 'in.' : 'out.');
                    }
                    return 'Ready — tap Confirm ' + (this.logType === 'time_in' ? 'Time In.' : 'Time Out.');
                },

                pinIcon() {
                    return L.divIcon({
                        className: '',
                        html: '<div style="width:18px;height:18px;border-radius:9999px;background:#ea6c44;border:3px solid #fff;box-shadow:0 0 0 2px rgba(0,0,0,.25)"></div>',
                        iconSize: [18, 18], iconAnchor: [9, 9],
                    });
                },

                // Live GPS fix only. acc = accuracy in metres. The marker is
                // non-draggable — the employee can't reposition it by hand.
                setLocation(lat, lng, acc) {
                    this.lat = (+lat).toFixed(7);
                    this.lng = (+lng).toFixed(7);
                    this.accuracy = (acc != null) ? Math.round(acc) : '';
                    this.geoStatus = '';
                    const pt = [lat, lng];

                    if (!this.userMarker) {
                        this.userMarker = L.marker(pt, { draggable: false, keyboard: false, icon: this.pinIcon() }).addTo(this.map);
                    } else {
                        this.userMarker.setLatLng(pt);
                    }

                    // Accuracy ring around the live fix
                    if (acc != null) {
                        if (!this.accCircle) {
                            this.accCircle = L.circle(pt, { radius: acc, color: '#4a9bb5', weight: 1, fillColor: '#7ec8e3', fillOpacity: 0.15 }).addTo(this.map);
                        } else {
                            this.accCircle.setLatLng(pt).setRadius(acc);
                        }
                    } else if (this.accCircle) {
                        this.map.removeLayer(this.accCircle);
                        this.accCircle = null;
                    }

                    this.evaluateGeofence(lat, lng);

                    if (!this.centeredOnce) {
                        this.centeredOnce = true;
                        this.map.setView(pt, 17);
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

                // Work out the nearest site and whether the fix is inside its fence.
                evaluateGeofence(lat, lng) {
                    if (!this.sites.length) { this.onSite = false; this.nearestDistance = null; return; }
                    let best = null, bestDist = Infinity;
                    this.sites.forEach((s) => {
                        const d = this.distanceMeters(lat, lng, +s.latitude, +s.longitude);
                        if (d < bestDist) { bestDist = d; best = s; }
                    });
                    this.nearestSiteName = best.name;
                    this.nearestDistance = Math.round(bestDist);
                    this.onSite = bestDist <= best.geofence_radius_m;
                },

                recenter() {
                    if (this.lat) this.map.setView([parseFloat(this.lat), parseFloat(this.lng)], 18);
                },

                async startCamera() {
                    this.cameraError = '';
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
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
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    canvas.getContext('2d').drawImage(video, 0, 0);
                    this.photo = canvas.toDataURL('image/jpeg', 0.8);
                    this.stopCamera();
                },

                retake() {
                    this.photo = '';
                    this.startCamera();
                },

                canSubmit() {
                    if (!(this.photo && this.lat && this.lng)) return false;
                    // When geofencing is enforced, block a submit we know the server will reject.
                    if (this.enforceGeofence && this.sites.length && !this.onSite) return false;
                    return true;
                },
            };
        }
    </script>
</x-app-layout>
