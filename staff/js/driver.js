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
      subtitle: function (plate) { return 'Before your first trip today in ' + plate + '.'; },
      ok: 'OK',
      problem: 'Problem',
      na: 'N/A',
      kmTitle: 'Starting kilometres',
      kmHint: function (km) { return 'Last recorded: ' + km.toLocaleString('en-US') + ' km'; },
      kmPlaceholder: 'Odometer reading',
      notesTitle: 'Problems',
      notesHint: 'Write what is wrong, so the office can fix it.',
      notesOptional: 'Anything else the office should know (optional)',
      save: 'Save and start trip',
      back: 'Back',
      missing: function (n) { return n === 1 ? '1 item still needs an answer.' : n + ' items still need an answer.'; },
      needKm: 'Enter the starting kilometres.',
      needNotes: 'Write what the problem is.',
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
      state.check = {
        vehicleId: vehicleId,
        plate: vehicle ? vehicle.plate_no : '',
        sections: today.sections,
        lastKm: today.last_km,
        answers: {},
        km: '',
        notes: '',
        missing: null,
        error: null,
        saving: false,
      };
      S.go({ app: 'driver', view: 'check' });
      return;
    }
    S.sheet({ title: T.startSheet.title, body: T.startSheet.body, confirm: T.startSheet.confirm, cancel: T.startSheet.cancel, onConfirm: begin });
  }

  function checkProblems() {
    var c = state.check;
    return Object.keys(c.answers).filter(function (k) { return c.answers[k] === 'problem'; }).length;
  }

  function renderChecklist() {
    var c = state.check;
    var sections = c.sections.map(function (s) {
      return '<h2 class="label muted check-section">' + esc(s.title) + '</h2>' + s.items.map(function (item) {
        var opts = [['ok', T.check.ok], ['problem', T.check.problem]].concat(item.na ? [['na', T.check.na]] : []);
        return '<div class="card check-item' + (c.missing === item.key ? ' missing' : '') + '" data-item="' + esc(item.key) + '">' +
          '<div class="body-lg">' + esc(item.label) + '</div>' +
          '<div class="seg mt-2" role="radiogroup" aria-label="' + esc(item.label) + '">' + opts.map(function (o) {
            var on = c.answers[item.key] === o[0];
            return '<button type="button" role="radio" aria-checked="' + on + '" class="seg-btn ' + o[0] + (on ? ' on' : '') +
              '" data-action="driver:check-answer" data-key="' + esc(item.key) + '" data-value="' + o[0] + '">' + esc(o[1]) + '</button>';
          }).join('') + '</div></div>';
      }).join('');
    }).join('');

    return '<main class="screen">' +
      S.backBar('core:back', T.check.back) +
      '<h1 class="h1 mt-2">' + esc(T.check.title) + '</h1>' +
      '<p class="secondary mt-1" style="margin-bottom:0">' + esc(T.check.subtitle(c.plate)) + '</p>' +
      '<div class="screen-body">' + sections +
      '<h2 class="label muted check-section">' + esc(T.check.kmTitle) + '</h2>' +
      '<div class="card"><input class="field" id="check-km" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" placeholder="' + esc(T.check.kmPlaceholder) + '" value="' + esc(c.km) + '">' +
      (c.lastKm !== null ? '<div class="small muted mt-2">' + esc(T.check.kmHint(c.lastKm)) + '</div>' : '') + '</div>' +
      '<h2 class="label muted check-section">' + esc(T.check.notesTitle) + '</h2>' +
      '<div class="card"><div class="small secondary">' + esc(checkProblems() > 0 ? T.check.notesHint : T.check.notesOptional) + '</div>' +
      '<textarea class="field mt-2" id="check-notes" rows="3">' + esc(c.notes) + '</textarea></div>' +
      '<div class="card alert" id="check-error"' + (c.error ? '' : ' hidden') + '>' + esc(c.error || '') + '</div>' +
      '</div>' +
      '<div class="footer">' + button(T.check.save, 'driver:check-save', { loading: c.saving }) + '</div>' +
      '</main>';
  }

  function checkError(message, scrollTo) {
    state.check.error = message;
    S.render();
    var el = document.querySelector(scrollTo || '#check-error');
    if (el && message) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }

  async function saveCheck() {
    var c = state.check;
    var keys = [];
    c.sections.forEach(function (s) { s.items.forEach(function (i) { keys.push(i.key); }); });
    var missing = keys.filter(function (k) { return !c.answers[k]; });
    if (missing.length) {
      c.missing = missing[0];
      checkError(T.check.missing(missing.length), '[data-item="' + missing[0] + '"]');
      return;
    }
    var km = c.km.replace(/[^\d]/g, '');
    if (km === '') { checkError(T.check.needKm); return; }
    if (checkProblems() > 0 && !c.notes.trim()) { checkError(T.check.needNotes); return; }

    keepScreenOn(); // still inside the tap
    c.saving = true;
    c.error = null;
    S.render();
    try {
      await api('checks', { method: 'POST', body: { vehicle_id: c.vehicleId, start_km: Number(km), answers: c.answers, notes: c.notes } });
    } catch (e) {
      c.saving = false;
      checkError(e.message || T.genericBody);
      return;
    }
    state.check = null;
    state.selectedId = c.vehicleId;
    S.go({ app: 'driver' }, true);
    begin();
  }

  // -------------------------------------------------------------------------
  // The module
  // -------------------------------------------------------------------------

  function render(route) {
    if (route.view === 'check' && state.check) return renderChecklist();
    return renderHome();
  }

  function action(name, el) {
    switch (name) {
      case 'pick': state.selectedId = Number(el.getAttribute('data-id')); S.render(); break;
      case 'reload': state.loadError = null; state.vehicles = null; S.render(); loadVehicles(); break;
      case 'hide-ended': store.setEndedByOffice(false); S.render(); break;
      case 'hide-gap': gps.gap = null; S.render(); break;
      case 'confirm-start': openStart(); break;
      case 'check-answer': {
        var key = el.getAttribute('data-key');
        state.check.answers[key] = el.getAttribute('data-value');
        if (state.check.missing === key) state.check.missing = null;
        state.check.error = null;
        S.render();
        break;
      }
      case 'check-save': saveCheck(); break;
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
    if (!state.check) return;
    if (e.target.id === 'check-km') state.check.km = e.target.value;
    else if (e.target.id === 'check-notes') { state.check.notes = e.target.value; }
    else return;
    if (state.check.error) { state.check.error = null; }
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
