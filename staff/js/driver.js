/**
 * Staff app — Driver. The same flow and server rules as /driver/ and
 * DRIVER-MOBILE-APP, over the same API (api/mobile/fleet):
 *
 *   No trip    -> pick the vehicle, daily check if owed, press Start
 *   On trip    -> GPS fixes are saved on the phone first, then sent in batches;
 *                 Stop works offline and is sent with the remaining fixes.
 *
 * A web page gets GPS only while it is on screen. So during a trip the page
 * asks the phone to keep the screen on, and shows plainly when recording has
 * paused. Everything recorded is kept and sent.
 *
 * Keep the tracking rules in step with DRIVER-MOBILE-APP/src/tracking and
 * driver/app.js: 25 m / 120 s heartbeat, 500-point chunks, offline Stop.
 */
(function () {
  'use strict';

  var S = window.Staff;
  var esc = S.esc;
  var icon = S.icon;
  var button = S.button;
  var clock = S.clock;
  var pad = S.pad;
  var API_BASE = String(S.CFG.fleetBase || '../api/mobile/fleet').replace(/\/+$/, '');

  // -------------------------------------------------------------------------
  // Words
  // -------------------------------------------------------------------------

  var T = {
    title: 'Driver',
    greeting: function (name) { return 'Hello, ' + name; },
    pickTitle: 'Which vehicle are you driving?',
    noVehiclesTitle: 'No vehicles yet',
    noVehiclesBody: 'Ask the office to register the vehicle first.',
    busyWith: function (name) { return 'On a trip with ' + name; },
    start: 'Start trip',
    startSheet: {
      title: 'Start the trip now?',
      body: 'Your route is recorded until you press Stop. Keep this app open on the screen while you drive.',
      confirm: 'Yes, start',
      cancel: 'Not yet',
    },
    onTrip: 'On trip',
    tileOnTrip: function (plate) { return 'On a trip · ' + plate; },
    startedAt: function (time) { return 'Started at ' + time; },
    trackingOn: 'Tracking is on',
    waitingGps: 'Looking for GPS…',
    trackingPaused: 'Tracking paused. Keep this screen open.',
    lastSent: function (time) { return 'Last sent at ' + time; },
    notSentYet: 'Nothing sent yet',
    waiting: function (n) { return n === 1 ? '1 location waiting to send' : n + ' locations waiting to send'; },
    allSent: 'Everything is sent',
    stop: 'Stop trip',
    stopSheet: {
      title: 'Stop the trip?',
      body: 'Recording stops now.',
      confirm: 'Yes, stop',
      cancel: 'Keep driving',
    },
    endedByOffice: 'The office ended your last trip.',
    pickup: 'Morning pickup',
    dropoff: 'Evening drop-off',
    nextStop: function (n, total) { return 'Next stop · ' + n + ' of ' + total; },
    allStopsDone: 'All stops done',
    stopTime: function (time) { return 'Time ' + time; },
    openMaps: 'Open in Maps',
    mapsNote: 'Recording pauses while Maps is open. Come back to this app to carry on.',
    keepOpenTitle: 'Keep this app on the screen',
    keepOpenBody: 'Recording pauses if the phone is locked or you switch to another app. Come back and it carries on.',
    autoLockHint: 'If the screen still turns off: Settings → Display & Brightness → Auto-Lock → Never, while driving.',
    gapNotice: function (from, to) { return 'Recording was paused from ' + from + ' to ' + to + ' while the app was not on screen.'; },
    permission: {
      title: 'Location is blocked',
      bodyIos: 'Allow location for this app, then open it again:',
      stepsIos: [
        'Open the iPhone Settings app',
        'Privacy & Security → Location Services → turn it on',
        'Scroll to Safari Websites → choose "While Using the App"',
      ],
      bodyAndroid: 'Allow location for this app, then open it again:',
      stepsAndroid: [
        'Long-press the Staff icon → App info → Permissions → Location → Allow',
        'Or in Chrome: tap ⋮ → Settings → Site settings → Location → allow this site',
      ],
      servicesOffTitle: 'Your GPS is off',
      servicesOffBody: "Turn on your phone's location, then try again.",
      ok: 'OK',
    },
    check: {
      title: 'Daily check',
      counter: function (n, total) { return n + ' of ' + total; },
      ok: 'OK',
      problem: 'Problem',
      na: 'Not in this vehicle',
      showTitle: 'Show the problem',
      showBody: 'Take a photo, or press the microphone and say what is wrong.',
      photo: 'Photo',
      voice: 'Voice',
      stop: 'Stop',
      recording: 'Recording…',
      writeInstead: 'Or write it here (optional)',
      next: 'Next',
      okAfterAll: 'It is OK',
      needEvidence: 'Take a photo or record your voice first.',
      tooShort: 'Too short. Press the microphone, speak, then press Stop.',
      maxPhotos: 'That is enough photos for this item.',
      micBlocked: 'The microphone is blocked. Allow it for this app in your phone settings, then try again.',
      noMic: 'This phone cannot record voice here. Take a photo instead.',
      kmTitle: 'Kilometres on the dashboard',
      kmBody: 'Type the number you see on the dashboard now.',
      kmHint: function (km) { return 'Last time: ' + km.toLocaleString('en-US') + ' km'; },
      kmPlaceholder: 'e.g. 45210',
      save: 'Save and start trip',
      back: 'Back',
      needKm: 'Type the kilometres from the dashboard.',
      sending: 'Sending…',
      seePhoto: 'see photo / voice',
    },
    genericBody: 'Please try again in a moment.',
    retry: 'Try again',
  };

  // -------------------------------------------------------------------------
  // Storage — kept apart from /driver/'s keys, so the two pages on one phone
  // can never both record the same trip.
  // -------------------------------------------------------------------------

  var KEYS = {
    trip: 'staff.fleet.trip.v1',
    points: 'staff.fleet.points.v1',
    stops: 'staff.fleet.stops.v1',
    lastSent: 'staff.fleet.lastSent.v1',
    ended: 'staff.fleet.endedByOffice.v1',
  };

  /** About 2.5 days of fixes at one every 15 s — past this the oldest go. */
  var MAX_POINTS = 15000;

  var store = {
    trip: function () { return S.readJson(KEYS.trip, null); },
    setTrip: function (t) { S.writeJson(KEYS.trip, t); },
    points: function () { return S.readJson(KEYS.points, []); },
    setPoints: function (p) { S.writeJson(KEYS.points, p.length > MAX_POINTS ? p.slice(p.length - MAX_POINTS) : p); },
    stops: function () { return S.readJson(KEYS.stops, []); },
    setStops: function (s) { S.writeJson(KEYS.stops, s); },
    lastSent: function () { return S.readJson(KEYS.lastSent, null); },
    setLastSent: function (ms) { S.writeJson(KEYS.lastSent, ms); },
    endedByOffice: function () { return S.readJson(KEYS.ended, false); },
    setEndedByOffice: function (v) { S.writeJson(KEYS.ended, !!v); },
  };

  function toActiveTrip(trip) {
    return {
      id: trip.id,
      vehicleId: trip.vehicle_id,
      plateNo: trip.plate_no,
      vehicleName: trip.vehicle_name,
      startedAtMs: trip.started_at_ms,
      routeName: trip.route_name || null,
      direction: trip.direction || null,
      stops: trip.stops || [],
    };
  }

  function signedIn() { return S.hasApp('driver'); }

  function api(path, opts) {
    opts = opts || {};
    return S.request(API_BASE + '/' + path, {
      method: opts.method,
      json: opts.body,
      token: S.token('driver'),
      background: opts.background,
    });
  }

  // -------------------------------------------------------------------------
  // Sending — fixes first, then Stops. Safe to call any time: the server
  // ignores anything it already has, and whatever fails stays for next time.
  // -------------------------------------------------------------------------

  var CHUNK = 500;
  var flushing = null;
  var pointKey = function (p) { return p[0] + ':' + p[6]; };

  function flush() {
    if (flushing) return flushing;
    flushing = doFlush().catch(function () {}).then(function () { flushing = null; S.render(); });
    return flushing;
  }

  async function doFlush() {
    if (!signedIn()) return;

    var points = store.points();
    while (points.length) {
      var tripId = points[0][0];
      var chunk = points.filter(function (p) { return p[0] === tripId; }).slice(0, CHUNK);
      var sent = {};
      chunk.forEach(function (p) { sent[pointKey(p)] = true; });
      var drop = function () {
        // Re-read: fixes may have arrived while this was sending.
        points = store.points().filter(function (p) { return !sent[pointKey(p)]; });
        store.setPoints(points);
      };
      try {
        var result = await api('trips/' + tripId + '/points', {
          method: 'POST',
          body: { points: chunk.map(function (p) {
            return { lat: p[1], lng: p[2], speed: p[3], heading: p[4], accuracy: p[5], t: p[6] };
          }) },
          background: true,
        });
        drop();
        store.setLastSent(Date.now());
        syncActiveTrip(result.trip);
        if (!result.trip_open) tripEndedElsewhere(tripId);
      } catch (e) {
        if (e.kind === 'not_found') {
          // Not this driver's trip (or gone): these fixes can never be delivered.
          points = store.points().filter(function (p) { return p[0] !== tripId; });
          store.setPoints(points);
          continue;
        }
        if (e.kind === 'bad_request') { drop(); continue; } // never going to be accepted
        return; // offline or signed out — later
      }
    }

    var stops = store.stops();
    for (var i = 0; i < stops.length; i++) {
      var stop = stops[i];
      try {
        await api('trips/' + stop.tripId + '/stop', {
          method: 'POST',
          body: { ended_at_ms: stop.endedAtMs },
          background: true,
        });
      } catch (e) {
        if (e.kind !== 'not_found') return;
      }
      store.setStops(store.stops().filter(function (s) { return s.tripId !== stop.tripId; }));
    }
  }

  /** Take the server's latest view of the open trip — which stops are reached. */
  function syncActiveTrip(trip) {
    var active = store.trip();
    if (active && trip && active.id === trip.id && trip.ended_at_ms === null) store.setTrip(toActiveTrip(trip));
  }

  function tripEndedElsewhere(tripId) {
    var active = store.trip();
    if (!active || active.id !== tripId) return;
    stopGps();
    store.setTrip(null);
    store.setEndedByOffice(true);
  }

  // -------------------------------------------------------------------------
  // GPS. watchPosition only runs while the page is on screen.
  // -------------------------------------------------------------------------

  var MIN_MOVE_M = 25;
  var PARKED_HEARTBEAT_MS = 120000;
  /** A browser's first fixes can come from Wi-Fi, hundreds of metres out. */
  var MAX_ACCURACY_M = 150;

  var gps = {
    watchId: null,
    lastFixMs: null,
    lastKept: null,
    error: null, // 'denied' | 'unavailable' | null
    hiddenAtMs: null,
    gap: null, // {from, to} — last pause shown to the driver
  };

  function metres(aLat, aLng, bLat, bLng) {
    var rad = Math.PI / 180;
    var dLat = (bLat - aLat) * rad;
    var dLng = (bLng - aLng) * rad;
    var h = Math.pow(Math.sin(dLat / 2), 2) + Math.cos(aLat * rad) * Math.cos(bLat * rad) * Math.pow(Math.sin(dLng / 2), 2);
    return 2 * 6371000 * Math.asin(Math.min(1, Math.sqrt(h)));
  }

  var round6 = function (n) { return Math.round(n * 1e6) / 1e6; };
  var num = function (n) { return typeof n === 'number' && isFinite(n) ? n : null; };

  function onFix(pos) {
    var trip = store.trip();
    gps.error = null;
    gps.lastFixMs = Date.now();
    if (!trip) return;

    var c = pos.coords;
    if (c.accuracy && c.accuracy > MAX_ACCURACY_M) return;
    // Some Safari versions report the time on a different clock; trust the phone's own when it is off.
    var t = Math.abs((pos.timestamp || 0) - Date.now()) < 600000 ? Math.round(pos.timestamp) : Date.now();

    var last = gps.lastKept;
    if (last && last.tripId === trip.id && t - last.t < PARKED_HEARTBEAT_MS &&
        metres(last.lat, last.lng, c.latitude, c.longitude) < MIN_MOVE_M) {
      return;
    }
    gps.lastKept = { tripId: trip.id, lat: c.latitude, lng: c.longitude, t: t };

    var points = store.points();
    points.push([trip.id, round6(c.latitude), round6(c.longitude), num(c.speed), num(c.heading), num(c.accuracy), t]);
    store.setPoints(points);
  }

  function onGpsError(err) {
    if (err && err.code === 1) {
      gps.error = 'denied';
      stopGps();
    } else if (err && err.code === 2) {
      gps.error = 'unavailable';
    }
    S.render();
  }

  function startGps() {
    if (!('geolocation' in navigator) || gps.watchId !== null) return;
    gps.watchId = navigator.geolocation.watchPosition(onFix, onGpsError, {
      enableHighAccuracy: true,
      maximumAge: 5000,
      timeout: 60000,
    });
    keepScreenOn();
  }

  function stopGps() {
    if (gps.watchId !== null) navigator.geolocation.clearWatch(gps.watchId);
    gps.watchId = null;
    gps.lastKept = null;
    releaseScreen();
  }

  /** Resolves 'granted', 'denied', 'services_off' — and the fix, when there is one. */
  function askForLocation() {
    return new Promise(function (resolve) {
      if (!('geolocation' in navigator)) return resolve({ result: 'services_off' });
      navigator.geolocation.getCurrentPosition(
        function (pos) { resolve({ result: 'granted', pos: pos }); },
        function (err) {
          if (err.code === 1) resolve({ result: 'denied' });
          else if (err.code === 2) resolve({ result: 'services_off' });
          else resolve({ result: 'granted' }); // slow first fix: start anyway, the watch will get one
        },
        { enableHighAccuracy: true, maximumAge: 30000, timeout: 20000 }
      );
    });
  }

  // Parked, watchPosition can go quiet. Ask once a minute so the office can
  // tell parked from no signal.
  setInterval(function () {
    if (!store.trip() || document.hidden || gps.watchId === null) return;
    if (gps.lastFixMs && Date.now() - gps.lastFixMs < 60000) return;
    navigator.geolocation.getCurrentPosition(onFix, function () {}, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
  }, 30000);

  // -------------------------------------------------------------------------
  // Screen on. Without it the phone locks after a minute and GPS stops.
  // -------------------------------------------------------------------------

  var wake = { lock: null, supported: 'wakeLock' in navigator, failed: false };

  function keepScreenOn() {
    if (!wake.supported || wake.lock || document.hidden) return;
    navigator.wakeLock.request('screen').then(function (lock) {
      wake.lock = lock;
      wake.failed = false;
      lock.addEventListener('release', function () { wake.lock = null; });
    }, function () {
      wake.failed = true;
      S.render();
    });
  }

  function releaseScreen() {
    if (wake.lock) wake.lock.release().catch(function () {});
    wake.lock = null;
  }

  // -------------------------------------------------------------------------
  // Trips
  // -------------------------------------------------------------------------

  function adopt(trip) {
    store.setTrip(toActiveTrip(trip));
    store.setEndedByOffice(false);
  }

  async function startTrip(vehicleId, firstFix) {
    var data = await api('trips/start', { method: 'POST', body: { vehicle_id: vehicleId } });
    adopt(data.trip);
    gps.lastKept = null;
    gps.gap = null;
    if (firstFix) onFix(firstFix);
    startGps();
    flush();
  }

  /** Works offline: the Stop is remembered and sent with the remaining fixes. */
  function stopTrip() {
    stopGps();
    var trip = store.trip();
    if (!trip) return Promise.resolve();
    var stops = store.stops().filter(function (s) { return s.tripId !== trip.id; });
    stops.push({ tripId: trip.id, endedAtMs: Date.now() });
    store.setStops(stops);
    store.setTrip(null);
    return flush();
  }

  /**
   * On open and on coming back to the screen. The server wins when it can be
   * reached; offline, the phone's own state stands.
   */
  async function reconcile() {
    var local = store.trip();
    var server;
    try {
      server = (await api('trips/current')).trip;
    } catch (e) {
      if (local) startGps();
      return;
    }
    // A Stop still waiting to be sent: the driver already ended that one.
    var stopPending = server && store.stops().some(function (s) { return s.tripId === server.id; });
    if (server && !stopPending) {
      adopt(server);
      startGps();
      return;
    }
    // Open here, not there: the office ended it.
    if (local) {
      stopGps();
      store.setTrip(null);
      store.setEndedByOffice(true);
    }
  }

  // -------------------------------------------------------------------------
  // Screens
  // -------------------------------------------------------------------------

  var VEHICLE_ICON = { car: 'car', van: 'truck', pickup: 'truck', truck: 'truck', bus: 'bus', bike: 'bike' };

  var state = {
    vehicles: null,
    loadError: null,
    selectedId: null,
    busy: false,
    check: null,
  };

  function elapsed(fromMs) {
    var total = Math.max(0, Math.floor((Date.now() - fromMs) / 1000));
    return Math.floor(total / 3600) + ':' + pad(Math.floor((total % 3600) / 60)) + ':' + pad(total % 60);
  }

  function trackingStatus() {
    if (gps.error === 'denied') return { dot: 'off', text: T.permission.title, action: 'driver:show-permission' };
    var fresh = gps.lastFixMs && Date.now() - gps.lastFixMs < 90000;
    if (gps.watchId !== null && fresh) return { dot: 'on', text: T.trackingOn };
    if (gps.watchId !== null) return { dot: 'off', text: T.waitingGps };
    return { dot: 'off', text: T.trackingPaused, action: 'driver:resume-gps' };
  }

  function nextStopCard(stops) {
    var done = stops.filter(function (s) { return s.arrived; }).length;
    var next = stops.find(function (s) { return !s.arrived; });
    var html = '<div class="card"><div class="label muted">' + esc(next ? T.nextStop(done + 1, stops.length) : T.allStopsDone) + '</div>';
    if (next) {
      var url = 'https://www.google.com/maps/dir/?api=1&destination=' + next.lat + ',' + next.lng + '&travelmode=driving';
      html += '<div class="h2 mt-1">' + esc(next.name) + '</div>' +
        (next.time ? '<div class="body-lg secondary">' + esc(T.stopTime(next.time)) + '</div>' : '') +
        '<a class="btn secondary mt-3" style="text-decoration:none" href="' + esc(url) + '" target="_blank" rel="noopener">' + icon('navigation') + esc(T.openMaps) + '</a>' +
        '<div class="small muted mt-2">' + esc(T.mapsNote) + '</div>';
    }
    return html + '</div>';
  }

  function renderHome() {
    var session = S.session();
    var trip = store.trip();
    var body = '';
    var footer;

    if (trip) {
      var status = trackingStatus();
      var waiting = store.points().length;
      var lastSent = store.lastSent();
      body += '<div class="trip-card">' +
        '<div class="label gold">' + esc(T.onTrip) + '</div>' +
        '<div class="h1">' + esc(trip.plateNo) + '</div>' +
        (trip.vehicleName ? '<div class="body-lg on-navy-muted">' + esc(trip.vehicleName) + '</div>' : '') +
        (trip.routeName ? '<div class="gold mt-1">' + esc((trip.direction === 'dropoff' ? T.dropoff : T.pickup) + ' · ' + trip.routeName) + '</div>' : '') +
        '<div class="clock" data-clock="' + trip.startedAtMs + '">' + elapsed(trip.startedAtMs) + '</div>' +
        '<div class="on-navy-muted">' + esc(T.startedAt(clock(trip.startedAtMs))) + '</div>' +
        '</div>';

      if (trip.stops && trip.stops.length) body += nextStopCard(trip.stops);

      body += (status.action ? '<button type="button" class="card row" data-action="' + status.action + '">' : '<div class="card row">') +
        '<span class="dot ' + status.dot + '"></span><span class="grow body-lg" style="font-weight:600">' + esc(status.text) + '</span>' +
        (status.action ? '</button>' : '</div>');

      if (gps.gap) {
        body += '<button type="button" class="card warn row" data-action="driver:hide-gap">' + icon('alert') +
          '<span class="grow">' + esc(T.gapNotice(clock(gps.gap.from), clock(gps.gap.to))) + '</span>' + icon('close') + '</button>';
      }

      body += '<div class="card info"><div class="row">' + icon('phone') + '<div class="grow"><div class="h3">' + esc(T.keepOpenTitle) + '</div>' +
        '<div class="small secondary mt-1">' + esc(T.keepOpenBody) + '</div>' +
        (S.isIOS && (!wake.supported || wake.failed) ? '<div class="small secondary mt-2">' + esc(T.autoLockHint) + '</div>' : '') +
        '</div></div></div>';

      body += '<div class="card"><div class="secondary">' + esc(lastSent ? T.lastSent(clock(lastSent)) : T.notSentYet) + '</div>' +
        '<div class="secondary mt-1">' + esc(waiting > 0 ? T.waiting(waiting) : T.allSent) + '</div></div>';

      footer = button(T.stop, 'driver:confirm-stop', { variant: 'danger', loading: state.busy });
    } else {
      body += S.installCard();
      if (store.endedByOffice()) {
        body += '<button type="button" class="card row" data-action="driver:hide-ended">' +
          '<span style="color:var(--warning)">' + icon('alert') + '</span><span class="grow body-lg">' + esc(T.endedByOffice) + '</span>' +
          '<span class="muted">' + icon('close') + '</span></button>';
      }
      body += '<h2 class="h3 section-title mt-3">' + esc(T.pickTitle) + '</h2>';

      if (state.vehicles === null && !state.loadError) {
        body += '<div class="skel" style="height:76px"></div><div class="skel" style="height:76px"></div>';
      }
      if (state.loadError) {
        body += '<div class="card"><div class="body-lg">' + esc(state.loadError) + '</div><div class="mt-3">' +
          button(T.retry, 'driver:reload', { variant: 'secondary' }) + '</div></div>';
      }
      if (state.vehicles && !state.vehicles.length) {
        body += '<div class="card"><div class="h3">' + esc(T.noVehiclesTitle) + '</div><div class="body-lg secondary mt-1">' + esc(T.noVehiclesBody) + '</div></div>';
      }
      (state.vehicles || []).forEach(function (v) {
        var taken = v.busy_with !== null;
        var selected = v.id === state.selectedId;
        var details = [v.name, v.type_label, v.color].filter(Boolean).join(' · ');
        body += '<button type="button" role="radio" aria-checked="' + selected + '" class="card vehicle' + (selected ? ' selected' : '') + (taken ? ' taken' : '') +
          '" data-action="driver:pick" data-id="' + v.id + '" data-key="v' + v.id + '"' + (taken ? ' disabled' : '') + '>' +
          '<span class="veh-icon">' + icon(VEHICLE_ICON[v.vehicle_type] || 'car', 'lg') + '</span>' +
          '<span class="grow" style="flex:1;min-width:0"><span class="h3" style="display:block">' + esc(v.plate_no) + '</span>' +
          (details ? '<span class="secondary" style="display:block">' + esc(details) + '</span>' : '') +
          (taken ? '<span class="busy" style="display:block">' + esc(T.busyWith(v.busy_with)) + '</span>' : '') +
          '</span>' + (selected ? '<span class="check">' + icon('check') + '</span>' : '') + '</button>';
      });

      footer = button(T.start, 'driver:confirm-start', { disabled: state.selectedId === null, loading: state.busy });
    }

    return '<main class="screen">' +
      S.topHeader(T.title, T.greeting(session ? session.user.name : '')) +
      '<div class="screen-body">' + body + '</div>' +
      '<div class="footer">' + footer + '</div>' +
      '</main>';
  }

  function loadVehicles() {
    return api('vehicles').then(function (result) {
      state.vehicles = result.vehicles;
      state.loadError = null;
      // Keep the current pick if still free; otherwise the one used last, if free.
      var free = function (id) {
        return id !== null && result.vehicles.some(function (v) { return v.id === id && v.busy_with === null; });
      };
      state.selectedId = free(state.selectedId) ? state.selectedId : free(result.last_vehicle_id) ? result.last_vehicle_id : null;
    }, function (e) {
      state.loadError = e.message || T.genericBody;
    }).then(S.render);
  }

  var syncing = null;
  function sync() {
    if (!signedIn()) return Promise.resolve();
    if (syncing) return syncing;
    syncing = flush()
      .then(reconcile)
      .then(function () { S.render(); return store.trip() ? null : loadVehicles(); })
      .catch(function () {})
      .then(function () { syncing = null; S.render(); });
    return syncing;
  }

  async function begin() {
    if (state.selectedId === null) return;
    keepScreenOn(); // still inside the tap
    state.busy = true;
    S.render();
    var loc = await askForLocation();
    if (loc.result !== 'granted') {
      releaseScreen();
      state.busy = false;
      gps.error = loc.result === 'denied' ? 'denied' : null;
      showPermissionSheet(loc.result);
      return;
    }
    try {
      await startTrip(state.selectedId, loc.pos);
    } catch (e) {
      releaseScreen();
      S.toast(e.message || T.genericBody);
      await loadVehicles();
    }
    state.busy = false;
    S.render();
  }

  async function end() {
    state.busy = true;
    S.render();
    await stopTrip();
    state.busy = false;
    S.render();
    loadVehicles();
  }

  function showPermissionSheet(result) {
    if (result === 'services_off') {
      S.sheet({ title: T.permission.servicesOffTitle, body: T.permission.servicesOffBody, cancel: T.permission.ok });
    } else {
      S.sheet({
        title: T.permission.title,
        body: S.isIOS ? T.permission.bodyIos : T.permission.bodyAndroid,
        steps: S.isIOS ? T.permission.stepsIos : T.permission.stepsAndroid,
        cancel: T.permission.ok,
      });
    }
  }

  // --- Daily check ---------------------------------------------------------
  //
  // Once a day per vehicle, before the first trip. The list comes from the
  // server, so wording changes need no app update.
  //
  // Built for drivers who find reading and writing hard: ONE question per
  // screen, a big picture, two big buttons (green OK, red Problem), and it
  // moves on by itself. Nothing can be skipped. A Problem is shown with a photo
  // or a voice message rather than typed; typing is there but optional. The
  // only thing to type is the kilometres.

  var ITEM_ICON = {
    licence: 'idcard', fit: 'heart', body: 'car', lights: 'bulb', tyre_pressure: 'gauge', tyre_tread: 'tyre',
    fluids: 'droplet', wipers: 'wiper', windows: 'window', mirrors: 'eye', seatbelt: 'shield',
    emergency_kit: 'firstaid', documents: 'file', fuel: 'fuel', rest: 'moon',
  };
  var SECTION_ICON = {
    personal: 'user', exterior: 'car', tyres: 'tyre', fluids: 'droplet', glass: 'window', interior: 'shield',
    emergency: 'firstaid', documents: 'file', final: 'check',
  };
  var MAX_PHOTOS = 3;
  var MAX_VOICE_MS = 60000;

  Object.assign(S.ICON, {
    thumbUp: '<path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/>',
    thumbDown: '<path d="M17 14V2"/><path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z"/>',
    idcard: '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="12" r="2"/><path d="M14 10h4"/><path d="M14 14h4"/><path d="M5 17a3 3 0 0 1 6 0"/>',
    heart: '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/>',
    bulb: '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
    gauge: '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
    tyre: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><path d="M12 2v6M12 16v6M2 12h6M16 12h6"/>',
    droplet: '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/>',
    wiper: '<path d="M4 14.9A7 7 0 1 1 15.7 8h1.8a4.5 4.5 0 0 1 2.5 8.2"/><path d="M16 14v6"/><path d="M8 14v6"/><path d="M12 16v6"/>',
    window: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 10h20"/>',
    eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    firstaid: '<rect x="2" y="6" width="20" height="14" rx="2"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M12 10v6"/><path d="M9 13h6"/>',
    file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    fuel: '<line x1="3" x2="15" y1="22" y2="22"/><line x1="4" x2="14" y1="9" y2="9"/><path d="M14 22V4a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v18"/><path d="M14 13h2a2 2 0 0 1 2 2v2a2 2 0 0 0 2 2a2 2 0 0 0 2-2V9.83a2 2 0 0 0-.59-1.42L18 5"/>',
    moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
    globe: '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    volume: '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>',
    trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
  });

  // --- Spoken questions, like a phone menu ------------------------------------
  //
  // Each card reads its question out loud when it appears, in the language the
  // driver picked, and the big speaker button reads it again. The clips are
  // files made once by scripts/staff_check_voice.mjs from audio/check/texts.json
  // (OpenAI's voice, as in Jarvis). When the office has changed an item's
  // wording since the clips were made, or a clip will not load, the phone's own
  // voice reads the office's current words instead — never an outdated question.

  var LANG_KEY = 'staff.check.lang.v1';
  var voiceTexts = null;
  fetch('audio/check/texts.json', { cache: 'no-cache' }).then(function (r) { return r.json(); }).then(function (j) { voiceTexts = j; S.render(); }, function () {});

  function lang() { return S.readJson(LANG_KEY, null); }
  function langInfo() { return (voiceTexts && voiceTexts.languages[lang()]) || { speech: 'en-GB', dir: 'ltr' }; }

  /** A word on the check screen, in the driver's language when there is one. */
  function word(name) {
    var ui = voiceTexts && voiceTexts.ui[lang()];
    return (ui && ui[name]) || (voiceTexts && voiceTexts.ui.en[name]) || T.check[name] || name;
  }

  /** The question in the driver's language, or null when it no longer matches the office's wording. */
  function translated(item) {
    var entry = voiceTexts && voiceTexts.items[item.key];
    if (!entry || entry.source !== item.label) return null;
    return entry[lang()] || null;
  }

  var speaker = typeof Audio !== 'undefined' ? new Audio() : null;
  var speakToken = 0;

  /** Called inside a tap, so the phone lets later questions play by themselves. */
  function unlockSpeaker() {
    if (!speaker || speaker.dataset.unlocked) return;
    speaker.dataset.unlocked = '1';
    speaker.src = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQAAAAA=';
    speaker.play().catch(function () { delete speaker.dataset.unlocked; });
  }

  function hush() {
    speakToken++;
    if (speaker) { try { speaker.pause(); } catch (e) { /* ignore */ } }
    if (window.speechSynthesis) window.speechSynthesis.cancel();
  }

  function phoneVoice(text, speech, done) {
    if (!window.speechSynthesis || !window.SpeechSynthesisUtterance || !text) { done(); return; }
    var u = new SpeechSynthesisUtterance(text);
    u.lang = speech;
    u.rate = 0.9;
    u.onend = done;
    u.onerror = done;
    window.speechSynthesis.speak(u);
  }

  /** Play clips one after another: [{clip: 'intro' | item key, text, speech}]. */
  function sayAll(parts) {
    hush();
    var token = speakToken;
    var i = 0;
    var next = function () {
      if (token !== speakToken || i >= parts.length) return;
      var part = parts[i++];
      if (!part.clip || !speaker) { phoneVoice(part.text, part.speech, next); return; }
      speaker.onended = next;
      speaker.onerror = function () { if (token === speakToken) phoneVoice(part.text, part.speech, next); };
      speaker.src = 'audio/check/' + lang() + '/' + part.clip + '.mp3';
      speaker.play().catch(function () { if (token === speakToken) phoneVoice(part.text, part.speech, next); });
    };
    next();
  }

  function promptPart(name) {
    var text = voiceTexts && voiceTexts.prompts[name] ? voiceTexts.prompts[name][lang()] : '';
    return { clip: name, text: text, speech: langInfo().speech };
  }

  function itemPart(item) {
    var t = translated(item);
    // Outdated clip: say the office's own words in English rather than an old question.
    return t ? { clip: item.key, text: t, speech: langInfo().speech } : { clip: null, text: item.label, speech: 'en-GB' };
  }

  /** Read out whatever the check screen is showing now. */
  function speakCard(withIntro) {
    var c = state.check;
    if (!c || !lang() || c.step === 'lang') return;
    if (c.step === 'km') { sayAll([promptPart('km')]); return; }
    var item = c.items[c.i];
    if (c.answers[item.key] === 'problem') { sayAll([promptPart('problem')]); return; }
    sayAll((withIntro ? [promptPart('intro')] : []).concat([itemPart(item)]));
  }

  /** Fetch the chosen language's clips once, so they are on the phone when the signal is not. */
  function warmClips() {
    if (!voiceTexts || !lang()) return;
    Object.keys(voiceTexts.prompts).concat(Object.keys(voiceTexts.items)).forEach(function (k) {
      fetch('audio/check/' + lang() + '/' + k + '.mp3').catch(function () {});
    });
  }

  /** Start pressed: the daily check first if today's is still owed, else straight to Start. */
  async function openStart() {
    var vehicleId = state.selectedId;
    if (vehicleId === null) return;
    var vehicle = (state.vehicles || []).find(function (v) { return v.id === vehicleId; });
    state.busy = true;
    S.render();
    var today;
    try {
      today = await api('checks/today?vehicle_id=' + vehicleId);
    } catch (e) {
      state.busy = false;
      S.render();
      S.toast(e.message || T.genericBody);
      return;
    }
    state.busy = false;
    if (today.needed) {
      var items = [];
      today.sections.forEach(function (s) {
        s.items.forEach(function (i) { items.push({ key: i.key, label: i.label, na: !!i.na, section: s.title, sectionKey: s.key }); });
      });
      state.check = {
        vehicleId: vehicleId,
        plate: vehicle ? vehicle.plate_no : '',
        items: items,
        i: 0,
        step: 'items',
        answers: {},
        media: {},
        lastKm: today.last_km,
        km: '',
        error: null,
        saving: false,
      };
      if (!lang()) state.check.step = 'lang';
      S.go({ app: 'driver', view: 'check' });
      speakCard(true);
      warmClips();
      return;
    }
    S.sheet({ title: T.startSheet.title, body: T.startSheet.body, confirm: T.startSheet.confirm, cancel: T.startSheet.cancel, onConfirm: begin });
  }

  function evidence(key) {
    var c = state.check;
    return c.media[key] || (c.media[key] = { photos: [], voice: null, text: '' });
  }

  function hasEvidence(key) {
    var m = state.check.media[key];
    return !!m && (m.photos.length > 0 || !!m.voice || m.text.trim() !== '');
  }

  function nextCard() {
    var c = state.check;
    c.error = null;
    if (c.i < c.items.length - 1) c.i += 1;
    else c.step = 'km';
    S.render();
    window.scrollTo(0, 0);
    speakCard(false);
  }

  function prevCard() {
    var c = state.check;
    stopVoice(true);
    c.error = null;
    if (c.step === 'km') c.step = 'items';
    else if (c.i > 0) c.i -= 1;
    else { hush(); S.back(); return; }
    S.render();
    window.scrollTo(0, 0);
    speakCard(false);
  }

  function checkTop(n, total) {
    var pct = Math.round((n / total) * 100);
    var info = voiceTexts && voiceTexts.languages[lang()];
    return '<div class="topbar"><button type="button" class="back-btn" data-action="driver:check-prev">' + icon('back') + esc(word('back')) + '</button>' +
      '<span class="qcount">' + (Math.min(n, total)) + ' / ' + total + '</span>' +
      (info ? '<button type="button" class="qlang" data-action="driver:check-lang">' + icon('globe') + '<span>' + esc(info.name) + '</span></button>' : '') + '</div>' +
      '<div class="qprogress"><span style="width:' + pct + '%"></span></div>';
  }

  function renderLanguagePicker() {
    var list = voiceTexts ? Object.keys(voiceTexts.languages) : [];
    return '<main class="screen qscreen">' +
      '<div class="topbar"><button type="button" class="back-btn" data-action="driver:check-prev">' + icon('back') + esc(T.check.back) + '</button></div>' +
      '<div class="qcard"><div class="qicon">' + icon('globe', 'huge') + '</div></div>' +
      '<div class="screen-body"><div class="qlangs">' +
      (list.length ? list.map(function (code) {
        var l = voiceTexts.languages[code];
        return '<button type="button" class="qlangbtn" dir="' + l.dir + '" data-action="driver:check-pick-lang" data-lang="' + code + '">' + esc(l.name) + '</button>';
      }).join('') : '<div class="skel" style="height:72px"></div><div class="skel" style="height:72px"></div>') +
      '</div></div></main>';
  }

  function listenButton() {
    return '<button type="button" class="qlisten" data-action="driver:check-listen">' + icon('volume', 'lg') + '<span>' + esc(word('listen')) + '</span></button>';
  }

  function problemPanel(item) {
    var m = evidence(item.key);
    var recording = vrec.active && vrec.key === item.key;
    var photos = m.photos.map(function (p, idx) {
      return '<div class="qthumb" data-key="ph' + idx + '"><img src="' + esc(p.url) + '" alt="">' +
        '<button type="button" class="remove" data-action="driver:check-photo-del" data-i="' + idx + '" aria-label="Delete">' + icon('close') + '</button></div>';
    }).join('');
    var voice = m.voice
      ? '<div class="qvoice"><button type="button" class="vbtn" data-action="driver:check-voice-play" aria-label="Play">' + icon('play') + '</button>' +
        '<span class="grow">' + esc(word('voice')) + ' · 0:' + pad(Math.min(59, m.voice.seconds)) + '</span>' +
        '<button type="button" class="icon-btn" data-action="driver:check-voice-del" aria-label="Delete">' + icon('trash') + '</button></div>'
      : '';
    return '<div class="qproblem">' +
      '<div class="h3" style="color:var(--danger)">' + esc(word('showTitle')) + '</div>' +
      '<div class="qtools">' +
      '<button type="button" class="qtool" data-action="driver:check-photo"' + (recording ? ' disabled' : '') + '>' + icon('camera', 'lg') + '<span>' + esc(word('photo')) + '</span></button>' +
      '<button type="button" class="qtool' + (recording ? ' live' : '') + '" data-action="driver:check-voice">' + icon(recording ? 'pause' : 'mic', 'lg') +
      '<span>' + (recording ? esc(word('stop')) + ' <b data-vtime>0:00</b>' : esc(word('voice'))) + '</span></button>' +
      '</div>' +
      (photos ? '<div class="qthumbs">' + photos + '</div>' : '') + voice +
      '<textarea class="field mt-3" id="check-text" rows="2" placeholder="' + esc(word('writeInstead')) + '">' + esc(m.text) + '</textarea>' +
      '</div>';
  }

  function renderChecklist() {
    var c = state.check;
    if (c.step === 'lang' || !lang()) return renderLanguagePicker();
    var total = c.items.length + 1;
    var dir = langInfo().dir;
    var body;
    var footer;

    if (c.step === 'km') {
      body = '<div class="qcard" dir="' + dir + '"><div class="qicon">' + icon('gauge', 'huge') + '</div>' +
        '<div class="qtext">' + esc(word('kmTitle')) + '</div></div>' + listenButton() +
        '<input class="field qkm" id="check-km" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" placeholder="' + esc(T.check.kmPlaceholder) + '" value="' + esc(c.km) + '">' +
        (c.lastKm !== null ? '<div class="secondary center mt-2">' + esc(T.check.kmHint(c.lastKm)) + '</div>' : '');
      footer = button(c.saving ? T.check.sending : word('save'), 'driver:check-save', { loading: c.saving });
      return '<main class="screen qscreen">' + checkTop(total, total) +
        '<div class="screen-body">' + body + (c.error ? '<div class="card alert">' + esc(c.error) + '</div>' : '') + '</div>' +
        '<div class="footer">' + footer + '</div></main>';
    }

    var item = c.items[c.i];
    var answer = c.answers[item.key];
    var own = translated(item);
    body = '<div class="qcard" data-key="q' + esc(item.key) + '">' +
      '<div class="qicon' + (answer === 'problem' ? ' bad' : answer === 'ok' ? ' good' : '') + '">' + icon(ITEM_ICON[item.key] || SECTION_ICON[item.sectionKey] || 'check', 'huge') + '</div>' +
      (own && lang() !== 'en'
        ? '<div class="qtext" dir="' + dir + '">' + esc(own) + '</div><div class="secondary mt-2">' + esc(item.label) + '</div>'
        : '<div class="qtext">' + esc(own || item.label) + '</div>') +
      '</div>' + listenButton();

    if (answer === 'problem') {
      body += problemPanel(item);
      footer = '<div class="action-row">' +
        button(word('okAfterAll'), 'driver:check-ok', { variant: 'secondary', style: 'flex:2' }) +
        button(word('next'), 'driver:check-next', { style: 'flex:3', icon: 'chevron' }) + '</div>';
    } else {
      footer = '<div class="qanswers">' +
        '<button type="button" class="qbtn ok' + (answer === 'ok' ? ' on' : '') + '" data-action="driver:check-answer" data-value="ok">' + icon('thumbUp', 'lg') + esc(word('ok')) + '</button>' +
        '<button type="button" class="qbtn problem" data-action="driver:check-answer" data-value="problem">' + icon('thumbDown', 'lg') + esc(word('problem')) + '</button>' +
        (item.na ? '<button type="button" class="btn plain' + (answer === 'na' ? ' on' : '') + '" data-action="driver:check-answer" data-value="na">' + esc(word('na')) + '</button>' : '') +
        '</div>';
    }

    return '<main class="screen qscreen">' + checkTop(c.i + 1, total) +
      '<div class="screen-body">' + body + (c.error ? '<div class="card alert">' + esc(c.error) + '</div>' : '') + '</div>' +
      '<div class="footer">' + footer + '</div></main>';
  }

  function addPhoto() {
    var c = state.check;
    var key = c.items[c.i].key;
    var m = evidence(key);
    if (m.photos.length >= MAX_PHOTOS) { S.toast(T.check.maxPhotos); return; }
    S.pickFiles('image/*', true, false).then(function (files) {
      if (!files.length) return null;
      return S.preparePhoto(files[0]).then(function (p) {
        m.photos.push({ blob: p.blob, name: p.name, url: URL.createObjectURL(p.blob) });
        c.error = null;
        S.render();
      });
    }).catch(function (e) { S.toast(e.message || T.genericBody); });
  }

  // Voice: press once to start, once to stop — easier than holding a finger down.
  var vrec = { active: false, key: null, recorder: null, stream: null, chunks: [], startedAt: 0, timer: null, type: '', discard: false };

  function toggleVoice() {
    if (vrec.active) { stopVoice(false); return; }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) { S.toast(T.check.noMic); return; }
    var key = state.check.items[state.check.i].key;
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var type = S.recorderMime();
      var r;
      try { r = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream); } catch (e) { r = new MediaRecorder(stream); }
      vrec = { active: true, key: key, recorder: r, stream: stream, chunks: [], startedAt: Date.now(), timer: null, type: type, discard: false };
      r.ondataavailable = function (ev) { if (ev.data && ev.data.size) vrec.chunks.push(ev.data); };
      r.onstop = voiceStopped;
      r.start();
      vrec.timer = setInterval(function () {
        var held = Date.now() - vrec.startedAt;
        var el = document.querySelector('[data-vtime]');
        if (el) el.textContent = '0:' + pad(Math.min(59, Math.floor(held / 1000)));
        if (held >= MAX_VOICE_MS) stopVoice(false);
      }, 250);
      S.render();
    }, function () { S.toast(T.check.micBlocked); });
  }

  function stopVoice(discard) {
    if (!vrec.active) return;
    vrec.active = false;
    vrec.discard = discard;
    vrec.seconds = Math.round((Date.now() - vrec.startedAt) / 1000);
    clearInterval(vrec.timer);
    try { vrec.recorder.stop(); } catch (e) { voiceStopped(); }
    S.render();
  }

  function voiceStopped() {
    if (vrec.stream) vrec.stream.getTracks().forEach(function (t) { t.stop(); });
    var blob = new Blob(vrec.chunks, { type: (vrec.recorder && vrec.recorder.mimeType) || 'audio/webm' });
    var key = vrec.key;
    var seconds = vrec.seconds;
    if (vrec.discard || !state.check) return;
    if (seconds < 1 || !blob.size) { S.toast(T.check.tooShort); return; }
    S.voiceFile(blob, (vrec.recorder && vrec.recorder.mimeType) || vrec.type).then(function (f) {
      var m = evidence(key);
      if (m.voice) URL.revokeObjectURL(m.voice.url);
      m.voice = { blob: f.blob, name: f.name, url: URL.createObjectURL(f.blob), seconds: seconds };
      state.check.error = null;
      S.render();
    }, function () { S.toast(T.genericBody); });
  }

  var playing = null;
  function playVoice() {
    var c = state.check;
    var m = c.media[c.items[c.i].key];
    if (!m || !m.voice) return;
    if (playing) { playing.pause(); playing = null; }
    playing = new Audio(m.voice.url);
    playing.play().catch(function () {});
  }

  /** The notes the server keeps: every problem by name, with anything typed. */
  function problemNotes() {
    var c = state.check;
    return c.items.filter(function (i) { return c.answers[i.key] === 'problem'; }).map(function (i) {
      var m = c.media[i.key] || { text: '', photos: [], voice: null };
      var said = m.text.trim();
      return '• ' + i.label + (said ? ': ' + said : (m.photos.length || m.voice ? ' — ' + T.check.seePhoto : ''));
    }).join('\n');
  }

  async function saveCheck() {
    var c = state.check;
    if (c.saving) return;
    var km = c.km.replace(/[^\d]/g, '');
    if (km === '') { c.error = T.check.needKm; S.render(); return; }

    var form = new FormData();
    form.append('vehicle_id', String(c.vehicleId));
    form.append('start_km', km);
    form.append('answers', JSON.stringify(c.answers));
    form.append('notes', problemNotes());
    c.items.forEach(function (i) {
      var m = c.media[i.key];
      if (c.answers[i.key] !== 'problem' || !m) return;
      m.photos.forEach(function (p) {
        form.append('media[]', p.blob, p.name);
        form.append('media_item[]', i.key);
        form.append('media_kind[]', 'photo');
        form.append('media_duration[]', '');
      });
      if (m.voice) {
        form.append('media[]', m.voice.blob, m.voice.name);
        form.append('media_item[]', i.key);
        form.append('media_kind[]', 'voice');
        form.append('media_duration[]', String(m.voice.seconds));
      }
    });

    keepScreenOn(); // still inside the tap
    c.saving = true;
    c.error = null;
    S.render();
    try {
      await S.request(API_BASE + '/checks', { method: 'POST', form: form, token: S.token('driver'), timeout: 5 * 60 * 1000 });
    } catch (e) {
      c.saving = false;
      c.error = e.message || T.genericBody;
      S.render();
      return;
    }
    Object.keys(c.media).forEach(function (k) {
      c.media[k].photos.forEach(function (p) { URL.revokeObjectURL(p.url); });
      if (c.media[k].voice) URL.revokeObjectURL(c.media[k].voice.url);
    });
    state.check = null;
    state.selectedId = c.vehicleId;
    S.go({ app: 'driver' }, true);
    begin();
  }

  // -------------------------------------------------------------------------
  // The module
  // -------------------------------------------------------------------------

  var onCheck = false;

  function render(route) {
    if (route.view === 'check' && state.check) { onCheck = true; return renderChecklist(); }
    if (onCheck) { onCheck = false; hush(); } // left with the phone's Back button
    return renderHome();
  }

  function action(name, el) {
    switch (name) {
      case 'pick': state.selectedId = Number(el.getAttribute('data-id')); S.render(); break;
      case 'reload': state.loadError = null; state.vehicles = null; S.render(); loadVehicles(); break;
      case 'hide-ended': store.setEndedByOffice(false); S.render(); break;
      case 'hide-gap': gps.gap = null; S.render(); break;
      case 'confirm-start': unlockSpeaker(); openStart(); break;
      case 'check-pick-lang': {
        S.writeJson(LANG_KEY, el.getAttribute('data-lang'));
        unlockSpeaker();
        var cl = state.check;
        if (cl) { cl.step = cl.i === 0 && !Object.keys(cl.answers).length ? 'items' : cl.back || 'items'; }
        S.render();
        speakCard(!cl || !Object.keys(cl.answers).length);
        warmClips();
        break;
      }
      case 'check-lang': hush(); state.check.back = state.check.step; state.check.step = 'lang'; S.render(); break;
      case 'check-listen': unlockSpeaker(); speakCard(false); break;
      case 'check-answer': {
        var c = state.check;
        var item = c.items[c.i];
        var value = el.getAttribute('data-value');
        c.answers[item.key] = value;
        c.error = null;
        if (value === 'problem') { S.render(); speakCard(false); break; }
        delete c.media[item.key];
        nextCard();
        break;
      }
      case 'check-ok': {
        var ck = state.check;
        stopVoice(true);
        ck.answers[ck.items[ck.i].key] = 'ok';
        delete ck.media[ck.items[ck.i].key];
        nextCard();
        break;
      }
      case 'check-next': {
        var cn = state.check;
        if (vrec.active) stopVoice(false);
        if (!hasEvidence(cn.items[cn.i].key) && !vrec.active) { cn.error = T.check.needEvidence; S.render(); break; }
        nextCard();
        break;
      }
      case 'check-prev': prevCard(); break;
      case 'check-photo': addPhoto(); break;
      case 'check-photo-del': {
        var cm = evidence(state.check.items[state.check.i].key);
        var gone = cm.photos.splice(Number(el.getAttribute('data-i')), 1)[0];
        if (gone) URL.revokeObjectURL(gone.url);
        S.render();
        break;
      }
      case 'check-voice': hush(); toggleVoice(); break;
      case 'check-voice-play': playVoice(); break;
      case 'check-voice-del': {
        var cv = evidence(state.check.items[state.check.i].key);
        if (cv.voice) URL.revokeObjectURL(cv.voice.url);
        cv.voice = null;
        S.render();
        break;
      }
      case 'check-save': hush(); saveCheck(); break;
      case 'confirm-stop':
        S.sheet({ title: T.stopSheet.title, body: T.stopSheet.body, confirm: T.stopSheet.confirm, cancel: T.stopSheet.cancel, danger: true, onConfirm: end });
        break;
      case 'show-permission': showPermissionSheet('denied'); break;
      case 'resume-gps':
        askForLocation().then(function (loc) {
          if (loc.result !== 'granted') { showPermissionSheet(loc.result); return; }
          gps.error = null;
          if (loc.pos) onFix(loc.pos);
          startGps();
          S.render();
        });
        break;
    }
  }

  function input(e) {
    var c = state.check;
    if (!c) return;
    if (e.target.id === 'check-km') c.km = e.target.value;
    else if (e.target.id === 'check-text') evidence(c.items[c.i].key).text = e.target.value;
    else return;
    c.error = null;
    S.render();
  }

  function tickClock() {
    var el = document.querySelector('[data-clock]');
    if (el) el.textContent = elapsed(Number(el.getAttribute('data-clock')));
  }

  setInterval(tickClock, 1000);
  // 30 s with the screen open: a reached stop moves on within about that long.
  setInterval(function () { if (signedIn() && store.trip() && !document.hidden) flush(); }, 30000);

  document.addEventListener('visibilitychange', function () {
    if (!signedIn()) return;
    if (document.hidden) {
      if (store.trip()) gps.hiddenAtMs = Date.now();
      return;
    }
    // Back on screen: say how long recording was paused, if it was long enough to matter.
    if (store.trip() && gps.hiddenAtMs && Date.now() - gps.hiddenAtMs > 60000) {
      gps.gap = { from: gps.hiddenAtMs, to: Date.now() };
    }
    gps.hiddenAtMs = null;
    if (store.trip()) {
      // iOS can drop the watch and the wake lock while hidden — ask again.
      if (gps.watchId !== null) { navigator.geolocation.clearWatch(gps.watchId); gps.watchId = null; }
      releaseScreen();
      startGps();
    }
    sync();
  });

  window.addEventListener('online', function () { if (signedIn()) flush(); });
  window.addEventListener('pagehide', function () { if (store.trip()) gps.hiddenAtMs = gps.hiddenAtMs || Date.now(); });

  S.register('driver', {
    render: render,
    action: action,
    input: input,
    /** Signed in (or opened with a session): pick up where the phone left off. */
    start: function () {
      if (!signedIn()) return;
      if (store.trip()) startGps();
      sync();
    },
    /** Sign-out stops a running trip first — nobody should drive on untracked. */
    beforeSignOut: function () {
      state.vehicles = null;
      state.selectedId = null;
      state.check = null;
      return store.trip() ? stopTrip() : Promise.resolve();
    },
    hasTrip: function () { return !!store.trip(); },
    tileStatus: function () {
      var trip = store.trip();
      return trip ? T.tileOnTrip(trip.plateNo) : null;
    },
    // Safari may only grant the screen-on lock from a tap.
    onAnyTap: function () {
      if (store.trip() && gps.watchId !== null && !wake.lock) keepScreenOn();
    },
  });
})();
