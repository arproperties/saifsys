/**
 * Driver app — web version. Same flow and server rules as DRIVER-MOBILE-APP:
 *
 *   PIN        -> token (api/mobile/fleet auth/pin, same lockout)
 *   No trip    -> pick the vehicle, press Start
 *   On trip    -> GPS fixes are saved on the phone first, then sent in batches;
 *                 Stop works offline and is sent with the remaining fixes.
 *
 * What differs from the APK: a web page gets GPS only while it is on screen.
 * So during a trip the page asks the phone to keep the screen on, and shows
 * plainly when recording has paused. Everything recorded is kept and sent.
 */
(function () {
  'use strict';

  var CFG = window.DRIVER_CONFIG || {};
  var API_BASE = String(CFG.apiBase || '../api/mobile/fleet').replace(/\/+$/, '');

  // -------------------------------------------------------------------------
  // Words
  // -------------------------------------------------------------------------

  var T = {
    signIn: {
      wordmark: 'AR PROPERTIES',
      title: 'Enter your PIN',
      subtitle: 'The same four digits you use for the other company apps.',
      checking: 'Checking…',
      wrongPin: 'That PIN did not work. If it works in another company app, ask the office to turn on Driver access.',
      lockedMinutes: function (n) { return n === 1 ? 'Try again in 1 minute.' : 'Try again in ' + n + ' minutes.'; },
      forgot: 'Forgotten your PIN? Ask the office for a new one.',
      delete: 'Delete the last digit',
    },
    home: {
      greeting: function (name) { return 'Hello, ' + name; },
      settings: 'Settings',
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
    },
    install: {
      iosTitle: 'Add to your Home Screen',
      iosBody: 'Tap the Share button, then "Add to Home Screen". Open the app from there next time.',
      androidTitle: 'Install the app',
      androidBody: 'Put Driver on your home screen like a normal app.',
      androidButton: 'Install',
      dismiss: 'Hide this',
    },
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
        'Long-press the Driver icon → App info → Permissions → Location → Allow',
        'Or in Chrome: tap ⋮ → Settings → Site settings → Location → allow this site',
      ],
      servicesOffTitle: 'Your GPS is off',
      servicesOffBody: "Turn on your phone's location, then try again.",
      ok: 'OK',
    },
    settings: {
      title: 'Settings',
      back: 'Back',
      you: 'You are signed in as',
      signOut: 'Sign out',
      signOutSheet: {
        title: 'Sign out of the app?',
        body: 'You will need your PIN to get back in. A running trip is stopped first.',
        confirm: 'Yes, sign out',
        cancel: 'Stay signed in',
      },
      version: 'App version',
    },
    errors: {
      offlineBody: 'No internet right now. Try again when you have signal.',
      genericBody: 'Please try again in a moment.',
      blockedBody: 'This app cannot reach the office system. Ask the office to check the app setup.',
      signedOutBody: 'Please sign in again. If your PIN has changed, ask the office for the new one.',
      noGps: 'Your phone could not find its location. Go outside or near a window and try again.',
      retry: 'Try again',
    },
  };

  // -------------------------------------------------------------------------
  // Storage — same key names as the APK. Every access can throw (private mode).
  // -------------------------------------------------------------------------

  var KEYS = {
    session: 'fleet.session.v1',
    device: 'fleet.deviceId.v1',
    trip: 'fleet.trip.v1',
    points: 'fleet.points.v1',
    stops: 'fleet.stops.v1',
    lastSent: 'fleet.lastSent.v1',
    ended: 'fleet.endedByOffice.v1',
    installHidden: 'fleet.installHidden.v1',
  };

  /** About 2.5 days of fixes at one every 15 s — past this the oldest go. */
  var MAX_POINTS = 15000;

  function readJson(key, fallback) {
    try {
      var raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (e) {
      return fallback;
    }
  }

  function writeJson(key, value) {
    try {
      if (value === null || value === undefined) localStorage.removeItem(key);
      else localStorage.setItem(key, JSON.stringify(value));
    } catch (e) { /* full or blocked */ }
  }

  var store = {
    session: function () {
      var s = readJson(KEYS.session, null);
      return s && s.token && s.user && s.user.id ? s : null;
    },
    setSession: function (s) { writeJson(KEYS.session, s); },
    trip: function () { return readJson(KEYS.trip, null); },
    setTrip: function (t) { writeJson(KEYS.trip, t); },
    points: function () { return readJson(KEYS.points, []); },
    setPoints: function (p) { writeJson(KEYS.points, p.length > MAX_POINTS ? p.slice(p.length - MAX_POINTS) : p); },
    stops: function () { return readJson(KEYS.stops, []); },
    setStops: function (s) { writeJson(KEYS.stops, s); },
    lastSent: function () { return readJson(KEYS.lastSent, null); },
    setLastSent: function (ms) { writeJson(KEYS.lastSent, ms); },
    endedByOffice: function () { return readJson(KEYS.ended, false); },
    setEndedByOffice: function (v) { writeJson(KEYS.ended, !!v); },
  };

  /** Per-install id the sign-in lockout counts against. Kept across sign-outs. Not a secret. */
  function deviceId() {
    var id = readJson(KEYS.device, null);
    if (id) return id;
    id = Date.now().toString(36) + Math.random().toString(36).slice(2, 10) + Math.random().toString(36).slice(2, 10);
    writeJson(KEYS.device, id);
    return id;
  }

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

  // -------------------------------------------------------------------------
  // API
  // -------------------------------------------------------------------------

  function ApiError(kind, message, status, retryAfter) {
    this.kind = kind;
    this.message = message;
    this.status = status || 0;
    this.retryAfter = retryAfter || 0;
  }

  function friendlyError(status, code, message, retryAfter) {
    if (code === 'bad_pin') return new ApiError('bad_pin', T.signIn.wrongPin, status);
    if (code === 'too_many_attempts') return new ApiError('locked', T.signIn.lockedMinutes(15), status, retryAfter);
    if (status === 401) return new ApiError('unauthorized', T.errors.signedOutBody, status);
    if (status === 403 || status === 503) return new ApiError('blocked', T.errors.blockedBody, status);
    // The server's 400/404/409 messages are written for drivers — pass them through.
    if (status === 404) return new ApiError('not_found', message || T.errors.genericBody, status);
    if (status === 409) return new ApiError('conflict', message || T.errors.genericBody, status);
    if (status === 400) return new ApiError('bad_request', message || T.errors.genericBody, status);
    return new ApiError('server', T.errors.genericBody, status);
  }

  /**
   * opts.background: sent by the tracker, not by a tap — a refused token
   * there must not throw the driver out to the PIN screen mid-drive.
   */
  function api(path, opts) {
    opts = opts || {};
    var session = store.session();
    var headers = { Accept: 'application/json', 'X-Ops-Device-Id': deviceId() };
    if (CFG.appKey) headers['X-Ops-App-Key'] = CFG.appKey;
    if (session) headers.Authorization = 'Bearer ' + session.token;
    if (opts.body) headers['Content-Type'] = 'application/json';

    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, 15000) : null;

    return fetch(API_BASE + '/' + path, {
      method: opts.method || 'GET',
      headers: headers,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
      signal: controller ? controller.signal : undefined,
      cache: 'no-store',
    }).then(function (response) {
      if (timer) clearTimeout(timer);
      return response.json().catch(function () { return null; }).then(function (env) {
        if (response.ok && env && env.ok) return env.data;
        var err = env && env.error ? env.error : {};
        var failure = friendlyError(
          response.status,
          err.code || null,
          err.message || null,
          Number((err.details && err.details.retry_after) || 0)
        );
        if (failure.kind === 'unauthorized' && !opts.background) onSignedOut();
        throw failure;
      });
    }, function () {
      if (timer) clearTimeout(timer);
      throw new ApiError('offline', T.errors.offlineBody);
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
    flushing = doFlush().catch(function () {}).then(function () { flushing = null; render(); });
    return flushing;
  }

  async function doFlush() {
    if (!store.session()) return;

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
    render();
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
      render();
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
  // App state and screens
  // -------------------------------------------------------------------------

  var ua = navigator.userAgent || '';
  var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isStandalone = function () {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  };

  var state = {
    screen: store.session() ? 'home' : 'signin',
    pin: '',
    pinBusy: false,
    pinError: null,
    lockedUntil: 0,
    vehicles: null,
    loadError: null,
    selectedId: null,
    busy: false,
    sheet: null,
    installPrompt: null,
    installHidden: readJson(KEYS.installHidden, false),
  };

  var app = document.getElementById('app');
  var sheetRoot = document.getElementById('sheet-root');
  var toastEl = document.getElementById('toast');

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var pad = function (n) { return String(n).padStart(2, '0'); };
  function clock(ms) { var d = new Date(ms); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function elapsed(fromMs) {
    var total = Math.max(0, Math.floor((Date.now() - fromMs) / 1000));
    return Math.floor(total / 3600) + ':' + pad(Math.floor((total % 3600) / 60)) + ':' + pad(total % 60);
  }

  var toastTimer = null;
  function toast(message) {
    toastEl.textContent = message;
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 6000);
  }

  // Icons: Feather/Lucide line icons, drawn inline so nothing loads.
  var ICON = {
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    check: '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    alert: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    close: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    navigation: '<polygon points="3 11 22 2 13 21 11 13 3 11"/>',
    backspace: '<path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><line x1="18" y1="9" x2="12" y2="15"/><line x1="12" y1="9" x2="18" y2="15"/>',
    back: '<polyline points="15 18 9 12 15 6"/>',
    share: '<path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    phone: '<rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
    car: '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',
    truck: '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
    bus: '<path d="M8 6v6"/><path d="M15 6v6"/><path d="M2 12h19.6"/><path d="M18 18h3s.5-1.7.8-2.8c.1-.4.2-.8.2-1.2 0-.4-.1-.8-.2-1.2l-1.4-5C20.1 6.8 19.1 6 18 6H4a2 2 0 0 0-2 2v10h3"/><circle cx="7" cy="18" r="2"/><path d="M9 18h5"/><circle cx="16" cy="18" r="2"/>',
    bike: '<circle cx="18.5" cy="17.5" r="3.5"/><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="M12 17.5V14l-3-3 4-3 2 3h2"/>',
  };
  var VEHICLE_ICON = { car: 'car', van: 'truck', pickup: 'truck', truck: 'truck', bus: 'bus', bike: 'bike' };

  function icon(name, cls) {
    return '<svg class="icon ' + (cls || '') + '" viewBox="0 0 24 24" aria-hidden="true">' + ICON[name] + '</svg>';
  }

  function button(label, action, opts) {
    opts = opts || {};
    return '<button type="button" class="btn ' + (opts.variant || '') + '" data-action="' + action + '"' +
      (opts.disabled || opts.loading ? ' disabled' : '') + '>' +
      (opts.loading ? '<span class="spinner" aria-hidden="true"></span>' : (opts.icon ? icon(opts.icon) : '') + esc(label)) +
      '</button>';
  }

  // --- Sign in -------------------------------------------------------------

  function renderSignIn() {
    var lockedFor = Math.max(0, Math.ceil((state.lockedUntil - Date.now()) / 1000));
    var locked = lockedFor > 0;
    var message = '';
    if (state.pinBusy) message = '<div class="on-navy-muted">' + esc(T.signIn.checking) + '</div>';
    else if (locked) message = '<div class="message locked">' + esc(T.signIn.lockedMinutes(Math.max(1, Math.ceil(lockedFor / 60)))) + '</div>';
    else if (state.pinError) message = '<div class="message error">' + esc(state.pinError) + '</div>';

    var dots = '';
    for (var i = 0; i < 4; i++) dots += '<span class="pin-dot' + (i < state.pin.length ? ' filled' : '') + '"></span>';

    var keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', null, '0', 'back'];
    var off = state.pinBusy || locked;
    var pad = keys.map(function (k) {
      if (k === null) return '<span></span>';
      if (k === 'back') {
        return '<button type="button" class="key back" data-action="pin-back" aria-label="' + esc(T.signIn.delete) + '"' +
          (off || !state.pin.length ? ' disabled' : '') + '>' + icon('backspace', 'lg') + '</button>';
      }
      return '<button type="button" class="key digit" data-action="pin-digit" data-digit="' + k + '"' + (off ? ' disabled' : '') + '>' + k + '</button>';
    }).join('');

    return '<main class="screen signin">' +
      '<div class="wordmark">' + esc(T.signIn.wordmark) + '</div>' +
      '<h1 class="h1">' + esc(T.signIn.title) + '</h1>' +
      '<p class="subtitle body-lg">' + esc(T.signIn.subtitle) + '</p>' +
      '<div class="pin-dots" role="progressbar" aria-label="PIN" aria-valuetext="' + state.pin.length + ' digits entered">' + dots + '</div>' +
      '<div class="message-slot">' + message + '</div>' +
      '<div class="keypad">' + pad + '</div>' +
      '<p class="forgot">' + esc(T.signIn.forgot) + '</p>' +
      '</main>';
  }

  function pressDigit(d) {
    if (state.pinBusy || state.lockedUntil > Date.now() || state.pin.length >= 4) return;
    state.pinError = null;
    state.pin += d;
    render();
    // The fourth digit is the Continue button.
    if (state.pin.length === 4) submitPin(state.pin);
  }

  function submitPin(pin) {
    state.pinBusy = true;
    render();
    api('auth/pin', { method: 'POST', body: { pin: pin } }).then(function (data) {
      store.setSession({ token: data.token, user: data.user });
      state.pin = '';
      state.pinBusy = false;
      state.screen = 'home';
      render();
      sync();
    }, function (e) {
      // Wrong digits are cleared for them — starting over on four is cheap.
      state.pin = '';
      state.pinBusy = false;
      state.pinError = e.message || T.errors.genericBody;
      if (e.kind === 'locked') state.lockedUntil = Date.now() + (e.retryAfter > 0 ? e.retryAfter : 900) * 1000;
      render();
    });
  }

  /** The server refused our token (PIN changed, access switched off). */
  function onSignedOut() {
    store.setSession(null);
    state.screen = 'signin';
    state.pin = '';
    state.pinError = T.errors.signedOutBody;
    state.vehicles = null;
    state.sheet = null;
    render();
  }

  // --- Home ----------------------------------------------------------------

  function trackingStatus() {
    if (gps.error === 'denied') return { dot: 'off', text: T.permission.title, action: 'show-permission' };
    var fresh = gps.lastFixMs && Date.now() - gps.lastFixMs < 90000;
    if (gps.watchId !== null && fresh) return { dot: 'on', text: T.home.trackingOn };
    if (gps.watchId !== null) return { dot: 'off', text: T.home.waitingGps };
    return { dot: 'off', text: T.home.trackingPaused, action: 'resume-gps' };
  }

  function installCard() {
    if (isStandalone() || state.installHidden) return '';
    var hide = '<button type="button" class="btn plain mt-2" data-action="hide-install">' + esc(T.install.dismiss) + '</button>';
    if (isIOS) {
      return '<div class="card info"><div class="row">' + icon('share') +
        '<div class="grow"><div class="h3">' + esc(T.install.iosTitle) + '</div><div class="small secondary mt-1">' + esc(T.install.iosBody) + '</div></div></div>' + hide + '</div>';
    }
    if (state.installPrompt) {
      return '<div class="card info"><div class="row">' + icon('download') +
        '<div class="grow"><div class="h3">' + esc(T.install.androidTitle) + '</div><div class="small secondary mt-1">' + esc(T.install.androidBody) + '</div></div></div>' +
        '<div class="mt-3">' + button(T.install.androidButton, 'install', { variant: 'secondary' }) + '</div>' + hide + '</div>';
    }
    return '';
  }

  function nextStopCard(stops) {
    var done = stops.filter(function (s) { return s.arrived; }).length;
    var next = stops.find(function (s) { return !s.arrived; });
    var html = '<div class="card"><div class="label muted">' + esc(next ? T.home.nextStop(done + 1, stops.length) : T.home.allStopsDone) + '</div>';
    if (next) {
      var url = 'https://www.google.com/maps/dir/?api=1&destination=' + next.lat + ',' + next.lng + '&travelmode=driving';
      html += '<div class="h2 mt-1">' + esc(next.name) + '</div>' +
        (next.time ? '<div class="body-lg secondary">' + esc(T.home.stopTime(next.time)) + '</div>' : '') +
        '<a class="btn secondary mt-3" style="text-decoration:none" href="' + esc(url) + '" target="_blank" rel="noopener">' + icon('navigation') + esc(T.home.openMaps) + '</a>' +
        '<div class="small muted mt-2">' + esc(T.home.mapsNote) + '</div>';
    }
    return html + '</div>';
  }

  function renderHome() {
    var session = store.session();
    var trip = store.trip();
    var body = '';
    var footer;

    if (trip) {
      var status = trackingStatus();
      var waiting = store.points().length;
      var lastSent = store.lastSent();
      body += '<div class="trip-card">' +
        '<div class="label gold">' + esc(T.home.onTrip) + '</div>' +
        '<div class="h1">' + esc(trip.plateNo) + '</div>' +
        (trip.vehicleName ? '<div class="body-lg on-navy-muted">' + esc(trip.vehicleName) + '</div>' : '') +
        (trip.routeName ? '<div class="gold mt-1">' + esc((trip.direction === 'dropoff' ? T.home.dropoff : T.home.pickup) + ' · ' + trip.routeName) + '</div>' : '') +
        '<div class="clock" data-clock="' + trip.startedAtMs + '"></div>' +
        '<div class="on-navy-muted">' + esc(T.home.startedAt(clock(trip.startedAtMs))) + '</div>' +
        '</div>';

      if (trip.stops && trip.stops.length) body += nextStopCard(trip.stops);

      body += (status.action ? '<button type="button" class="card row" data-action="' + status.action + '">' : '<div class="card row">') +
        '<span class="dot ' + status.dot + '"></span><span class="grow body-lg" style="font-weight:600">' + esc(status.text) + '</span>' +
        (status.action ? '</button>' : '</div>');

      if (gps.gap) {
        body += '<button type="button" class="card warn row" data-action="hide-gap">' + icon('alert') +
          '<span class="grow">' + esc(T.home.gapNotice(clock(gps.gap.from), clock(gps.gap.to))) + '</span>' + icon('close') + '</button>';
      }

      body += '<div class="card info"><div class="row">' + icon('phone') + '<div class="grow"><div class="h3">' + esc(T.home.keepOpenTitle) + '</div>' +
        '<div class="small secondary mt-1">' + esc(T.home.keepOpenBody) + '</div>' +
        (isIOS && (!wake.supported || wake.failed) ? '<div class="small secondary mt-2">' + esc(T.home.autoLockHint) + '</div>' : '') +
        '</div></div></div>';

      body += '<div class="card"><div class="secondary">' + esc(lastSent ? T.home.lastSent(clock(lastSent)) : T.home.notSentYet) + '</div>' +
        '<div class="secondary mt-1">' + esc(waiting > 0 ? T.home.waiting(waiting) : T.home.allSent) + '</div></div>';

      footer = button(T.home.stop, 'confirm-stop', { variant: 'danger', loading: state.busy });
    } else {
      body += installCard();
      if (store.endedByOffice()) {
        body += '<button type="button" class="card row" data-action="hide-ended">' +
          '<span style="color:var(--warning)">' + icon('alert') + '</span><span class="grow body-lg">' + esc(T.home.endedByOffice) + '</span>' +
          '<span class="muted">' + icon('close') + '</span></button>';
      }
      body += '<h2 class="h3 section-title mt-3">' + esc(T.home.pickTitle) + '</h2>';

      if (state.vehicles === null && !state.loadError) {
        body += '<div class="skel" style="height:76px"></div><div class="skel" style="height:76px"></div>';
      }
      if (state.loadError) {
        body += '<div class="card"><div class="body-lg">' + esc(state.loadError) + '</div><div class="mt-3">' +
          button(T.errors.retry, 'reload', { variant: 'secondary' }) + '</div></div>';
      }
      if (state.vehicles && !state.vehicles.length) {
        body += '<div class="card"><div class="h3">' + esc(T.home.noVehiclesTitle) + '</div><div class="body-lg secondary mt-1">' + esc(T.home.noVehiclesBody) + '</div></div>';
      }
      (state.vehicles || []).forEach(function (v) {
        var taken = v.busy_with !== null;
        var selected = v.id === state.selectedId;
        var details = [v.name, v.type_label, v.color].filter(Boolean).join(' · ');
        body += '<button type="button" role="radio" aria-checked="' + selected + '" class="card vehicle' + (selected ? ' selected' : '') + (taken ? ' taken' : '') +
          '" data-action="pick" data-id="' + v.id + '"' + (taken ? ' disabled' : '') + '>' +
          '<span class="veh-icon">' + icon(VEHICLE_ICON[v.vehicle_type] || 'car', 'lg') + '</span>' +
          '<span class="grow" style="flex:1;min-width:0"><span class="h3" style="display:block">' + esc(v.plate_no) + '</span>' +
          (details ? '<span class="secondary" style="display:block">' + esc(details) + '</span>' : '') +
          (taken ? '<span class="busy" style="display:block">' + esc(T.home.busyWith(v.busy_with)) + '</span>' : '') +
          '</span>' + (selected ? '<span class="check">' + icon('check') + '</span>' : '') + '</button>';
      });

      footer = button(T.home.start, 'confirm-start', { disabled: state.selectedId === null, loading: state.busy });
    }

    return '<main class="screen">' +
      '<header class="header"><h1 class="h2">' + esc(T.home.greeting(session ? session.user.name : '')) + '</h1>' +
      '<button type="button" class="icon-btn" data-action="settings" aria-label="' + esc(T.home.settings) + '">' + icon('settings') + '</button></header>' +
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
      state.loadError = e.message || T.errors.genericBody;
    }).then(render);
  }

  var syncing = null;
  function sync() {
    if (!store.session()) return Promise.resolve();
    if (syncing) return syncing;
    syncing = flush()
      .then(reconcile)
      .then(function () { render(); return store.trip() ? null : loadVehicles(); })
      .catch(function () {})
      .then(function () { syncing = null; render(); });
    return syncing;
  }

  async function begin() {
    state.sheet = null;
    if (state.selectedId === null) return;
    keepScreenOn(); // still inside the tap
    state.busy = true;
    render();
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
      toast(e.message || T.errors.genericBody);
      await loadVehicles();
    }
    state.busy = false;
    render();
  }

  async function end() {
    state.sheet = null;
    state.busy = true;
    render();
    await stopTrip();
    state.busy = false;
    render();
    loadVehicles();
  }

  function showPermissionSheet(result) {
    if (result === 'services_off') {
      state.sheet = { title: T.permission.servicesOffTitle, body: T.permission.servicesOffBody, cancel: T.permission.ok };
    } else {
      state.sheet = {
        title: T.permission.title,
        body: isIOS ? T.permission.bodyIos : T.permission.bodyAndroid,
        steps: isIOS ? T.permission.stepsIos : T.permission.stepsAndroid,
        cancel: T.permission.ok,
      };
    }
    render();
  }

  // --- Settings ------------------------------------------------------------

  function renderSettings() {
    var session = store.session();
    return '<main class="screen">' +
      '<div><button type="button" class="back-btn" data-action="home">' + icon('back') + esc(T.settings.back) + '</button></div>' +
      '<h1 class="h1 mt-2">' + esc(T.settings.title) + '</h1>' +
      '<div class="screen-body">' +
      '<div class="card"><div class="small muted">' + esc(T.settings.you) + '</div><div class="h3 mt-1">' + esc(session ? session.user.name : '') + '</div></div>' +
      '<div class="card kv"><span class="secondary">' + esc(T.settings.version) + '</span><span>' + esc(CFG.version || '') + ' (web)</span></div>' +
      '</div>' +
      '<div class="footer">' + button(T.settings.signOut, 'confirm-signout', { variant: 'secondary' }) + '</div>' +
      '</main>';
  }

  async function signOut() {
    state.sheet = null;
    if (store.trip()) await stopTrip();
    store.setSession(null);
    state.screen = 'signin';
    state.pin = '';
    state.pinError = null;
    state.vehicles = null;
    state.selectedId = null;
    render();
  }

  // --- Sheet ---------------------------------------------------------------

  function renderSheet() {
    var s = state.sheet;
    if (!s) return '';
    return '<div class="sheet-backdrop" data-action="sheet-cancel"><div class="sheet" role="dialog" aria-modal="true" aria-labelledby="sheet-title">' +
      '<h2 class="h2" id="sheet-title">' + esc(s.title) + '</h2>' +
      (s.body ? '<p class="body-lg">' + esc(s.body) + '</p>' : '') +
      (s.steps ? '<ol>' + s.steps.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ol>' : '') +
      '<div class="actions">' +
      (s.confirm ? button(s.confirm, 'sheet-confirm', { variant: s.danger ? 'danger' : '' }) : '') +
      (s.cancel ? button(s.cancel, 'sheet-cancel', { variant: 'plain' }) : '') +
      '</div></div></div>';
  }

  // --- Render --------------------------------------------------------------
  //
  // Whole-screen string templates, but the DOM is only replaced when the HTML
  // actually changed — so a status refresh cannot swallow a tap mid-press.
  // The trip clock ticks on its own below.

  var lastHtml = '';
  var lastSheet = '';

  function render() {
    var html = state.screen === 'signin' || !store.session() ? renderSignIn()
      : state.screen === 'settings' ? renderSettings()
      : renderHome();
    document.body.classList.toggle('dark', html.indexOf('class="screen signin"') !== -1);
    if (html !== lastHtml) {
      app.innerHTML = html;
      lastHtml = html;
    }
    var sheet = renderSheet();
    if (sheet !== lastSheet) {
      sheetRoot.innerHTML = sheet;
      lastSheet = sheet;
    }
    tickClock();
  }

  function tickClock() {
    var el = app.querySelector('[data-clock]');
    if (el) el.textContent = elapsed(Number(el.getAttribute('data-clock')));
  }

  setInterval(tickClock, 1000);
  setInterval(function () {
    render();
    if (state.lockedUntil && state.lockedUntil <= Date.now()) { state.lockedUntil = 0; render(); }
  }, 3000);
  // 30 s with the screen open: a reached stop moves on within about that long.
  setInterval(function () { if (store.trip() && !document.hidden) flush(); }, 30000);

  // --- Taps ----------------------------------------------------------------

  function onTap(e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    var action = el.getAttribute('data-action');
    // A tap on the sheet itself must not count as a tap on the backdrop.
    if (action === 'sheet-cancel' && el.classList.contains('sheet-backdrop') && e.target !== el) return;
    if (el.disabled) return;
    // Safari may only grant the screen-on lock from a tap.
    if (store.trip() && gps.watchId !== null && !wake.lock) keepScreenOn();

    switch (action) {
      case 'pin-digit': pressDigit(el.getAttribute('data-digit')); break;
      case 'pin-back': state.pin = state.pin.slice(0, -1); state.pinError = null; render(); break;
      case 'pick': state.selectedId = Number(el.getAttribute('data-id')); render(); break;
      case 'reload': state.loadError = null; state.vehicles = null; render(); loadVehicles(); break;
      case 'settings': state.screen = 'settings'; render(); break;
      case 'home': state.screen = 'home'; render(); break;
      case 'hide-ended': store.setEndedByOffice(false); render(); break;
      case 'hide-gap': gps.gap = null; render(); break;
      case 'hide-install': state.installHidden = true; writeJson(KEYS.installHidden, true); render(); break;
      case 'install':
        if (state.installPrompt) {
          state.installPrompt.prompt();
          state.installPrompt.userChoice.finally(function () { state.installPrompt = null; render(); });
        }
        break;
      case 'confirm-start':
        state.sheet = { title: T.home.startSheet.title, body: T.home.startSheet.body, confirm: T.home.startSheet.confirm, cancel: T.home.startSheet.cancel, onConfirm: begin };
        render();
        break;
      case 'confirm-stop':
        state.sheet = { title: T.home.stopSheet.title, body: T.home.stopSheet.body, confirm: T.home.stopSheet.confirm, cancel: T.home.stopSheet.cancel, danger: true, onConfirm: end };
        render();
        break;
      case 'confirm-signout':
        state.sheet = { title: T.settings.signOutSheet.title, body: T.settings.signOutSheet.body, confirm: T.settings.signOutSheet.confirm, cancel: T.settings.signOutSheet.cancel, danger: true, onConfirm: signOut };
        render();
        break;
      case 'show-permission': showPermissionSheet('denied'); break;
      case 'resume-gps':
        askForLocation().then(function (loc) {
          if (loc.result !== 'granted') { showPermissionSheet(loc.result); return; }
          gps.error = null;
          if (loc.pos) onFix(loc.pos);
          startGps();
          render();
        });
        break;
      case 'sheet-confirm': {
        var run = state.sheet && state.sheet.onConfirm;
        state.sheet = null;
        render();
        if (run) run();
        break;
      }
      case 'sheet-cancel': state.sheet = null; render(); break;
    }
  }

  document.addEventListener('click', onTap);

  // --- Coming and going ----------------------------------------------------

  document.addEventListener('visibilitychange', function () {
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

  window.addEventListener('online', function () { flush(); });
  window.addEventListener('pagehide', function () { if (store.trip()) gps.hiddenAtMs = gps.hiddenAtMs || Date.now(); });

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    state.installPrompt = e;
    render();
  });

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {});
    });
  }

  render();
  if (store.session()) {
    if (store.trip()) startGps();
    sync();
  }
})();
