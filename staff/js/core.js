/**
 * Staff app — the shared parts: storage, talking to the server, drawing the
 * screen, sheets, the viewer and loading photos. The two halves of the app —
 * Driver (driver.js) and Cleaning (cleaning.js) — register themselves here and
 * app.js ties them together with the PIN and the main screen.
 *
 * Screens are string templates. render() does not swap the page for the new
 * HTML: it walks the old and new trees and changes only what differs. That is
 * what lets a message be typed, a video play or a list scroll while the
 * screen keeps refreshing around it.
 */
(function () {
  'use strict';

  var CFG = window.STAFF_CONFIG || {};

  // -------------------------------------------------------------------------
  // Words shared by both halves
  // -------------------------------------------------------------------------

  var T = {
    signIn: {
      wordmark: 'AR PROPERTIES',
      title: 'Enter your PIN',
      subtitle: 'The four digits the office gave you.',
      checking: 'Checking…',
      wrongPin: 'That PIN did not work. Check it and try again.',
      lockedMinutes: function (n) { return n === 1 ? 'Try again in 1 minute.' : 'Try again in ' + n + ' minutes.'; },
      forgot: 'Forgotten your PIN? Ask the office for a new one.',
      delete: 'Delete the last digit',
    },
    home: {
      greeting: function (name) { return 'Hello, ' + name; },
      pick: 'What are you working on?',
      driver: 'Driver',
      driverHint: 'Vehicle trips',
      cleaning: 'Cleaning',
      cleaningHint: 'Your jobs',
      back: 'Home',
      settings: 'Settings',
    },
    install: {
      iosTitle: 'Add to your Home Screen',
      iosBody: 'Tap the Share button, then "Add to Home Screen". Open the app from there next time.',
      androidTitle: 'Install the app',
      androidBody: 'Put Staff on your home screen like a normal app.',
      androidButton: 'Install',
      dismiss: 'Hide this',
    },
    settings: {
      title: 'Settings',
      back: 'Back',
      you: 'You are signed in as',
      apps: 'You can use',
      signOut: 'Sign out',
      signOutSheet: {
        title: 'Sign out of the app?',
        body: 'You will need your PIN to get back in.',
        bodyTrip: 'You will need your PIN to get back in. Your running trip is stopped first.',
        confirm: 'Yes, sign out',
        cancel: 'Stay signed in',
      },
      version: 'App version',
    },
    errors: {
      offlineBody: 'No internet right now. Try again when you have signal.',
      genericBody: 'Please try again in a moment.',
      blockedBody: 'This app cannot reach the office system. Ask the office to check the app setup.',
      signedOutTitle: 'Please sign in again',
      signedOutBody: 'Enter your PIN. If it has changed, ask the office for the new one.',
      signedOutConfirm: 'Sign in now',
      signedOutCancel: 'Not now',
      retry: 'Try again',
    },
  };

  // -------------------------------------------------------------------------
  // Storage. Every access can throw (private mode, blocked site data).
  // -------------------------------------------------------------------------

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
      return true;
    } catch (e) {
      return false; // full or blocked
    }
  }

  function keysWithPrefix(prefix) {
    var out = [];
    try {
      for (var i = 0; i < localStorage.length; i++) {
        var k = localStorage.key(i);
        if (k && k.indexOf(prefix) === 0) out.push(k);
      }
    } catch (e) { /* blocked */ }
    return out;
  }

  var KEYS = {
    session: 'staff.session.v1',
    device: 'staff.deviceId.v1',
    installHidden: 'staff.installHidden.v1',
  };

  /**
   * { user: {id, name}, tokens: {cleaning: string|null, driver: string|null} }
   * One token per app: each API only accepts its own.
   */
  function session() {
    var s = readJson(KEYS.session, null);
    return s && s.user && s.user.id && s.tokens && (s.tokens.cleaning || s.tokens.driver) ? s : null;
  }

  function setSession(s) { writeJson(KEYS.session, s); }

  function token(app) {
    var s = session();
    return s ? s.tokens[app] || null : null;
  }

  function hasApp(app) { return !!token(app); }

  function apps() {
    return ['driver', 'cleaning'].filter(hasApp);
  }

  /** Per-install id the sign-in lockout counts against. Kept across sign-outs. Not a secret. */
  function deviceId() {
    var id = readJson(KEYS.device, null);
    if (id) return id;
    id = Date.now().toString(36) + Math.random().toString(36).slice(2, 10) + Math.random().toString(36).slice(2, 10);
    writeJson(KEYS.device, id);
    return id;
  }

  // -------------------------------------------------------------------------
  // Talking to the server
  // -------------------------------------------------------------------------

  function ApiError(kind, message, status, retryAfter) {
    this.kind = kind;
    this.message = message;
    this.status = status || 0;
    this.retryAfter = retryAfter || 0;
    // Worth trying again later without anyone doing anything. `unauthorized`
    // too: queued work waits for the next sign-in rather than being thrown away.
    this.retryable = kind === 'offline' || kind === 'blocked' || kind === 'unauthorized' ||
      (kind === 'server' && this.status >= 500);
  }

  function friendlyError(status, code, message, retryAfter) {
    if (code === 'bad_pin') return new ApiError('bad_pin', T.signIn.wrongPin, status);
    if (code === 'too_many_attempts') return new ApiError('locked', T.signIn.lockedMinutes(15), status, retryAfter);
    if (status === 401) return new ApiError('unauthorized', T.errors.signedOutBody, status);
    if (status === 403 || status === 503) return new ApiError('blocked', T.errors.blockedBody, status);
    // The server's own refusals are written for staff — pass them through.
    if (status === 404) return new ApiError('not_found', message || T.errors.genericBody, status);
    if (status === 409) return new ApiError('conflict', message || T.errors.genericBody, status);
    if ((status === 400 || status === 413 || status === 422) && message) return new ApiError('bad_request', message, status);
    if (status === 413) return new ApiError('bad_request', 'That file is too big to send.', status);
    return new ApiError('server', T.errors.genericBody, status);
  }

  /**
   * One request. opts:
   *   method, json (body object), form (FormData), token,
   *   requestId  — X-Ops-Request-Id: the same write sent twice is applied once
   *   background — sent by a queue or timer, not a tap: a refused token there
   *                must not throw the person out mid-job
   *   timeout    — ms; uploads pass a long one
   *   raw        — resolve with the Response (for files), not the envelope
   */
  function request(url, opts) {
    opts = opts || {};
    var headers = { Accept: 'application/json', 'X-Ops-Device-Id': deviceId() };
    if (CFG.appKey) headers['X-Ops-App-Key'] = CFG.appKey;
    if (opts.token) headers.Authorization = 'Bearer ' + opts.token;
    if (opts.requestId) headers['X-Ops-Request-Id'] = opts.requestId;
    var body;
    if (opts.json) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(opts.json); }
    if (opts.form) body = opts.form;

    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, opts.timeout || 15000) : null;

    return fetch(url, {
      method: opts.method || 'GET',
      headers: headers,
      body: body,
      signal: controller ? controller.signal : undefined,
      cache: 'no-store',
    }).then(function (response) {
      if (opts.raw && response.ok) {
        return response.blob().then(function (blob) {
          if (timer) clearTimeout(timer);
          return blob;
        });
      }
      return response.json().catch(function () { return null; }).then(function (env) {
        if (timer) clearTimeout(timer);
        if (response.ok && env && env.ok) return env.data;
        var err = env && env.error ? env.error : {};
        var failure = friendlyError(
          response.status,
          err.code || null,
          err.message || null,
          Number((err.details && err.details.retry_after) || 0)
        );
        if (failure.kind === 'unauthorized' && opts.token && !opts.background) onRefused();
        throw failure;
      });
    }, function () {
      if (timer) clearTimeout(timer);
      throw new ApiError('offline', T.errors.offlineBody);
    });
  }

  // -------------------------------------------------------------------------
  // Little helpers
  // -------------------------------------------------------------------------

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var pad = function (n) { return String(n).padStart(2, '0'); };
  function clock(ms) { var d = new Date(ms); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }

  /** The server's DATETIME shape, in phone time: 2026-09-28 14:05:00. */
  function nowStamp(ms) {
    var d = ms ? new Date(ms) : new Date();
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' +
      pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }

  function uid() {
    return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
  }

  var ua = navigator.userAgent || '';
  var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isStandalone = function () {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  };

  // Icons: Feather/Lucide line icons, drawn inline so nothing loads.
  var ICON = {
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    check: '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    tick: '<polyline points="20 6 9 17 4 12"/>',
    alert: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    close: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    navigation: '<polygon points="3 11 22 2 13 21 11 13 3 11"/>',
    backspace: '<path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><line x1="18" y1="9" x2="12" y2="15"/><line x1="12" y1="9" x2="18" y2="15"/>',
    back: '<polyline points="15 18 9 12 15 6"/>',
    chevron: '<polyline points="9 18 15 12 9 6"/>',
    share: '<path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
    phone: '<rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
    car: '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',
    truck: '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
    bus: '<path d="M8 6v6"/><path d="M15 6v6"/><path d="M2 12h19.6"/><path d="M18 18h3s.5-1.7.8-2.8c.1-.4.2-.8.2-1.2 0-.4-.1-.8-.2-1.2l-1.4-5C20.1 6.8 19.1 6 18 6H4a2 2 0 0 0-2 2v10h3"/><circle cx="7" cy="18" r="2"/><path d="M9 18h5"/><circle cx="16" cy="18" r="2"/>',
    bike: '<circle cx="18.5" cy="17.5" r="3.5"/><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="M12 17.5V14l-3-3 4-3 2 3h2"/>',
    sparkles: '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/>',
    wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    pin: '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    note: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    camera: '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
    video: '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
    gallery: '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    mic: '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>',
    send: '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
    play: '<polygon points="6 3 20 12 6 21 6 3"/>',
    pause: '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>',
    plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
    box: '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
    search: '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    list: '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    sun: '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
    home: '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    minus: '<line x1="5" y1="12" x2="19" y2="12"/>',
    circle: '<circle cx="12" cy="12" r="9"/>',
    refresh: '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
  };

  function icon(name, cls) {
    return '<svg class="icon ' + (cls || '') + '" viewBox="0 0 24 24" aria-hidden="true">' + (ICON[name] || '') + '</svg>';
  }

  function button(label, action, opts) {
    opts = opts || {};
    return '<button type="button" class="btn ' + (opts.variant || '') + '" data-action="' + action + '"' +
      (opts.data ? ' ' + opts.data : '') +
      (opts.disabled || opts.loading ? ' disabled' : '') +
      (opts.style ? ' style="' + opts.style + '"' : '') + '>' +
      (opts.loading ? '<span class="spinner" aria-hidden="true"></span>' : (opts.icon ? icon(opts.icon) : '') + esc(label)) +
      '</button>';
  }

  var toastEl = document.getElementById('toast');
  var toastTimer = null;
  function toast(message) {
    if (!message) return;
    toastEl.textContent = message;
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 6000);
  }
  toastEl.addEventListener('click', function () { toastEl.hidden = true; });

  // -------------------------------------------------------------------------
  // Loading photos, clips and voice notes
  //
  // Files sit behind the same token as the JSON, so a plain <img src> cannot
  // fetch them. They are fetched here, kept as object URLs, and the screen
  // draws once each arrives.
  // -------------------------------------------------------------------------

  var mediaCache = {};
  var mediaOrder = [];
  var MEDIA_MAX = 120;

  /** The object URL for `key`, or null while it loads. `loader` returns a Promise<Blob>. */
  function media(key, loader) {
    var m = mediaCache[key];
    if (m) return m.url || null;
    mediaCache[key] = { loading: true };
    loader().then(function (blob) {
      mediaCache[key] = { url: URL.createObjectURL(blob) };
      mediaOrder.push(key);
      while (mediaOrder.length > MEDIA_MAX) {
        var old = mediaOrder.shift();
        if (mediaCache[old] && mediaCache[old].url) URL.revokeObjectURL(mediaCache[old].url);
        delete mediaCache[old];
      }
      render();
    }, function () {
      mediaCache[key] = { failed: true };
      render();
    });
    return null;
  }

  // -------------------------------------------------------------------------
  // Files from the phone: picking, shrinking photos, making voice notes playable
  // -------------------------------------------------------------------------

  var picker = null;

  /** Open the phone's camera or file picker. Resolves with the chosen files. */
  function pickFiles(accept, capture, multiple) {
    if (picker && picker.parentNode) picker.parentNode.removeChild(picker);
    return new Promise(function (resolve) {
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = accept;
      if (capture) input.setAttribute('capture', 'environment');
      if (multiple) input.multiple = true;
      input.style.display = 'none';
      input.addEventListener('change', function () {
        var list = Array.prototype.slice.call(input.files || []);
        if (input.parentNode) input.parentNode.removeChild(input);
        resolve(list);
      });
      document.body.appendChild(input);
      picker = input;
      input.click();
    });
  }

  function extOf(name) { var m = /\.([a-z0-9]+)$/i.exec(name || ''); return m ? m[1].toLowerCase() : ''; }

  /** A photo shrunk for 3G: at most 1600 px, JPEG. Resolves {blob, name}. */
  function preparePhoto(file, notPhotoMessage) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var scale = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
        var canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (b) { resolve(b); }, 'image/jpeg', 0.7);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(null); };
      img.src = url;
    }).then(function (blob) {
      if (blob) return { blob: blob, name: 'photo.jpg' };
      var ext = extOf(file.name);
      if (['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) !== -1) return { blob: file, name: 'photo.' + ext };
      throw new Error(notPhotoMessage || 'Only JPG, PNG, GIF or WEBP photos are allowed.');
    });
  }

  function decodeAudio(context, buffer) {
    return new Promise(function (resolve, reject) {
      var maybe = context.decodeAudioData(buffer, resolve, reject);
      if (maybe && maybe.then) maybe.then(resolve, reject);
    });
  }

  /**
   * 16-bit PCM WAV, mono, 22.05 kHz — what the server takes and every phone
   * plays. Same as the office's web composer (modules/operations/job_view.php).
   */
  function toWav(blob) {
    var Context = window.AudioContext || window.webkitAudioContext;
    return blob.arrayBuffer().then(function (buffer) {
      var context = new Context();
      return decodeAudio(context, buffer).then(function (audio) {
        if (context.close) context.close();
        var rate = 22050;
        var step = audio.sampleRate / rate;
        var count = Math.max(1, Math.floor(audio.length / step));
        var tracks = [];
        for (var c = 0; c < audio.numberOfChannels; c++) tracks.push(audio.getChannelData(c));
        var view = new DataView(new ArrayBuffer(44 + count * 2));
        var text = function (o, v) { for (var i = 0; i < v.length; i++) view.setUint8(o + i, v.charCodeAt(i)); };
        text(0, 'RIFF'); view.setUint32(4, 36 + count * 2, true); text(8, 'WAVE'); text(12, 'fmt ');
        view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
        view.setUint32(24, rate, true); view.setUint32(28, rate * 2, true); view.setUint16(32, 2, true);
        view.setUint16(34, 16, true); text(36, 'data'); view.setUint32(40, count * 2, true);
        for (var i = 0; i < count; i++) {
          var at = Math.floor(i * step);
          var sum = 0;
          for (var t = 0; t < tracks.length; t++) sum += tracks[t][at] || 0;
          var s = Math.max(-1, Math.min(1, sum / tracks.length));
          view.setInt16(44 + i * 2, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
        }
        return new Blob([view.buffer], { type: 'audio/wav' });
      });
    });
  }

  /** A finished recording as an uploadable voice note: Safari's AAC stays .m4a, anything else becomes WAV. */
  function voiceFile(blob, mimeType) {
    if ((mimeType || '').indexOf('audio/mp4') === 0) return Promise.resolve({ blob: blob, name: 'voice.m4a' });
    return toWav(blob).then(function (wav) { return { blob: wav, name: 'voice.wav' }; });
  }

  /** The recorder type to ask for: AAC on iPhones (plays everywhere), the browser's own elsewhere. */
  function recorderMime() {
    return isIOS && window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported('audio/mp4') ? 'audio/mp4' : '';
  }

  function mediaFailed(key) { return !!(mediaCache[key] && mediaCache[key].failed); }
  function mediaForget(key) { delete mediaCache[key]; }

  // -------------------------------------------------------------------------
  // Screens: routes, drawing, sheets
  // -------------------------------------------------------------------------

  var modules = {};
  function register(name, mod) { modules[name] = mod; }

  var state = {
    route: { app: 'home' },
    sheet: null,
    installPrompt: null,
    installHidden: readJson(KEYS.installHidden, false),
    refused: false,
    refusalWaved: false,
  };

  var app = document.getElementById('app');
  var sheetRoot = document.getElementById('sheet-root');
  var viewerRoot = document.getElementById('viewer-root');

  /**
   * Move to a screen. Pushes a history entry so the phone's Back button walks
   * back through the app rather than out of it.
   */
  function go(route, replace) {
    state.route = route;
    try {
      if (replace) history.replaceState({ route: route }, '');
      else history.pushState({ route: route }, '');
    } catch (e) { /* ignore */ }
    render();
    window.scrollTo(0, 0);
  }

  function back() {
    if (history.state && history.length > 1) history.back();
    else go(homeRoute(), true);
  }

  window.addEventListener('popstate', function (e) {
    state.route = (e.state && e.state.route) || homeRoute();
    closeViewer();
    render();
  });

  /** Where "home" is: the choice of apps, or the only app this person has. */
  function homeRoute() {
    var list = apps();
    return list.length === 1 ? { app: list[0] } : { app: 'home' };
  }

  // --- Changing only what changed -------------------------------------------

  function morphAttrs(from, to) {
    var a, i;
    for (i = from.attributes.length - 1; i >= 0; i--) {
      a = from.attributes[i];
      if (!to.hasAttribute(a.name)) from.removeAttribute(a.name);
    }
    for (i = 0; i < to.attributes.length; i++) {
      a = to.attributes[i];
      if (from.getAttribute(a.name) !== a.value) from.setAttribute(a.name, a.value);
    }
  }

  function sameNode(a, b) {
    if (a.nodeType !== b.nodeType) return false;
    if (a.nodeType !== 1) return true;
    if (a.nodeName !== b.nodeName) return false;
    var ka = a.getAttribute('data-key');
    var kb = b.getAttribute('data-key');
    return ka === kb;
  }

  function morph(from, to) {
    if (from.nodeType === 3 || from.nodeType === 8) {
      if (from.nodeValue !== to.nodeValue) from.nodeValue = to.nodeValue;
      return;
    }
    morphAttrs(from, to);
    var tag = from.nodeName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
      // What the person is typing belongs to them, not to the template.
      if (from !== document.activeElement) {
        var v = tag === 'TEXTAREA' ? to.textContent : to.getAttribute('value') || '';
        if (from.value !== v) from.value = v;
      }
      if (tag === 'INPUT') from.checked = to.hasAttribute('checked');
      if (tag === 'TEXTAREA') return;
    }
    morphChildren(from, to);
  }

  function morphChildren(from, to) {
    var fc = from.firstChild;
    var tc = to.firstChild;
    while (tc) {
      var nextT = tc.nextSibling;
      if (!fc) {
        from.appendChild(tc);
      } else if (sameNode(fc, tc)) {
        morph(fc, tc);
        fc = fc.nextSibling;
      } else {
        var replaced = fc;
        fc = fc.nextSibling;
        from.replaceChild(tc, replaced);
      }
      tc = nextT;
    }
    while (fc) {
      var nextF = fc.nextSibling;
      from.removeChild(fc);
      fc = nextF;
    }
  }

  function patch(root, html) {
    var tpl = document.createElement(root.nodeName);
    tpl.innerHTML = html;
    morphChildren(root, tpl); // the root keeps its own id and attributes
  }

  var lastHtml = '';
  var lastSheet = '';
  var rendering = false;
  var renderAgain = false;

  function currentHtml() {
    var s = session();
    if (!s || state.route.app === 'signin') return modules.app.renderSignIn();
    var r = state.route;
    if (r.app === 'settings') return modules.app.renderSettings();
    if ((r.app === 'driver' || r.app === 'cleaning') && hasApp(r.app) && modules[r.app]) {
      return modules[r.app].render(r);
    }
    // Home, or an app this person no longer has.
    var list = apps();
    if (list.length === 1 && modules[list[0]]) {
      state.route = { app: list[0] };
      return modules[list[0]].render(state.route);
    }
    return modules.app.renderHome();
  }

  function render() {
    if (rendering) { renderAgain = true; return; }
    rendering = true;
    try {
      var html = currentHtml();
      document.body.classList.toggle('dark', html.indexOf('class="screen signin"') !== -1);
      if (html !== lastHtml) {
        if (!lastHtml) app.innerHTML = html;
        else patch(app, html);
        lastHtml = html;
      }
      var sheet = renderSheet();
      if (sheet !== lastSheet) {
        if (!lastSheet || !sheet) sheetRoot.innerHTML = sheet;
        else patch(sheetRoot, sheet);
        lastSheet = sheet;
      }
      Object.keys(modules).forEach(function (k) { if (modules[k].afterRender) modules[k].afterRender(); });
    } finally {
      rendering = false;
    }
    if (renderAgain) { renderAgain = false; render(); }
  }

  /**
   * A sheet: { title, body, steps, html, confirm, cancel, danger,
   *            onConfirm, onCancel, actions: [{label, action, variant}] }
   */
  function sheet(s) { state.sheet = s; render(); }
  function closeSheet() { state.sheet = null; render(); }

  function renderSheet() {
    var s = state.sheet;
    if (!s && state.refused && session()) {
      s = {
        title: T.errors.signedOutTitle,
        body: T.errors.signedOutBody,
        confirm: T.errors.signedOutConfirm,
        cancel: T.errors.signedOutCancel,
        refusal: true,
      };
    }
    if (!s) return '';
    return '<div class="sheet-backdrop" data-action="core:sheet-cancel"><div class="sheet' + (s.tall ? ' tall' : '') + '" role="dialog" aria-modal="true" aria-labelledby="sheet-title">' +
      (s.title ? '<h2 class="h2" id="sheet-title">' + esc(s.title) + '</h2>' : '') +
      (s.body ? '<p class="body-lg">' + esc(s.body) + '</p>' : '') +
      (s.steps ? '<ol>' + s.steps.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ol>' : '') +
      (typeof s.html === 'function' ? s.html() : s.html || '') +
      '<div class="actions">' +
      (s.actions || []).map(function (a) { return button(a.label, a.action, { variant: a.variant || 'secondary', data: a.data }); }).join('') +
      (s.confirm ? button(s.confirm, 'core:sheet-confirm', { variant: s.danger ? 'danger' : '' }) : '') +
      (s.cancel ? button(s.cancel, 'core:sheet-cancel', { variant: 'plain' }) : '') +
      '</div></div></div>';
  }

  // --- Full-screen viewer (photos, clips). Its own root, never redrawn by render(). ---

  function openViewer(html) {
    viewerRoot.innerHTML = '<div class="viewer" role="dialog" aria-modal="true">' +
      '<button type="button" class="viewer-close" data-action="core:viewer-close" aria-label="Close">' + icon('close', 'lg') + '</button>' +
      '<div class="viewer-body">' + html + '</div></div>';
    document.body.classList.add('viewing');
  }

  function closeViewer() {
    if (!viewerRoot.innerHTML) return;
    var v = viewerRoot.querySelector('video');
    if (v) { try { v.pause(); } catch (e) { /* ignore */ } }
    viewerRoot.innerHTML = '';
    document.body.classList.remove('viewing');
  }

  // --- Refused token ---------------------------------------------------------

  /**
   * The server refused a token — the PIN changed, access was switched off.
   * Ask, do not act: nothing is lost by the person finishing what they are
   * looking at first, and queued work waits for the next sign-in either way.
   */
  function onRefused() {
    Object.keys(modules).forEach(function (k) { if (modules[k].onRefused) modules[k].onRefused(); });
    if (state.refusalWaved) return;
    state.refused = true;
    render();
  }

  // --- Install ---------------------------------------------------------------

  function installCard() {
    if (isStandalone() || state.installHidden) return '';
    var hide = '<button type="button" class="btn plain mt-2" data-action="core:hide-install">' + esc(T.install.dismiss) + '</button>';
    if (isIOS) {
      return '<div class="card info"><div class="row">' + icon('share') +
        '<div class="grow"><div class="h3">' + esc(T.install.iosTitle) + '</div><div class="small secondary mt-1">' + esc(T.install.iosBody) + '</div></div></div>' + hide + '</div>';
    }
    if (state.installPrompt) {
      return '<div class="card info"><div class="row">' + icon('download') +
        '<div class="grow"><div class="h3">' + esc(T.install.androidTitle) + '</div><div class="small secondary mt-1">' + esc(T.install.androidBody) + '</div></div></div>' +
        '<div class="mt-3">' + button(T.install.androidButton, 'core:install', { variant: 'secondary' }) + '</div>' + hide + '</div>';
    }
    return '';
  }

  /**
   * The header of an app's first screen: Home (when there is a choice of
   * apps), a title, and Settings.
   */
  function topHeader(title, sub) {
    var choice = apps().length > 1;
    return '<header class="header">' +
      (choice ? '<button type="button" class="icon-btn" data-action="core:home" aria-label="' + esc(T.home.back) + '">' + icon('home') + '</button>' : '') +
      '<div class="grow"><h1 class="h2">' + esc(title) + '</h1>' + (sub ? '<div class="secondary">' + esc(sub) + '</div>' : '') + '</div>' +
      '<button type="button" class="icon-btn" data-action="core:settings" aria-label="' + esc(T.home.settings) + '">' + icon('settings') + '</button></header>';
  }

  function backBar(action, label, right) {
    return '<div class="topbar"><button type="button" class="back-btn" data-action="' + action + '">' + icon('back') + esc(label || T.settings.back) + '</button>' +
      (right || '') + '</div>';
  }

  // -------------------------------------------------------------------------
  // Taps and typing
  // -------------------------------------------------------------------------

  var coreActions = {
    'sheet-confirm': function () {
      var s = state.sheet;
      if (!s && state.refused) {
        state.refused = false;
        modules.app.signOut(true);
        return;
      }
      state.sheet = null;
      render();
      if (s && s.onConfirm) s.onConfirm();
    },
    'sheet-cancel': function () {
      var s = state.sheet;
      if (!s && state.refused) {
        state.refused = false;
        state.refusalWaved = true;
        render();
        return;
      }
      state.sheet = null;
      render();
      if (s && s.onCancel) s.onCancel();
    },
    'viewer-close': closeViewer,
    home: function () { go({ app: 'home' }); },
    settings: function () { go({ app: 'settings' }); },
    back: back,
    'hide-install': function () { state.installHidden = true; writeJson(KEYS.installHidden, true); render(); },
    install: function () {
      if (!state.installPrompt) return;
      state.installPrompt.prompt();
      state.installPrompt.userChoice.finally(function () { state.installPrompt = null; render(); });
    },
  };

  function onTap(e) {
    var el = e.target.closest('[data-action]');
    if (!el) return;
    var action = el.getAttribute('data-action');
    // A tap on the sheet itself must not count as a tap on the backdrop.
    if (el.classList.contains('sheet-backdrop') && e.target !== el) return;
    if (el.disabled) return;
    var at = action.indexOf(':');
    var scope = at === -1 ? 'core' : action.slice(0, at);
    var name = at === -1 ? action : action.slice(at + 1);
    Object.keys(modules).forEach(function (k) { if (modules[k].onAnyTap) modules[k].onAnyTap(); });
    if (scope === 'core') {
      if (coreActions[name]) coreActions[name](el, e);
      return;
    }
    if (modules[scope] && modules[scope].action) modules[scope].action(name, el, e);
  }

  document.addEventListener('click', onTap);
  document.addEventListener('input', function (e) {
    Object.keys(modules).forEach(function (k) { if (modules[k].input) modules[k].input(e); });
  });
  document.addEventListener('change', function (e) {
    Object.keys(modules).forEach(function (k) { if (modules[k].change) modules[k].change(e); });
  });

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    state.installPrompt = e;
    render();
  });

  // -------------------------------------------------------------------------

  window.Staff = {
    CFG: CFG,
    T: T,
    readJson: readJson,
    writeJson: writeJson,
    keysWithPrefix: keysWithPrefix,
    KEYS: KEYS,
    session: session,
    setSession: setSession,
    token: token,
    hasApp: hasApp,
    apps: apps,
    deviceId: deviceId,
    ApiError: ApiError,
    request: request,
    esc: esc,
    pad: pad,
    clock: clock,
    nowStamp: nowStamp,
    uid: uid,
    isIOS: isIOS,
    isStandalone: isStandalone,
    ICON: ICON,
    icon: icon,
    button: button,
    toast: toast,
    media: media,
    pickFiles: pickFiles,
    extOf: extOf,
    preparePhoto: preparePhoto,
    toWav: toWav,
    voiceFile: voiceFile,
    recorderMime: recorderMime,
    mediaFailed: mediaFailed,
    mediaForget: mediaForget,
    register: register,
    modules: modules,
    state: state,
    go: go,
    back: back,
    homeRoute: homeRoute,
    render: render,
    sheet: sheet,
    closeSheet: closeSheet,
    openViewer: openViewer,
    closeViewer: closeViewer,
    installCard: installCard,
    topHeader: topHeader,
    backBar: backBar,
  };
})();
