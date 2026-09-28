/**
 * Staff app — the PIN, the main screen (Driver / Cleaning), Settings, and
 * starting up. One PIN opens both halves: api/mobile/staff/signin.php checks it
 * once and hands back a token for each app this person may use. Someone with
 * only one of them never sees the main screen — they go straight in.
 */
(function () {
  'use strict';

  var S = window.Staff;
  var T = S.T;
  var esc = S.esc;
  var icon = S.icon;
  var button = S.button;

  var pin = { value: '', busy: false, error: null, lockedUntil: 0 };

  // --- Sign in ---------------------------------------------------------------

  function renderSignIn() {
    var lockedFor = Math.max(0, Math.ceil((pin.lockedUntil - Date.now()) / 1000));
    var locked = lockedFor > 0;
    var message = '';
    if (pin.busy) message = '<div class="on-navy-muted">' + esc(T.signIn.checking) + '</div>';
    else if (locked) message = '<div class="message locked">' + esc(T.signIn.lockedMinutes(Math.max(1, Math.ceil(lockedFor / 60)))) + '</div>';
    else if (pin.error) message = '<div class="message error">' + esc(pin.error) + '</div>';

    var dots = '';
    for (var i = 0; i < 4; i++) dots += '<span class="pin-dot' + (i < pin.value.length ? ' filled' : '') + '"></span>';

    var off = pin.busy || locked;
    var keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', null, '0', 'back'].map(function (k) {
      if (k === null) return '<span></span>';
      if (k === 'back') {
        return '<button type="button" class="key back" data-action="app:pin-back" aria-label="' + esc(T.signIn.delete) + '"' +
          (off || !pin.value.length ? ' disabled' : '') + '>' + icon('backspace', 'lg') + '</button>';
      }
      return '<button type="button" class="key digit" data-action="app:pin-digit" data-digit="' + k + '"' + (off ? ' disabled' : '') + '>' + k + '</button>';
    }).join('');

    return '<main class="screen signin">' +
      '<div class="wordmark">' + esc(T.signIn.wordmark) + '</div>' +
      '<h1 class="h1">' + esc(T.signIn.title) + '</h1>' +
      '<p class="subtitle body-lg">' + esc(T.signIn.subtitle) + '</p>' +
      '<div class="pin-dots" role="progressbar" aria-label="PIN" aria-valuetext="' + pin.value.length + ' digits entered">' + dots + '</div>' +
      '<div class="message-slot">' + message + '</div>' +
      '<div class="keypad">' + keys + '</div>' +
      '<p class="forgot">' + esc(T.signIn.forgot) + '</p>' +
      '</main>';
  }

  function pressDigit(d) {
    if (pin.busy || pin.lockedUntil > Date.now() || pin.value.length >= 4) return;
    pin.error = null;
    pin.value += d;
    S.render();
    // The fourth digit is the Continue button.
    if (pin.value.length === 4) submitPin(pin.value);
  }

  function submitPin(value) {
    pin.busy = true;
    S.render();
    S.request(S.CFG.signinUrl, { method: 'POST', json: { pin: value } }).then(function (data) {
      S.setSession({
        user: data.user,
        tokens: {
          cleaning: data.apps && data.apps.cleaning ? data.apps.cleaning.token : null,
          driver: data.apps && data.apps.driver ? data.apps.driver.token : null,
        },
      });
      pin.value = '';
      pin.busy = false;
      pin.error = null;
      S.state.refused = false;
      S.state.refusalWaved = false;
      startApps();
      S.go(firstRoute(), true);
    }, function (e) {
      // Wrong digits are cleared for them — starting over on four is cheap.
      pin.value = '';
      pin.busy = false;
      pin.error = e.message || T.errors.genericBody;
      if (e.kind === 'locked') pin.lockedUntil = Date.now() + (e.retryAfter > 0 ? e.retryAfter : 900) * 1000;
      S.render();
    });
  }

  /** Signed out on purpose (Settings) or because the server refused the token and they said so. */
  function signOut(refused) {
    var mods = S.modules;
    return Promise.all(['driver', 'cleaning'].map(function (k) {
      return mods[k] && mods[k].beforeSignOut ? mods[k].beforeSignOut() : null;
    })).catch(function () {}).then(function () {
      S.setSession(null);
      S.state.sheet = null;
      S.state.refused = false;
      S.state.refusalWaved = false;
      pin.value = '';
      pin.error = refused ? T.errors.signedOutBody : null;
      S.closeViewer();
      S.go({ app: 'home' }, true);
    });
  }

  // --- Main screen: Driver or Cleaning --------------------------------------

  function renderHome() {
    var s = S.session();
    var tiles = [
      { app: 'driver', title: T.home.driver, hint: T.home.driverHint, icon: 'car' },
      { app: 'cleaning', kind: 'cleaning', title: T.home.cleaning, hint: T.home.cleaningHint, icon: 'sparkles' },
      { app: 'cleaning', kind: 'maintenance', title: T.home.maintenance, hint: T.home.maintenanceHint, icon: 'wrench' },
    ].filter(function (t) { return S.hasApp(t.app); }).map(function (t) {
      var mod = S.modules[t.app];
      var status = mod && mod.tileStatus ? mod.tileStatus(t.kind) : null;
      var key = t.kind || t.app;
      return '<button type="button" class="app-tile" data-key="' + key + '" data-action="app:open" data-app="' + t.app + '" data-kind="' + (t.kind || '') + '">' +
        '<span class="app-icon">' + icon(t.icon, 'xl') + '</span>' +
        '<span class="grow"><span class="h2" style="display:block">' + esc(t.title) + '</span>' +
        '<span class="secondary" style="display:block">' + esc(status || t.hint) + '</span></span>' +
        icon('chevron') + '</button>';
    }).join('');

    return '<main class="screen">' +
      '<header class="header"><div class="grow"><h1 class="h2">' + esc(T.home.greeting(s ? String(s.user.name).trim().split(/\s+/)[0] : '')) + '</h1></div>' +
      '<button type="button" class="icon-btn" data-action="core:settings" aria-label="' + esc(T.home.settings) + '">' + icon('settings') + '</button></header>' +
      '<div class="screen-body">' + S.installCard() +
      (S.modules.cleaning && S.modules.cleaning.attendanceBar ? S.modules.cleaning.attendanceBar() : '') +
      '<h2 class="h3 section-title mt-4">' + esc(T.home.pick) + '</h2>' + tiles + '</div>' +
      '</main>';
  }

  // --- Settings --------------------------------------------------------------

  function renderSettings() {
    var s = S.session();
    var names = S.apps().map(function (a) { return a === 'driver' ? T.home.driver : T.home.cleaning + ' · ' + T.home.maintenance; }).join(' · ');
    return '<main class="screen">' +
      S.backBar('core:back', T.settings.back) +
      '<h1 class="h1 mt-2">' + esc(T.settings.title) + '</h1>' +
      '<div class="screen-body">' +
      '<div class="card"><div class="small muted">' + esc(T.settings.you) + '</div><div class="h3 mt-1">' + esc(s ? s.user.name : '') + '</div></div>' +
      '<div class="card kv"><span class="secondary">' + esc(T.settings.apps) + '</span><span>' + esc(names) + '</span></div>' +
      '<div class="card kv"><span class="secondary">' + esc(T.settings.version) + '</span><span>' + esc(S.CFG.version || '') + ' (web)</span></div>' +
      '</div>' +
      '<div class="footer">' + button(T.settings.signOut, 'app:confirm-signout', { variant: 'secondary' }) + '</div>' +
      '</main>';
  }

  // --- Taps ------------------------------------------------------------------

  function action(name, el) {
    switch (name) {
      case 'pin-digit': pressDigit(el.getAttribute('data-digit')); break;
      case 'pin-back': pin.value = pin.value.slice(0, -1); pin.error = null; S.render(); break;
      case 'open': S.go({ app: el.getAttribute('data-app'), kind: el.getAttribute('data-kind') || undefined }); break;
      case 'confirm-signout': {
        var trip = S.modules.driver && S.modules.driver.hasTrip && S.modules.driver.hasTrip();
        S.sheet({
          title: T.settings.signOutSheet.title,
          body: trip ? T.settings.signOutSheet.bodyTrip : T.settings.signOutSheet.body,
          confirm: T.settings.signOutSheet.confirm,
          cancel: T.settings.signOutSheet.cancel,
          danger: true,
          onConfirm: function () { signOut(false); },
        });
        break;
      }
    }
  }

  // --- Starting up -----------------------------------------------------------

  function startApps() {
    ['driver', 'cleaning'].forEach(function (k) {
      if (S.hasApp(k) && S.modules[k] && S.modules[k].start) S.modules[k].start();
    });
  }

  /** A running trip opens straight to it; otherwise the choice, or the only app. */
  function firstRoute() {
    if (S.hasApp('driver') && S.modules.driver.hasTrip()) return { app: 'driver' };
    return S.homeRoute();
  }

  S.register('app', {
    renderSignIn: renderSignIn,
    renderHome: renderHome,
    renderSettings: renderSettings,
    action: action,
    signOut: signOut,
  });

  // The trip clock, the tracking dot and the lockout count down on their own.
  setInterval(function () {
    if (pin.lockedUntil && pin.lockedUntil <= Date.now()) pin.lockedUntil = 0;
    if (!document.hidden) S.render();
  }, 3000);

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {});
    });
  }

  var restored = history.state && history.state.route;
  if (S.session()) {
    startApps();
    S.state.route = restored || firstRoute();
  } else {
    S.state.route = { app: 'home' };
  }
  try { history.replaceState({ route: S.state.route }, ''); } catch (e) { /* ignore */ }
  S.render();
})();
