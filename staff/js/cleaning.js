/**
 * Staff app — Cleaning. The same jobs, rules and API (api/mobile/ops) as the
 * Operations field app, OPERATION-MOBILE-APP ("My Jobs"):
 *
 *   list      Today / Late / Next / Done, check in and out, tenant requests
 *   job       Before photos -> Start -> After photos (+ checklist) -> Finish,
 *             Pause with a reason
 *   messages  text, photos, videos and hold-to-talk voice notes, and "ask the
 *             office for materials"
 *   new job   type + places, no typing
 *
 * NOTHING WAITS FOR THE NETWORK. Every write goes into the send queue below,
 * the screen changes on the tap, and the queue delivers when it can — oldest
 * first, each write with its own request id so a repeat is recognised, and
 * the moment of the tap sent as client_at. Files wait in IndexedDB. A job
 * raised with no signal lives under a negative id until the server makes it,
 * and everything queued behind it is pointed at the real id then. Claiming a
 * tenant request stays online-only, as in the app: only the server knows who
 * tapped first.
 *
 * Keep in step with OPERATION-MOBILE-APP/src/api/queue.ts and hooks.ts.
 */
(function () {
  'use strict';

  var S = window.Staff;
  var esc = S.esc;
  var icon = S.icon;
  var button = S.button;
  var pad = S.pad;
  var nowStamp = S.nowStamp;
  var API_BASE = String(S.CFG.opsBase || '../api/mobile/ops').replace(/\/+$/, '');

  // -------------------------------------------------------------------------
  // Words — OPERATION-MOBILE-APP/src/i18n/en.ts
  // -------------------------------------------------------------------------

  var T = {
    title: 'Cleaning',
    titles: { cleaning: 'Cleaning', maintenance: 'Maintenance' },
    greeting: function (name) { return 'Hello, ' + name; },
    status: { open: 'Not started', in_progress: 'In progress', done: 'Completed', cancelled: 'Cancelled' },
    jobType: { cleaning: 'Cleaning', maintenance: 'Maintenance' },
    jobs: {
      tabs: { today: 'Today', overdue: 'Late', upcoming: 'Next', done: 'Done' },
      urgent: 'Urgent',
      late: 'Late',
      anyTime: 'Any time',
      empty: {
        today: ['No jobs today.', 'Tap New job when you start something.'],
        overdue: ['Nothing late.', 'Everything is finished on time. Well done.'],
        upcoming: ['Nothing coming up yet.', 'Jobs dated for a later day will show here.'],
        done: ['Nothing finished yet.', 'Jobs you complete will show here.'],
      },
      newJob: 'New job',
      requestsWaiting: function (n) { return n === 1 ? '1 tenant request waiting' : n + ' tenant requests waiting'; },
      tileToday: function (n) { return n === 1 ? '1 job today' : n + ' jobs today'; },
    },
    attendance: {
      checkIn: 'Check in',
      checkOut: 'Check out',
      checkedInAt: function (time) { return 'Checked in at ' + time; },
      dayDone: function (a, b, h) { return 'Today: ' + a + ' – ' + b + ' · ' + h; },
      checkOutTitle: 'Check out for today?',
      checkOutBody: 'This is your last entry today. You cannot check in again until tomorrow.',
      checkOutConfirm: 'Yes, check out',
      checkOutCancel: 'Not yet',
    },
    requests: {
      title: 'Tenant requests',
      back: 'Back',
      intro: 'Tap a request to take it. It moves to your jobs.',
      fromTenant: 'Tenant',
      fromCleaner: 'Found by cleaner',
      emptyTitle: 'Nothing waiting.',
      emptyBody: 'New tenant requests will show here.',
      confirmTitle: 'Take this job?',
      confirmBody: 'It moves to your jobs and nobody else can take it.',
      confirm: "Yes, I'll do it",
      cancel: 'Not now',
    },
    newJob: {
      title: 'New job',
      titleFor: { cleaning: 'New cleaning job', maintenance: 'New maintenance job' },
      back: 'Back',
      typeLabel: 'Type',
      placesLabel: 'Where?',
      choosePlaces: 'Choose places',
      addMorePlaces: 'Add more places',
      placeRequired: 'Choose at least one place for this job.',
      create: 'Create job',
      createUnmatched: 'The office system did not return this job. Check your list when you have signal.',
      notCreatedYet: 'This job was not created on the office system, so this could not be sent. Try sending the job again first.',
    },
    places: {
      title: 'Where is the job?',
      close: 'Close',
      searchPlaceholder: 'Search — unit number, corridor, lobby…',
      loading: 'Loading places…',
      noMatch: 'Nothing matches that.',
      none: 'No places for this building yet. Ask the office to add them.',
      more: 'Type to search for the rest.',
      done: 'Done',
      doneCount: function (n) { return n === 1 ? 'Done — 1 place' : 'Done — ' + n + ' places'; },
      summary: function (labels) {
        return labels.length <= 3 ? labels.join(' · ') : labels.slice(0, 3).join(' · ') + ' +' + (labels.length - 3) + ' more';
      },
    },
    job: {
      back: 'Back',
      whatToDo: 'What to do',
      tenantPhotos: "Tenant's photos",
      startedAt: function (time) { return 'Started at ' + time; },
      start: 'Start job',
      finish: 'Finish job',
      pause: 'Pause',
      resume: 'Resume job',
      pauseTitle: 'Why are you pausing?',
      pauseCancel: 'Keep working',
      pauseReasons: { break: 'Break', materials: 'Waiting for materials', tenant: 'Tenant not available', other: 'Other' },
      pausedBanner: function (reason, time) { return 'Paused — ' + reason + ' · since ' + time; },
      paused: 'Paused',
      completedBanner: 'Job completed',
      completedIn: function (d) { return 'Took ' + d; },
      cancelledBanner: 'This job was cancelled',
      cancelledBody: 'You do not need to do anything.',
      startSheet: { title: 'Start this job now?', body: 'We will record the time you started.', confirm: 'Yes, start now', cancel: 'Not yet' },
      finishSheet: {
        title: 'Finished this job?',
        body: 'Add anything the office should know. You can leave it empty.',
        notesPlaceholder: 'For example: Left the key with reception',
        confirm: 'Yes, I finished',
        cancel: 'Not yet',
      },
      startNeedsPhoto: 'Take a Before photo or video first',
      finishNeedsPhoto: 'Add an After photo or video before you finish.',
      finishNeedsChecklist: function (left) {
        return left === 1 ? 'Tick the last checklist item before you finish.' : 'Tick ' + left + ' more checklist items before you finish.';
      },
      finishNeedsProblemNote: 'Write what the other problem is before you finish.',
      messages: 'Messages',
      photos: {
        beforeTitle: 'Before Photos',
        afterTitle: 'After Photos',
        take: 'Take photo',
        record: 'Record video',
        upload: 'Upload',
        none: 'No photos yet',
        afterLocked: 'You can add After photos and videos once you start the job.',
        deleteSheet: { title: 'Delete this photo?', body: 'You can take another one straight after.', confirm: 'Yes, delete it', cancel: 'Keep it' },
        deleteVideoSheet: { title: 'Delete this video?', body: 'You can film another one straight after.' },
        notPhoto: 'Only JPG, PNG, GIF or WEBP photos are allowed.',
        notVideo: 'Only MP4 or MOV videos are allowed.',
        videoTooBig: 'Each video must be 50 MB or smaller.',
        tooMany: function (n) { return 'Only the first ' + n + ' were added.'; },
      },
      materials: {
        requestAdd: 'Ask the office for materials',
        requestHint: 'Say it or show it — no typing needed',
        usedTitle: 'Materials used',
        usedNone: 'Nothing was recorded for this job.',
      },
      checklist: {
        title: 'Cleaning checklist',
        beforeStart: 'Read this now. You can tick items after you start the job.',
        progress: function (d, t) { return d + ' of ' + t + ' ticked'; },
        done: 'Done',
        na: 'N/A',
        problemsTitle: 'Found a problem?',
        problemsHint: 'Do not fix it. Tap what you found and maintenance will get a job.',
        notePlaceholder: 'Say where, for example: bathroom tap leaking',
        notePlaceholderRequired: 'Required: what is the problem, and where?',
        maintenanceRaised: 'A maintenance job was raised for this.',
        noProblems: 'No problems reported.',
      },
      gas: {
        title: 'Gas (R410)',
        hint: 'Weigh the cylinder before and after every unit. Take a photo of the scale each time.',
        weighBefore: 'Weigh gas before using',
        weighAfter: 'Weigh gas after using',
        none: 'No gas recorded.',
        unit: 'Unit',
        before: 'Before',
        after: 'After',
        used: 'Used',
        waitingAfter: 'Waiting for the after weight',
        noGasUsed: 'No gas used on this job.',
        screenBefore: 'Gas — before using',
        screenAfter: 'Gas — after using',
        stepUnit: '1. Which unit?',
        unitPlaceholder: 'Unit number, for example 1204',
        stepPhoto: function (n) { return n + '. Photo of the scale'; },
        stepKg: function (n) { return n + '. Weight on the scale'; },
        takePhoto: 'Take photo of the scale',
        retake: 'Take again',
        kgPlaceholder: 'For example 16.75',
        beforeWas: function (kg) { return 'Before: ' + kg; },
        usedNow: function (kg) { return 'Used: ' + kg; },
        moreThanBefore: 'After cannot be more than before. Check the scale.',
        badKg: 'Type the weight in kg, for example 16.75',
        save: 'Save weight',
        needUnit: 'Choose or type the unit first.',
        needPhoto: 'Take a photo of the scale first.',
        finishNeedsAfter: 'Weigh the gas after using it before you finish.',
        askTitle: 'Did you use gas (R410) on this job?',
        askBody: 'The office checks every cylinder against these weights.',
        askNo: 'No gas used',
        askYes: 'Yes, I used gas',
        forgotTitle: 'Weigh the gas first',
        forgotBody: 'Tap "Weigh gas before using" on this job, then "after using", for every unit. If you forgot to weigh before, tell the office in Messages.',
        forgotOk: 'OK',
        saved: 'Weight saved',
      },
    },
    notes: {
      title: 'Messages',
      emptyTitle: 'No messages yet',
      emptyBody: 'Write to the office if something is not right, or if you need help.',
      placeholder: 'Write a message',
      office: 'The office',
      askingTitle: 'Telling the office what you need',
      askingBody: 'Say it, show it, or write it. Then send.',
      materialTag: 'Asked for materials',
      slideToCancel: '‹ Slide left to cancel',
      cancelled: 'Voice message cancelled',
      tooShort: 'Hold the button down while you speak.',
      maxLength: 'That is as long as a voice message can be.',
      holdAgain: 'Microphone allowed. Hold the button again to record.',
      micNeeded: 'The microphone is blocked. Allow it for this app in your phone settings, then try again.',
      noMic: 'This phone cannot record voice messages here.',
      voiceFailed: 'This voice message would not play.',
      loading: 'Loading…',
      prepareFailed: 'That recording could not be prepared. Please try again.',
    },
    queue: {
      sending: 'Sending…',
      notSent: 'Not sent',
      failedTitle: 'This did not reach the office',
      tryAgain: 'Try again',
      discard: 'Forget it',
      close: 'Close',
    },
    errors: {
      genericBody: 'Please try again in a moment.',
      retry: 'Try again',
      couldNotSend: 'One change could not be sent. Please do it again.',
      notYours: 'That job is not on your list',
      storage: 'This phone is out of space for the app. Delete some photos or videos and try again.',
    },
    datetime: {
      today: 'Today',
      tomorrow: 'Tomorrow',
      yesterday: 'Yesterday',
      at: function (day, time) { return day + ' at ' + time; },
      weekdays: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
      months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    },
  };

  var TABS = ['today', 'overdue', 'upcoming', 'done'];
  var PAUSE_REASONS = ['break', 'materials', 'tenant', 'other'];
  var MAX_FILES = 10;
  var MAX_VIDEO_BYTES = 52428800;
  var UPLOAD_TIMEOUT = 10 * 60 * 1000;

  function signedIn() { return S.hasApp('cleaning'); }
  function userId() { var s = S.session(); return s ? s.user.id : 0; }

  function api(path, opts) {
    opts = opts || {};
    return S.request(API_BASE + '/' + path.replace(/^\/+/, ''), {
      method: opts.method,
      json: opts.body,
      form: opts.form,
      token: S.token('cleaning'),
      requestId: opts.requestId,
      background: opts.background,
      timeout: opts.timeout,
      raw: opts.raw,
    });
  }

  // -------------------------------------------------------------------------
  // Dates — OPERATION-MOBILE-APP/src/lib/datetime.ts
  // -------------------------------------------------------------------------

  function parseDate(iso) {
    var p = String(iso || '').split('-').map(Number);
    return new Date(p[0], (p[1] || 1) - 1, p[2] || 1);
  }
  function startOfToday() { var n = new Date(); return new Date(n.getFullYear(), n.getMonth(), n.getDate()); }
  function dayLabel(iso) {
    var date = parseDate(iso);
    var delta = Math.round((date - startOfToday()) / 86400000);
    if (delta === 0) return T.datetime.today;
    if (delta === 1) return T.datetime.tomorrow;
    if (delta === -1) return T.datetime.yesterday;
    return T.datetime.weekdays[date.getDay()] + ', ' + date.getDate() + ' ' + T.datetime.months[date.getMonth()];
  }
  function todayLongLabel() {
    var d = startOfToday();
    return T.datetime.weekdays[d.getDay()] + ', ' + d.getDate() + ' ' + T.datetime.months[d.getMonth()];
  }
  function timeLabel(time) {
    if (!time) return T.jobs.anyTime;
    var p = String(time).split(':').map(Number);
    if (!isFinite(p[0])) return T.jobs.anyTime;
    var hour = p[0] % 12 === 0 ? 12 : p[0] % 12;
    return hour + ':' + String(p[1] || 0).padStart(2, '0') + ' ' + (p[0] < 12 ? 'AM' : 'PM');
  }
  function whenLabel(iso, time) {
    var day = dayLabel(iso);
    return time ? T.datetime.at(day, timeLabel(time)) : day + ' · ' + T.jobs.anyTime;
  }
  function clockFromDateTime(dt) {
    if (!dt) return '';
    var time = dt.indexOf(' ') !== -1 ? dt.split(' ')[1] : dt;
    return timeLabel(time.slice(0, 5));
  }
  function noteStamp(dt) {
    if (!dt) return '';
    var parts = dt.split(' ');
    return T.datetime.at(dayLabel(parts[0]), timeLabel((parts[1] || '00:00').slice(0, 5)));
  }
  function clockLength(seconds) {
    var s = Math.max(0, Math.round(seconds || 0));
    return Math.floor(s / 60) + ':' + pad(s % 60);
  }
  function hoursLabel(hours) {
    if (hours === null || hours === undefined) return '';
    var minutes = Math.round(hours * 60);
    var h = Math.floor(minutes / 60);
    var m = minutes % 60;
    if (h === 0) return m + 'm';
    return m === 0 ? h + 'h' : h + 'h ' + m + 'm';
  }

  /** The building as the heading, its areas as lines — see OPERATION-MOBILE-APP/src/lib/jobHeading.ts. */
  function jobHeading(job) {
    var places = job.places || [];
    if (!places.length) return { heading: job.title, lines: [] };
    var SEP = ' — ';
    var split = places.map(function (p) {
      var at = p.label.indexOf(SEP);
      return at === -1 ? { building: '', area: p.label, full: p.label } : { building: p.label.slice(0, at), area: p.label.slice(at + SEP.length), full: p.label };
    });
    var buildings = [];
    split.forEach(function (x) { if (x.building && buildings.indexOf(x.building) === -1) buildings.push(x.building); });
    var one = buildings.length === 1;
    var type = T.jobType[job.job_type];
    var prefix = type + SEP;
    var appWritten = false;
    if (type && job.title.indexOf(prefix) === 0) {
      var rest = job.title.slice(prefix.length).replace(/ \+\d+$/, '');
      appWritten = places.some(function (p) { return p.label === rest; });
    }
    if (!appWritten) return { heading: job.title, lines: split.map(function (x) { return x.full; }) };
    return {
      heading: buildings.length ? buildings.join(' · ') : job.title,
      lines: split.map(function (x) { return one ? x.area : x.full; }),
    };
  }

  // -------------------------------------------------------------------------
  // Files waiting to send — IndexedDB, because a video will not fit in
  // localStorage. Falls back to memory when the browser refuses it.
  // -------------------------------------------------------------------------

  var files = (function () {
    var memory = {};
    var dbp = null;
    function db() {
      if (dbp) return dbp;
      dbp = new Promise(function (resolve) {
        try {
          var req = indexedDB.open('staff-outbox', 1);
          req.onupgradeneeded = function () { req.result.createObjectStore('files'); };
          req.onsuccess = function () { resolve(req.result); };
          req.onerror = function () { resolve(null); };
        } catch (e) { resolve(null); }
      });
      return dbp;
    }
    function tx(mode, fn) {
      return db().then(function (d) {
        if (!d) return fn(null);
        return new Promise(function (resolve, reject) {
          var t = d.transaction('files', mode);
          var result = fn(t.objectStore('files'));
          t.oncomplete = function () { resolve(result && result.result !== undefined ? result.result : undefined); };
          t.onerror = function () { reject(t.error); };
          t.onabort = function () { reject(t.error); };
        });
      });
    }
    return {
      put: function (key, blob) {
        return tx('readwrite', function (st) {
          if (!st) { memory[key] = blob; return null; }
          return st.put(blob, key);
        });
      },
      get: function (key) {
        if (memory[key]) return Promise.resolve(memory[key]);
        return tx('readonly', function (st) { return st ? st.get(key) : null; }).then(function (b) { return b || memory[key] || null; });
      },
      del: function (key) {
        delete memory[key];
        return tx('readwrite', function (st) { return st ? st.delete(key) : null; }).catch(function () {});
      },
    };
  })();

  if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(function () {});

  // -------------------------------------------------------------------------
  // What the server last said — kept per person, so a job opened with no
  // signal still shows. Optimistic writes patch it; delivery refetches it.
  // -------------------------------------------------------------------------

  var mem = {};
  var memUser = null;
  var queries = {};

  function ckey(name) { return 'staff.ops.c.' + userId() + '.' + name; }

  function resetIfUserChanged() {
    if (memUser !== userId()) { mem = {}; queries = {}; memUser = userId(); }
  }

  function cacheGet(name) {
    resetIfUserChanged();
    if (!(name in mem)) mem[name] = S.readJson(ckey(name), null);
    return mem[name];
  }

  function cacheSet(name, value) {
    resetIfUserChanged();
    mem[name] = value;
    if (name.indexOf('job:') === 0) rememberJob(name);
    if (!S.writeJson(ckey(name), value)) {
      // Full: throw away saved job details, keep lists and places.
      S.keysWithPrefix('staff.ops.c.' + userId() + '.job:').forEach(function (k) { try { localStorage.removeItem(k); } catch (e) { /* ignore */ } });
      S.writeJson(ckey('jobIndex'), []);
      S.writeJson(ckey(name), value);
    }
  }

  /** Keep the forty most recently seen jobs on the phone. */
  function rememberJob(name) {
    var list = S.readJson(ckey('jobIndex'), []).filter(function (n) { return n !== name; });
    list.unshift(name);
    while (list.length > 40) {
      var old = list.pop();
      try { localStorage.removeItem(ckey(old)); } catch (e) { /* ignore */ }
      delete mem[old];
    }
    S.writeJson(ckey('jobIndex'), list);
  }

  function query(name) { return queries[name] || (queries[name] = { loading: false, error: null, at: 0, errAt: 0 }); }

  function load(name, path, map) {
    var q = query(name);
    if (q.loading) return q.promise;
    q.loading = true;
    q.promise = api(path).then(function (data) {
      var value = map ? map(data) : data;
      cacheSet(name, value);
      q.error = null;
      q.at = Date.now();
      return value;
    }, function (e) {
      q.error = e;
      q.errAt = Date.now();
      return null;
    }).then(function (v) {
      q.loading = false;
      S.render();
      return v;
    });
    return q.promise;
  }

  /** Fetch unless fresh, loading, or failed a moment ago. */
  function ensure(name, path, staleMs, map) {
    var q = query(name);
    if (q.loading) return;
    if (q.at && Date.now() - q.at < staleMs) return;
    if (q.errAt && Date.now() - q.errAt < 15000) return;
    load(name, path, map);
  }

  function markStale(prefix) {
    Object.keys(queries).forEach(function (k) {
      if (k.indexOf(prefix) === 0) { queries[k].at = 0; queries[k].errAt = 0; }
    });
  }

  // --- Reads -----------------------------------------------------------------

  function loadJobs(tab) { return load('jobs:' + tab, 'jobs?tab=' + tab); }
  function ensureJobs(tab) { ensure('jobs:' + tab, 'jobs?tab=' + tab, 60000); }
  function ensurePlaces() { ensure('places', 'places', 3600000); }
  function ensureAttendance() {
    ensure('attendance', 'attendance', 60000, function (d) { return d.attendance; });
  }

  var LIST_FIELDS = ['status', 'status_label', 'duration_minutes', 'duration_label', 'can_start', 'start_blocked_reason', 'can_finish', 'finish_blocked_reason', 'is_paused', 'paused_at', 'pause_reason'];

  function loadJob(jobId) {
    if (!(jobId > 0)) return Promise.resolve(null);
    return load('job:' + jobId, 'jobs/' + jobId, function (d) { return d.job; }).then(function (job) {
      if (!job) return job;
      // Teach the lists what the detail just learned, so the two screens
      // cannot disagree about one job.
      TABS.concat(['requests']).forEach(function (tab) {
        var list = cacheGet('jobs:' + tab);
        if (!list || !list.jobs) return;
        var changed = false;
        list.jobs = list.jobs.map(function (j) {
          if (j.id !== job.id) return j;
          var copy = Object.assign({}, j);
          LIST_FIELDS.forEach(function (f) { if (copy[f] !== job[f]) { copy[f] = job[f]; changed = true; } });
          return copy;
        });
        if (changed) cacheSet('jobs:' + tab, list);
      });
      return job;
    });
  }

  function ensureJob(jobId) {
    if (!(jobId > 0)) return;
    var q = query('job:' + jobId);
    if (q.loading || (q.at && Date.now() - q.at < 30000) || (q.errAt && Date.now() - q.errAt < 15000)) return;
    loadJob(jobId);
  }

  /** Patch one job everywhere it is cached, so the tap shows on every screen. */
  function patchJob(routeJobId, fn) {
    var jobId = resolveJobId(routeJobId);
    var job = cacheGet('job:' + jobId);
    if (job) cacheSet('job:' + jobId, fn(job));
    TABS.forEach(function (tab) {
      var list = cacheGet('jobs:' + tab);
      if (!list || !list.jobs) return;
      var hit = false;
      list.jobs = list.jobs.map(function (j) {
        if (j.id !== jobId) return j;
        hit = true;
        var patched = fn(Object.assign({ photos: [], comments: [], materials: [] }, j));
        var copy = Object.assign({}, j);
        Object.keys(patched).forEach(function (k) { if (k !== 'photos' && k !== 'comments' && k !== 'materials') copy[k] = patched[k]; });
        return copy;
      });
      if (hit) cacheSet('jobs:' + tab, list);
    });
  }

  // -------------------------------------------------------------------------
  // The send queue — OPERATION-MOBILE-APP/src/api/queue.ts
  // -------------------------------------------------------------------------

  var QKEYS = { queue: 'staff.ops.queue.v1', failed: 'staff.ops.failed.v1', jobMap: 'staff.ops.jobMap.v1' };
  var RETRY_MS = [2000, 5000, 15000, 30000, 60000];
  var MAX_ATTEMPTS = 8;

  var queue = S.readJson(QKEYS.queue, []);
  var failed = S.readJson(QKEYS.failed, []);
  var jobIdMap = S.readJson(QKEYS.jobMap, {});
  var flushing = false;
  var authPaused = false;
  var offlineStreak = 0;
  var retryTimer = null;

  function persist() {
    S.writeJson(QKEYS.queue, queue);
    S.writeJson(QKEYS.failed, failed);
  }

  function resolveJobId(jobId) {
    return jobId < 0 ? (jobIdMap[String(jobId)] || jobId) : jobId;
  }

  function newTempJobId() { return -(Date.now() * 100 + Math.floor(Math.random() * 100)); }

  /** Rows invented on the phone get negative ids, unique across restarts. */
  var tempSeq = 0;
  function nextTempId() { tempSeq = (tempSeq + 1) % 1000; return -((Date.now() % 1e9) * 1000 + tempSeq); }

  function fileOf(op) {
    if (op.kind === 'photo' || op.kind === 'gas') return op.fileKey;
    if (op.kind === 'comment' && op.media) return op.media.fileKey;
    return null;
  }

  function enqueue(op) {
    op.jobId = op.kind === 'create' ? op.jobId : resolveJobId(op.jobId);
    op.id = S.uid();
    op.attempts = 0;
    op.at = Date.now();
    queue.push(op);
    persist();
    S.render();
    flush();
    return op;
  }

  function shelve(op, reason) {
    failed.push({ op: op, reason: reason, at: Date.now() });
    persist();
  }

  function removeOp(id) {
    queue = queue.filter(function (o) { return o.id !== id; });
    persist();
  }

  function retryOp(opId) {
    var item = failed.find(function (f) { return f.op.id === opId; });
    if (!item) return;
    failed = failed.filter(function (f) { return f.op.id !== opId; });
    item.op.attempts = 0;
    queue.push(item.op);
    persist();
    S.render();
    flush();
  }

  function discardOp(opId) {
    var item = failed.find(function (f) { return f.op.id === opId; });
    if (!item) return;
    var key = fileOf(item.op);
    if (key) files.del(key);
    failed = failed.filter(function (f) { return f.op.id !== opId; });
    persist();
    markStale('job');
    refreshScreen();
  }

  /** The server made a job this phone raised offline: point everything waiting at the real id. */
  function mapJobId(tempId, job) {
    var realId = job.id;
    jobIdMap[String(tempId)] = realId;
    S.writeJson(QKEYS.jobMap, jobIdMap);
    queue.forEach(function (o) { if (o.jobId === tempId) o.jobId = realId; });
    failed.forEach(function (f) { if (f.op.jobId === tempId) f.op.jobId = realId; });
    persist();
    var draft = S.readJson(draftKey(tempId), null);
    if (draft && !S.readJson(draftKey(realId), null)) S.writeJson(draftKey(realId), draft);
    cacheSet('job:' + realId, job);
  }

  function send(op) {
    if (op.kind === 'create') {
      return api('jobs', {
        method: 'POST',
        body: { title: op.title, job_type: op.jobType, location: op.location, description: op.description, places: op.places, client_at: op.at },
        requestId: op.id,
        background: true,
      }).then(function (data) {
        if (!data.job) throw new S.ApiError('conflict', T.newJob.createUnmatched, 409);
        mapJobId(op.jobId, data.job);
      });
    }
    if (op.kind === 'attendance') {
      return api('attendance/' + op.action, { method: 'POST', body: { client_at: op.at }, requestId: op.id, background: true });
    }

    var jobId = resolveJobId(op.jobId);
    if (jobId < 0) return Promise.reject(new S.ApiError('conflict', T.newJob.notCreatedYet, 409));
    var clientAt = op.at || Date.now();
    var simple = { start: 'start', pause: 'pause', resume: 'resume', finish: 'finish' };

    if (simple[op.kind]) {
      var body = { client_at: clientAt };
      if (op.kind === 'pause') body.reason = op.reason;
      if (op.kind === 'finish') {
        body.completion_notes = op.completionNotes || '';
        if (op.checklist) body.checklist = op.checklist;
        if (op.gasUsed !== undefined) body.gas_used = op.gasUsed ? 1 : 0;
      }
      return api('jobs/' + jobId + '/' + op.kind, { method: 'POST', body: body, requestId: op.id, background: true });
    }

    if (op.kind === 'comment' && !op.media) {
      var json = { comment: op.comment, is_material_request: op.isMaterialRequest ? 1 : 0 };
      if (op.groupId) json.client_group_id = op.groupId;
      return api('jobs/' + jobId + '/comments', { method: 'POST', body: json, requestId: op.id, background: true });
    }

    if (op.kind === 'comment' || op.kind === 'photo') {
      var key = fileOf(op);
      return files.get(key).then(function (blob) {
        if (!blob) throw new S.ApiError('bad_request', T.errors.couldNotSend, 400);
        var form = new FormData();
        if (op.kind === 'photo') {
          form.append('photo_type', op.photoType);
          form.append('photo', blob, op.fileName);
        } else {
          form.append('comment', op.comment);
          form.append('is_material_request', op.isMaterialRequest ? '1' : '0');
          if (op.groupId) form.append('client_group_id', op.groupId);
          form.append('media_kind', op.media.kind);
          if (op.media.durationSeconds) form.append('duration_seconds', String(op.media.durationSeconds));
          form.append('media', blob, op.media.fileName);
        }
        var path = 'jobs/' + jobId + (op.kind === 'photo' ? '/photos' : '/comments');
        return api(path, { method: 'POST', form: form, requestId: op.id, background: true, timeout: UPLOAD_TIMEOUT });
      }).then(function (data) {
        files.del(key);
        return data;
      });
    }

    if (op.kind === 'gas') {
      return files.get(op.fileKey).then(function (blob) {
        if (!blob) throw new S.ApiError('bad_request', T.errors.couldNotSend, 400);
        var form = new FormData();
        form.append('stage', op.stage);
        form.append('client_ref', op.ref);
        form.append('kg', op.kg);
        form.append('client_at', String(clientAt));
        if (op.stage === 'before') {
          if (op.place) { form.append('place_kind', op.place.kind); form.append('place_id', String(op.place.id)); }
          else form.append('unit_label', op.unitLabel);
        }
        form.append('photo', blob, op.fileName);
        return api('jobs/' + jobId + '/gas', { method: 'POST', form: form, requestId: op.id, background: true, timeout: UPLOAD_TIMEOUT });
      }).then(function (data) {
        files.del(op.fileKey);
        return data;
      });
    }

    if (op.kind === 'deletePhoto') {
      return api('jobs/' + jobId + '/photos/' + op.photoId, { method: 'DELETE', requestId: op.id, background: true });
    }
    return Promise.resolve();
  }

  /**
   * Deliver what we can, oldest first, stopping at the first thing that will
   * not go. Order matters: Finish must never overtake Start.
   */
  async function flush() {
    if (!signedIn() || authPaused || flushing || !queue.length) return;
    flushing = true;
    try {
      while (queue.length) {
        var op = queue[0];
        try {
          await send(op);
          offlineStreak = 0;
          removeOp(op.id);
          delivered(resolveJobId(op.jobId));
        } catch (error) {
          var e = error instanceof S.ApiError ? error : null;
          // A refused token is not this op's fault: stop until someone signs in.
          if (e && e.kind === 'unauthorized') { authPaused = true; S.render(); return; }
          // No signal: wait as long as it takes, without spending attempts.
          if (e && e.kind === 'offline') {
            offlineStreak += 1;
            scheduleRetry(offlineStreak);
            return;
          }
          if (e && !e.retryable) {
            // The server decided — keep it where the person can see it, in its words.
            removeOp(op.id);
            shelve(op, e.message);
            S.toast(e.message);
            delivered(resolveJobId(op.jobId));
            continue;
          }
          var attempts = op.attempts + 1;
          if (attempts >= MAX_ATTEMPTS) {
            var why = e ? e.message : T.errors.couldNotSend;
            removeOp(op.id);
            shelve(op, why);
            S.toast(why);
            delivered(resolveJobId(op.jobId));
            continue;
          }
          op.attempts = attempts;
          persist();
          S.render();
          scheduleRetry(attempts);
          return;
        }
      }
    } finally {
      flushing = false;
    }
  }

  function scheduleRetry(attempts) {
    if (retryTimer) clearTimeout(retryTimer);
    retryTimer = setTimeout(function () { retryTimer = null; flush(); }, RETRY_MS[Math.min(attempts, RETRY_MS.length - 1)]);
  }

  /** Something landed (or was refused): take the server's version. */
  function delivered(jobId) {
    markStale('jobs:');
    markStale('job:');
    if (jobId === 0) markStale('attendance');
    refreshScreen();
  }

  /** Files whose blob is gone can never be sent — say so now, not eight tries later. */
  function dropUnsendable() {
    var withFiles = queue.filter(function (o) { return fileOf(o); });
    if (!withFiles.length) return Promise.resolve();
    return Promise.all(withFiles.map(function (o) {
      return files.get(fileOf(o)).then(function (b) { return b ? null : o.id; }, function () { return o.id; });
    })).then(function (ids) {
      var missing = ids.filter(Boolean);
      if (!missing.length) return;
      queue.filter(function (o) { return missing.indexOf(o.id) !== -1; }).forEach(function (o) { shelve(o, T.errors.couldNotSend); });
      queue = queue.filter(function (o) { return missing.indexOf(o.id) === -1; });
      persist();
      S.toast(T.errors.couldNotSend);
    });
  }

  function opsForJob(routeJobId) {
    var jobId = resolveJobId(routeJobId);
    return queue.filter(function (o) { return o.jobId === jobId; })
      .concat(failed.filter(function (f) { return f.op.jobId === jobId; }).map(function (f) { return f.op; }))
      .sort(function (a, b) { return (a.at || 0) - (b.at || 0); });
  }

  /** What the queue is doing to each thing on one job's screen. */
  function jobQueueState(routeJobId) {
    var jobId = resolveJobId(routeJobId);
    var r = { photos: {}, comments: {}, gas: {}, action: null, sendingKinds: [] };
    var entries = queue.filter(function (o) { return o.jobId === jobId; }).map(function (o) { return [o, null]; })
      .concat(failed.filter(function (f) { return f.op.jobId === jobId; }).map(function (f) { return [f.op, f]; }));
    entries.forEach(function (pair) {
      var op = pair[0];
      var f = pair[1];
      var item = { state: f ? 'failed' : 'sending', reason: f ? f.reason : null, opId: op.id };
      if (op.kind === 'photo') r.photos[op.tempId] = item;
      else if (op.kind === 'gas') r.gas[op.ref + ':' + op.stage] = item;
      else if (op.kind === 'comment' && op.commentTempId !== undefined) {
        var known = r.comments[op.commentTempId];
        if (!known || (known.state === 'sending' && item.state === 'failed')) r.comments[op.commentTempId] = item;
      } else if (['start', 'finish', 'pause', 'resume'].indexOf(op.kind) !== -1) {
        if (item.state === 'sending') r.sendingKinds.push(op.kind);
        if (!r.action || (r.action.state === 'sending' && item.state === 'failed')) r.action = Object.assign({ kind: op.kind }, item);
      } else if (op.kind === 'create' && item.state === 'failed') {
        r.action = Object.assign({ kind: 'create' }, item);
      }
    });
    return r;
  }

  function queueStateByJob() {
    var byJob = {};
    queue.forEach(function (o) { if (!byJob[o.jobId]) byJob[o.jobId] = 'sending'; });
    failed.forEach(function (f) { byJob[f.op.jobId] = 'failed'; });
    return byJob;
  }

  /** The job the server described, plus everything about it still on the phone. */
  function mergeOutbox(job, routeJobId) {
    var ops = opsForJob(routeJobId);
    if (!ops.length) return job;
    var photos = (job.photos || []).slice();
    var comments = (job.comments || []).slice();
    var pending = {};
    var order = [];
    var gas = job.gas ? { used: job.gas.used, readings: (job.gas.readings || []).map(function (x) { return Object.assign({}, x); }) } : null;
    ops.forEach(function (op) {
      if (op.kind === 'gas') {
        if (!gas) return;
        var rd = gas.readings.find(function (x) { return x.client_ref === op.ref; });
        if (op.stage === 'before' && !rd) {
          gas.readings.push({
            id: null, client_ref: op.ref, place_kind: op.place ? op.place.kind : null, place_id: op.place ? op.place.id : null,
            unit_label: op.unitLabel, before_kg: Number(op.kg), before_at: nowStamp(op.at), before_local: op.fileKey,
            after_kg: null, after_at: null, used_kg: null,
          });
        } else if (op.stage === 'after' && rd && rd.after_kg === null) {
          rd.after_kg = Number(op.kg);
          rd.after_at = nowStamp(op.at);
          rd.after_local = op.fileKey;
          rd.used_kg = Math.round((rd.before_kg - rd.after_kg) * 1000) / 1000;
        }
        return;
      }
      if (op.kind === 'photo') {
        if (photos.some(function (p) { return p.id === op.tempId; })) return;
        photos.push({
          id: op.tempId, photo_type: op.photoType, media_kind: op.mediaKind || 'photo', caption: null, url_path: '',
          created_at: nowStamp(op.at), localKey: op.fileKey, is_mine: true,
          can_delete: (job.status === 'open' && op.photoType === 'before') || (job.status === 'in_progress' && op.photoType === 'after'),
        });
        return;
      }
      if (op.kind === 'comment' && op.commentTempId !== undefined) {
        if (comments.some(function (c) { return c.id === op.commentTempId; })) return;
        if (!pending[op.commentTempId]) { pending[op.commentTempId] = { op: op, media: [] }; order.push(op.commentTempId); }
        if (op.media) {
          pending[op.commentTempId].media.push({
            id: op.media.tempId, kind: op.media.kind, url_path: '', file_ext: '',
            duration_seconds: op.media.durationSeconds || null, created_at: nowStamp(op.at), localKey: op.media.fileKey,
          });
        }
      }
    });
    order.forEach(function (id) {
      var p = pending[id];
      comments.push({
        id: id, comment: p.op.comment, has_text: p.op.comment !== '', author_name: null, is_mine: true,
        created_at: nowStamp(p.op.at), is_material_request: !!p.op.isMaterialRequest, media: p.media,
      });
    });
    return Object.assign({}, job, { photos: photos, comments: comments, gas: gas });
  }

  // -------------------------------------------------------------------------
  // Writes — OPERATION-MOBILE-APP/src/api/hooks.ts. Each patches the cache so
  // the screen changes on the tap, then hands the work to the queue.
  // -------------------------------------------------------------------------

  function startJob(jobId) {
    patchJob(jobId, function (j) { return Object.assign({}, j, { status: 'in_progress', started_at: j.started_at || nowStamp() }); });
    enqueue({ kind: 'start', jobId: jobId });
  }

  function pauseJob(jobId, reason) {
    patchJob(jobId, function (j) { return Object.assign({}, j, { is_paused: true, paused_at: nowStamp(), pause_reason: reason }); });
    enqueue({ kind: 'pause', jobId: jobId, reason: reason });
  }

  function resumeJob(jobId) {
    patchJob(jobId, function (j) { return Object.assign({}, j, { is_paused: false, paused_at: null, pause_reason: null }); });
    enqueue({ kind: 'resume', jobId: jobId });
  }

  function finishJob(jobId, notes, checklist, gasUsed) {
    patchJob(jobId, function (j) {
      var started = j.started_at ? new Date(j.started_at.replace(' ', 'T')) : new Date();
      var minutes = Math.max(0, Math.round((Date.now() - started.getTime()) / 60000));
      return Object.assign({}, j, {
        is_paused: false, paused_at: null, pause_reason: null,
        status: 'done', finished_at: nowStamp(), duration_minutes: minutes,
        duration_label: minutes < 60 ? minutes + 'm' : j.duration_label,
        completion_notes: notes || j.completion_notes,
      });
    });
    var op = { kind: 'finish', jobId: jobId, completionNotes: notes };
    if (checklist) op.checklist = checklist;
    if (gasUsed !== undefined) op.gasUsed = gasUsed;
    enqueue(op);
  }

  function checkInOut(action) {
    var stamp = nowStamp();
    var hm = stamp.slice(11, 16);
    var old = cacheGet('attendance');
    if (action === 'check-in') {
      if (!old || old.state === 'not_checked_in') {
        cacheSet('attendance', { state: 'checked_in', work_date: stamp.slice(0, 10), check_in: hm, check_out: null, hours: null, message: null });
      }
    } else if (old && old.state === 'checked_in' && old.check_in && old.work_date) {
      var inAt = new Date(old.work_date + 'T' + old.check_in + ':00').getTime();
      var hours = isFinite(inAt) ? Math.max(0, Math.round(((Date.now() - inAt) / 3600000) * 100) / 100) : null;
      cacheSet('attendance', Object.assign({}, old, { state: 'checked_out', check_out: hm, hours: hours }));
    }
    enqueue({ kind: 'attendance', jobId: 0, action: action });
  }

  /** Keep a file somewhere that survives a closed app until it is sent. */
  function stash(blob) {
    var key = 'f-' + S.uid();
    return files.put(key, blob).then(function () { return key; }, function () {
      throw new Error(T.errors.storage);
    });
  }

  function addPhoto(jobId, photoType, prepared) {
    return stash(prepared.blob).then(function (key) {
      var tempId = nextTempId();
      enqueue({
        kind: 'photo', jobId: jobId, photoType: photoType, mediaKind: prepared.kind,
        fileKey: key, fileName: prepared.name, tempId: tempId,
      });
    });
  }

  /** One weight: the photo of the scale is kept on the phone until it is sent. */
  function addGas(jobId, g) {
    return stash(g.photo.blob).then(function (key) {
      enqueue({
        kind: 'gas', jobId: jobId, stage: g.stage, ref: g.ref, kg: g.kg.replace(',', '.'),
        place: g.stage === 'before' && g.place ? { kind: g.place.kind, id: g.place.id } : null,
        unitLabel: g.stage === 'before' ? (g.place ? g.place.label : g.unitText.trim()) : '',
        fileKey: key, fileName: g.photo.name,
      });
    });
  }

  function deletePhoto(jobId, photo) {
    patchJob(jobId, function (j) {
      return Object.assign({}, j, { photos: (j.photos || []).filter(function (p) { return p.id !== photo.id; }) });
    });
    if (photo.id < 0) {
      var id = resolveJobId(jobId);
      var shelved = failed.find(function (f) { return f.op.kind === 'photo' && f.op.jobId === id && f.op.tempId === photo.id; });
      if (shelved) { discardOp(shelved.op.id); return; }
      var target = queue.find(function (o) { return o.kind === 'photo' && o.jobId === id && o.tempId === photo.id; });
      if (target) { files.del(target.fileKey); removeOp(target.id); }
      S.render();
      return;
    }
    enqueue({ kind: 'deletePhoto', jobId: jobId, photoId: photo.id });
  }

  /** Text, files, or both. One request per file, all naming one message. */
  function addComment(jobId, comment, media, isMaterialRequest) {
    media = media || [];
    return Promise.all(media.map(function (m) {
      return stash(m.blob).then(function (key) { return Object.assign({}, m, { fileKey: key, tempId: nextTempId() }); });
    })).then(function (stashed) {
      var commentTempId = nextTempId();
      patchJob(jobId, function (j) {
        return Object.assign({}, j, { needs_materials: isMaterialRequest ? true : j.needs_materials });
      });
      var groupId = stashed.length > 1 ? 'g' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10) : undefined;
      if (!stashed.length) {
        enqueue({ kind: 'comment', jobId: jobId, comment: comment, isMaterialRequest: isMaterialRequest, commentTempId: commentTempId });
        return;
      }
      stashed.forEach(function (m) {
        enqueue({
          kind: 'comment', jobId: jobId, comment: comment, isMaterialRequest: isMaterialRequest, groupId: groupId,
          commentTempId: commentTempId,
          media: { kind: m.kind, fileKey: m.fileKey, fileName: m.name, durationSeconds: m.durationSeconds, tempId: m.tempId },
        });
      });
    });
  }

  function createJob(input) {
    var tempId = newTempJobId();
    var stamp = nowStamp();
    var placesData = cacheGet('places');
    var definition = placesData && placesData.cleaning_checklist;
    var unitClean = input.jobType === 'cleaning' && input.places.some(function (p) { return p.kind === 'unit'; });
    var job = {
      id: tempId, title: input.title, location: null, job_type: input.jobType, job_type_label: T.jobType[input.jobType],
      status: 'open', status_label: T.status.open, priority: 'normal', is_urgent: false, is_late: false,
      scheduled_date: stamp.slice(0, 10), scheduled_time: null, duration_minutes: null, duration_label: null,
      needs_materials: false, before_photo_count: 0, can_start: false, start_blocked_reason: null,
      can_finish: false, finish_blocked_reason: null, places: input.places, source_type: 'staff', request_note: null,
      is_paused: false, paused_at: null, pause_reason: null, description: null, completion_notes: null,
      started_at: null, finished_at: null, photos: [], materials: [], comments: [],
      checklist: unitClean && definition ? Object.assign({}, definition, { saved: null }) : null, request_photos: [],
      gas: input.jobType === 'maintenance' ? { readings: [], used: null } : null,
    };
    cacheSet('job:' + tempId, job);
    var today = cacheGet('jobs:today');
    if (today && today.jobs) {
      today.jobs = [job].concat(today.jobs);
      today.counts = Object.assign({}, today.counts, { today: (today.counts.today || 0) + 1 });
      cacheSet('jobs:today', today);
    }
    enqueue({
      kind: 'create', jobId: tempId, title: input.title, jobType: input.jobType, location: '', description: '',
      places: input.places.map(function (p) { return { kind: p.kind, id: p.id }; }),
    });
    return job;
  }

  // -------------------------------------------------------------------------
  // Checklist ticks — on the phone until Finish carries them.
  // -------------------------------------------------------------------------

  function draftKey(jobId) { return 'staff.ops.checklist.' + userId() + '.' + jobId; }
  function getDraft(routeJobId) {
    var d = S.readJson(draftKey(resolveJobId(routeJobId)), null) || S.readJson(draftKey(routeJobId), null);
    return { items: (d && d.items) || {}, problems: (d && d.problems) || [], problem_note: (d && d.problem_note) || '' };
  }
  function setDraft(routeJobId, d) { S.writeJson(draftKey(resolveJobId(routeJobId)), d); }
  function clearDraft(routeJobId) { S.writeJson(draftKey(resolveJobId(routeJobId)), null); }

  function checklistTotal(c) { return c.sections.reduce(function (n, s) { return n + s.items.length; }, 0); }
  function checklistAnswered(c, answers) {
    return c.sections.reduce(function (n, s) { return n + s.items.filter(function (i) { return answers[i.key]; }).length; }, 0);
  }

  // -------------------------------------------------------------------------
  // Picking and preparing files
  // -------------------------------------------------------------------------

  var pickFiles = S.pickFiles;
  var extOf = S.extOf;

  /** A photo shrunk for 3G — the app sends at quality 0.6, this does about the same. */
  function preparePhoto(file) {
    return S.preparePhoto(file, T.job.photos.notPhoto).then(function (p) { return { kind: 'photo', blob: p.blob, name: p.name }; });
  }

  function prepareVideo(file) {
    var ext = extOf(file.name);
    var type = file.type || '';
    var out = ext === 'mov' || type === 'video/quicktime' ? 'mov' : (ext === 'mp4' || ext === 'm4v' || type === 'video/mp4' ? 'mp4' : '');
    if (!out) return Promise.reject(new Error(T.job.photos.notVideo));
    if (file.size > MAX_VIDEO_BYTES) return Promise.reject(new Error(T.job.photos.videoTooBig));
    return Promise.resolve({ kind: 'video', blob: file, name: 'video.' + out });
  }

  function videoSeconds(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var v = document.createElement('video');
      v.preload = 'metadata';
      var done = function (s) { URL.revokeObjectURL(url); resolve(s); };
      v.onloadedmetadata = function () { done(isFinite(v.duration) ? Math.max(1, Math.round(v.duration)) : undefined); };
      v.onerror = function () { done(undefined); };
      setTimeout(function () { done(undefined); }, 4000);
      v.src = url;
    });
  }

  function prepareAny(file) {
    return (file.type || '').indexOf('video/') === 0 || ['mp4', 'mov', 'm4v'].indexOf(extOf(file.name)) !== -1
      ? prepareVideo(file).then(function (p) { return videoSeconds(file).then(function (s) { p.durationSeconds = s; return p; }); })
      : preparePhoto(file);
  }

  // -------------------------------------------------------------------------
  // Showing photos, clips and voice notes
  // -------------------------------------------------------------------------

  function remoteKey(path) { return 'ops:' + path; }
  function localKeyOf(key) { return 'file:' + key; }

  function mediaUrl(item) {
    if (item.localKey) return S.media(localKeyOf(item.localKey), function () {
      return files.get(item.localKey).then(function (b) { if (!b) throw new Error('gone'); return b; });
    });
    if (!item.url_path) return null;
    return S.media(remoteKey(item.url_path), function () { return api(item.url_path, { raw: true, background: true }); });
  }

  function mediaKeyOf(item) { return item.localKey ? localKeyOf(item.localKey) : remoteKey(item.url_path); }

  function openPhoto(item) {
    var url = mediaUrl(item);
    if (!url) { S.toast(T.notes.loading); return; }
    S.openViewer('<img src="' + esc(url) + '" alt="">');
  }

  function openVideo(item) {
    var url = mediaUrl(item);
    S.openViewer(url ? '<video src="' + esc(url) + '" controls playsinline autoplay></video>' : '<div class="viewer-wait">' + esc(T.notes.loading) + '</div>');
    if (url) return;
    var key = mediaKeyOf(item);
    var wait = setInterval(function () {
      var viewer = document.querySelector('.viewer-wait');
      if (!viewer) { clearInterval(wait); return; }
      var u = mediaUrl(item);
      if (u) {
        clearInterval(wait);
        viewer.outerHTML = '<video src="' + esc(u) + '" controls playsinline></video>';
      } else if (S.mediaFailed(key)) {
        clearInterval(wait);
        viewer.textContent = T.errors.genericBody;
        S.mediaForget(key);
      }
    }, 300);
  }

  // One player for every voice note, outside the page, so redrawing the
  // thread can never cut one off mid-sentence.
  var player = { audio: null, key: null, playing: false };

  function toggleVoice(item) {
    var key = mediaKeyOf(item);
    if (player.key === key && player.audio) {
      if (player.playing) player.audio.pause();
      else player.audio.play().catch(function () {});
      return;
    }
    var url = mediaUrl(item);
    if (!url) {
      if (S.mediaFailed(key)) { S.mediaForget(key); mediaUrl(item); }
      S.toast(T.notes.loading);
      return;
    }
    if (player.audio) player.audio.pause();
    var a = new Audio(url);
    player = { audio: a, key: key, playing: false };
    a.addEventListener('play', function () { player.playing = true; S.render(); });
    a.addEventListener('pause', function () { player.playing = false; S.render(); });
    a.addEventListener('ended', function () { player.playing = false; a.currentTime = 0; S.render(); paintVoice(); });
    a.addEventListener('timeupdate', paintVoice);
    a.play().catch(function () { S.toast(T.notes.voiceFailed); });
  }

  function paintVoice() {
    if (!player.audio) return;
    var a = player.audio;
    var bar = document.querySelector('[data-voice-fill="' + player.key + '"]');
    var label = document.querySelector('[data-voice-time="' + player.key + '"]');
    var total = isFinite(a.duration) && a.duration > 0 ? a.duration : 0;
    if (bar) bar.style.width = (total ? Math.min(100, (a.currentTime / total) * 100) : 0) + '%';
    if (label) label.textContent = clockLength(a.currentTime || total);
  }

  // -------------------------------------------------------------------------
  // Voice recording — hold to talk, slide left to cancel, let go to send.
  // Safari records AAC (.m4a); everything else is turned into WAV, which the
  // server takes and every phone plays — same as the office's web composer.
  // -------------------------------------------------------------------------

  var MIN_MS = 1000;
  var MAX_MS = 120000;
  var CANCEL_AT = -80;

  var rec = { active: false, starting: false, abandoned: false, cancel: false, downAt: 0, startedAt: 0, startX: 0, stream: null, recorder: null, chunks: [], timer: null };

  function micDown(e) {
    if (rec.active || rec.starting) return;
    e.preventDefault();
    rec.abandoned = false;
    rec.cancel = false;
    rec.startX = e.clientX;
    rec.downAt = Date.now();
    document.addEventListener('pointermove', micMove);
    document.addEventListener('pointerup', micUp);
    document.addEventListener('pointercancel', micCancelled);
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
      S.toast(T.notes.noMic);
      return;
    }
    rec.starting = true;
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      rec.starting = false;
      if (rec.abandoned) {
        stream.getTracks().forEach(function (t) { t.stop(); });
        S.toast(Date.now() - rec.downAt > 800 ? T.notes.holdAgain : T.notes.tooShort);
        return;
      }
      var type = S.recorderMime();
      var r;
      try { r = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream); } catch (err) { r = new MediaRecorder(stream); }
      rec.chunks = [];
      r.ondataavailable = function (ev) { if (ev.data && ev.data.size) rec.chunks.push(ev.data); };
      r.onstop = recordingStopped;
      r.start();
      rec.recorder = r;
      rec.stream = stream;
      rec.active = true;
      rec.startedAt = Date.now();
      rec.timer = setInterval(tickRecording, 200);
      S.render();
    }, function () {
      rec.starting = false;
      endPointer();
      S.toast(T.notes.micNeeded);
    });
  }

  function micMove(e) {
    if (!rec.active) return;
    var past = e.clientX - rec.startX < CANCEL_AT;
    if (past !== rec.cancel) { rec.cancel = past; S.render(); }
  }

  function micUp() { endPointer(); stopRecording(rec.cancel); }
  function micCancelled() { endPointer(); stopRecording(true); }

  function endPointer() {
    document.removeEventListener('pointermove', micMove);
    document.removeEventListener('pointerup', micUp);
    document.removeEventListener('pointercancel', micCancelled);
  }

  function stopRecording(cancel) {
    if (!rec.active) { rec.abandoned = true; return; }
    rec.cancel = cancel;
    rec.active = false;
    clearInterval(rec.timer);
    rec.heldMs = Date.now() - rec.startedAt;
    try { rec.recorder.stop(); } catch (e) { recordingStopped(); }
    S.render();
  }

  function tickRecording() {
    var held = Date.now() - rec.startedAt;
    var el = document.querySelector('[data-rec-time]');
    if (el) el.textContent = clockLength(held / 1000);
    if (held >= MAX_MS) { S.toast(T.notes.maxLength); endPointer(); stopRecording(false); }
  }

  function recordingStopped() {
    if (rec.stream) rec.stream.getTracks().forEach(function (t) { t.stop(); });
    rec.stream = null;
    var type = (rec.recorder && rec.recorder.mimeType) || '';
    var blob = new Blob(rec.chunks, { type: type || 'audio/webm' });
    rec.chunks = [];
    if (rec.cancel) { S.toast(T.notes.cancelled); return; }
    if (rec.heldMs < MIN_MS || !blob.size) { S.toast(T.notes.tooShort); return; }
    var seconds = Math.max(1, Math.round(rec.heldMs / 1000));
    var jobId = S.state.route.id;
    var ready = S.voiceFile(blob, type).then(function (f) { return { kind: 'voice', blob: f.blob, name: f.name, durationSeconds: seconds }; });
    ready.then(function (item) { sendMessage(jobId, [item]); }, function () { S.toast(T.notes.prepareFailed); });
  }

  // -------------------------------------------------------------------------
  // Screen state
  // -------------------------------------------------------------------------

  var ui = {
    tab: 'today',
    finishNotes: '',
    draft: '',
    asking: false,
    newJob: null,
    gas: null,
    lastThreadCount: -1,
  };

  function refreshScreen() {
    if (!signedIn()) return;
    var r = S.state.route;
    if (r.app !== 'cleaning' && r.app !== 'home') { S.render(); return; }
    if (r.app === 'home') { ensureJobs('today'); ensureAttendance(); S.render(); return; }
    var view = r.view || 'list';
    if (view === 'list') { ensureJobs(ui.tab); if (ui.tab !== 'today') ensureJobs('today'); }
    if (view === 'requests') ensureJobs('requests');
    if (view === 'job' || view === 'notes' || view === 'gas') ensureJob(resolveJobId(r.id));
    if (view === 'new') ensurePlaces();
    S.render();
  }

  // --- Small pieces ------------------------------------------------------------

  function pill(text, color, weight) {
    return '<span class="pill" style="color:' + color + ';background:' + tint(color) + (weight ? ';font-weight:' + weight : '') + '">' + esc(text) + '</span>';
  }
  function tint(hex) {
    var v = hex.replace('#', '');
    return 'rgba(' + parseInt(v.slice(0, 2), 16) + ',' + parseInt(v.slice(2, 4), 16) + ',' + parseInt(v.slice(4, 6), 16) + ',.12)';
  }
  var STATUS_COLOR = { open: '#6B7280', in_progress: '#D97706', done: '#059669', cancelled: '#9CA3AF' };
  var TYPE_COLOR = { cleaning: '#0E7490', maintenance: '#7C3AED' };
  var TYPE_ICON = { cleaning: 'sparkles', maintenance: 'wrench' };

  function statusPill(job) { return pill(T.status[job.status] || job.status_label, STATUS_COLOR[job.status] || '#6B7280', 600); }

  function queueMark(state) {
    var bad = state === 'failed';
    return '<span class="qmark' + (bad ? ' bad' : '') + '">' + icon(bad ? 'alert' : 'upload') + esc(bad ? T.queue.notSent : T.queue.sending) + '</span>';
  }

  function queueNote(item) {
    if (item.state === 'sending') return '<div class="qnote">' + icon('upload') + esc(T.queue.sending) + '</div>';
    return '<div class="qfail"><div class="row">' + icon('alert') + '<strong>' + esc(T.queue.notSent) + '</strong></div>' +
      (item.reason ? '<div class="small secondary mt-1">' + esc(item.reason) + '</div>' : '') +
      '<div class="qactions"><button type="button" data-action="cleaning:retry" data-op="' + esc(item.opId) + '">' + esc(T.queue.tryAgain) + '</button>' +
      '<button type="button" class="muted" data-action="cleaning:discard" data-op="' + esc(item.opId) + '">' + esc(T.queue.discard) + '</button></div></div>';
  }

  function emptyState(iconName, title, body) {
    return '<div class="empty">' + icon(iconName, 'xl') + '<div class="h3 mt-3">' + esc(title) + '</div><div class="secondary mt-1">' + esc(body) + '</div></div>';
  }

  function errorCard(error, action) {
    return '<div class="card"><div class="body-lg">' + esc((error && error.message) || T.errors.genericBody) + '</div><div class="mt-3">' +
      button(T.errors.retry, action, { variant: 'secondary' }) + '</div></div>';
  }

  function jobCard(job, opts) {
    opts = opts || {};
    var color = TYPE_COLOR[job.job_type] || '#10233C';
    var h = jobHeading(job);
    var tags = '';
    if (job.source_type && job.source_type !== 'staff') tags += pill(job.source_type === 'cleaner_report' ? T.requests.fromCleaner : T.requests.fromTenant, '#2563EB', 700);
    if (job.is_urgent) tags += pill(T.jobs.urgent, '#DC2626', 700);
    var where = h.lines.length ? T.places.summary(h.lines) : job.location;
    var when = opts.showDay ? dayLabel(job.scheduled_date) + ' · ' + timeLabel(job.scheduled_time) : timeLabel(job.scheduled_time);
    return '<button type="button" class="card job-card" style="border-left-color:' + color + '" data-key="j' + job.id + '" data-action="' + opts.action + '" data-id="' + job.id + '">' +
      '<div class="row between"><span class="type" style="color:' + color + '">' + icon(TYPE_ICON[job.job_type] || 'sparkles', 'sm') + esc(T.jobType[job.job_type] || job.job_type_label) + '</span><span class="tags">' + tags + '</span></div>' +
      '<div class="h3 mt-2">' + esc(h.heading) + '</div>' +
      (where ? '<div class="meta">' + icon('pin', 'sm') + '<span>' + esc(where) + '</span></div>' : '') +
      (job.request_note ? '<div class="meta strong">' + icon('note', 'sm') + '<span class="clamp2">' + esc(job.request_note) + '</span></div>' : '') +
      '<div class="meta' + (job.is_late ? ' late' : '') + '">' + icon('clock', 'sm') + '<span>' + esc(when) + '</span></div>' +
      '<div class="pills mt-3">' + statusPill(job) +
      (job.is_paused ? pill(T.job.paused, '#D97706', 700) : '') +
      (job.is_late ? pill(T.jobs.late, '#DC2626', 700) : '') +
      (job.status === 'done' && job.duration_label ? '<span class="small muted">' + esc(T.job.completedIn(job.duration_label)) + '</span>' : '') +
      (opts.queued ? queueMark(opts.queued) : '') +
      '</div></button>';
  }

  function skeletonCards(n) {
    var s = '';
    for (var i = 0; i < n; i++) s += '<div class="skel" style="height:132px"></div>';
    return s;
  }

  // --- List ---------------------------------------------------------------------

  /** Check in / out. Shown on the main screen, not in the Cleaning list. */
  function attendanceBar() {
    if (!signedIn()) return '';
    var data = cacheGet('attendance');
    var q = query('attendance');
    var offlineUnknown = !data && q.error && q.error.kind === 'offline';
    if (q.error && !data && !offlineUnknown) {
      return '<div class="att note">' + icon('alert') + '<span class="grow">' + esc(q.error.message) + '</span></div>';
    }
    if (!data && !offlineUnknown) return '';
    if (data && data.state === 'hr_marked') return '<div class="att note">' + icon('user') + '<span class="grow">' + esc(data.message) + '</span></div>';
    if (data && data.state === 'checked_out') {
      return '<div class="att note"><span class="ok">' + icon('check') + '</span><span class="grow">' +
        esc(T.attendance.dayDone(data.check_in || '', data.check_out || '', hoursLabel(data.hours))) + '</span></div>';
    }
    if (data && data.state === 'checked_in') {
      return '<div class="att"><span class="ok">' + icon('clock') + '</span><strong class="grow">' + esc(T.attendance.checkedInAt(data.check_in || '')) + '</strong>' +
        '<button type="button" class="btn secondary auto" data-action="cleaning:check-out">' + esc(T.attendance.checkOut) + '</button></div>';
    }
    return '<div class="att">' + button(T.attendance.checkIn, 'cleaning:check-in', { icon: 'clock' }) + '</div>';
  }

  /**
   * The home screen has a Cleaning and a Maintenance button; both open these
   * screens with route.kind set, and each shows only its own type of job.
   * No kind (someone who only has this one app) shows both.
   */
  function ofKind(jobs, kind) {
    return kind ? jobs.filter(function (j) { return j.job_type === kind; }) : jobs;
  }

  function countsOf(list, kind) {
    if (!list) return null;
    if (kind) return (list.counts_by_type && list.counts_by_type[kind]) || null;
    return list.counts || null;
  }

  function renderList(route) {
    var kind = route.kind || null;
    var s = S.session();
    var firstName = s ? String(s.user.name).trim().split(/\s+/)[0] : '';
    var data = cacheGet('jobs:' + ui.tab);
    var today = cacheGet('jobs:today');
    var q = query('jobs:' + ui.tab);
    var counts = countsOf(data, kind) || countsOf(today, kind) || {};
    var queued = queueStateByJob();
    var body = S.installCard();

    if (counts.requests) {
      body += '<button type="button" class="requests-bar" data-action="cleaning:requests" data-kind="' + esc(kind || '') + '">' + icon('alert') +
        '<span class="grow">' + esc(T.jobs.requestsWaiting(counts.requests)) + '</span>' + icon('chevron') + '</button>';
    }

    body += '<div class="tabs" role="tablist">' + TABS.map(function (tab) {
      var on = ui.tab === tab;
      var n = counts[tab];
      return '<button type="button" role="tab" aria-selected="' + on + '" class="tab' + (on ? ' on' : '') + '" data-action="cleaning:tab" data-tab="' + tab + '">' +
        '<span>' + esc(T.jobs.tabs[tab]) + '</span>' + (n ? '<span class="badge">' + n + '</span>' : '') + '</button>';
    }).join('') + '</div>';

    var jobs = ofKind((data && data.jobs) || [], kind);
    if (q.error && !data) body += errorCard(q.error, 'cleaning:reload');
    else if (!data) body += skeletonCards(3);
    else if (!jobs.length) {
      var e = T.jobs.empty[ui.tab];
      body += emptyState(ui.tab === 'done' ? 'check' : ui.tab === 'overdue' ? 'clock' : 'sun', e[0], e[1]);
    } else {
      var showDay = ui.tab === 'upcoming' || ui.tab === 'overdue';
      body += jobs.map(function (job) { return jobCard(job, { action: 'cleaning:open', showDay: showDay, queued: queued[job.id] }); }).join('');
    }

    return '<main class="screen">' +
      S.topHeader(S.apps().length > 1 ? (T.titles[kind] || T.title) : T.greeting(firstName), S.apps().length > 1 ? T.greeting(firstName) + ' · ' + todayLongLabel() : todayLongLabel()) +
      '<div class="screen-body">' + body + '</div>' +
      '<div class="footer">' + button(T.jobs.newJob, 'cleaning:new', { icon: 'plus', data: 'data-kind="' + esc(kind || '') + '"' }) + '</div>' +
      '</main>';
  }

  // --- Requests -----------------------------------------------------------------

  function renderRequests(route) {
    var data = cacheGet('jobs:requests');
    var q = query('jobs:requests');
    var jobs = ofKind((data && data.jobs) || [], route.kind || null);
    var body = '<h1 class="h2">' + esc(T.requests.title) + '</h1><p class="secondary mt-1">' + esc(T.requests.intro) + '</p>';
    if (q.error && !data) body += errorCard(q.error, 'cleaning:reload');
    else if (!data) body += skeletonCards(2);
    else if (!jobs.length) body += emptyState('check', T.requests.emptyTitle, T.requests.emptyBody);
    else body += jobs.map(function (job) { return jobCard(job, { action: 'cleaning:claim' }); }).join('');
    return '<main class="screen">' + S.backBar('core:back', T.requests.back) + '<div class="screen-body">' + body + '</div></main>';
  }

  var claiming = false;
  function claim(jobId) {
    S.sheet({
      title: T.requests.confirmTitle,
      body: T.requests.confirmBody,
      confirm: T.requests.confirm,
      cancel: T.requests.cancel,
      onConfirm: function () {
        if (claiming) return;
        claiming = true;
        api('jobs/' + jobId + '/claim', { method: 'POST' }).then(function (d) {
          cacheSet('job:' + d.job.id, d.job);
          markStale('jobs:');
          S.go({ app: 'cleaning', view: 'job', id: d.job.id }, true);
          refreshScreen();
        }, function (e) {
          S.toast(e.message || T.errors.genericBody);
          markStale('jobs:');
          refreshScreen();
        }).then(function () { claiming = false; });
      },
    });
  }

  // --- Job ----------------------------------------------------------------------

  function currentJob(routeId) {
    var id = resolveJobId(routeId);
    var job = cacheGet('job:' + id);
    return job ? mergeOutbox(job, id) : null;
  }

  function section(title, inner, last) {
    return '<section class="jsection' + (last ? ' last' : '') + '">' + (title ? '<h2 class="h3 stitle">' + esc(title) + '</h2>' : '') + inner + '</section>';
  }

  function photoGroup(job, type, qs, canAdd) {
    var photos = (job.photos || []).filter(function (p) { return p.photo_type === type; });
    var tiles = photos.map(function (p) {
      var qstate = p.id < 0 ? qs.photos[p.id] || null : null;
      var bad = qstate && qstate.state === 'failed';
      var pending = p.id < 0 && !bad;
      var video = p.media_kind === 'video';
      var url = video ? null : mediaUrl(p);
      return '<div class="tile" data-key="p' + p.id + '">' +
        '<button type="button" class="thumb' + (video ? ' vid' : '') + '" data-action="cleaning:photo" data-id="' + p.id + '"' + (pending ? ' disabled' : '') + '>' +
        (video ? '<span class="play">' + icon('play') + '</span>' : (url ? '<img src="' + esc(url) + '" alt="">' : '')) +
        (pending ? '<span class="over">' + icon('upload') + '</span>' : '') +
        (bad ? '<span class="over bad">' + icon('alert') + '<small>' + esc(T.queue.notSent) + '</small></span>' : '') +
        '</button>' +
        (p.can_delete ? '<button type="button" class="remove" data-action="cleaning:photo-delete" data-id="' + p.id + '" aria-label="Delete">' + icon('close') + '</button>' : '') +
        '</div>';
    }).join('');
    if (!canAdd && !photos.length) tiles += '<div class="body-lg muted">' + esc(T.job.photos.none) + '</div>';
    var adds = canAdd ? '<div class="adds' + (photos.length ? ' mt-3' : '') + '">' +
      '<button type="button" class="add" data-action="cleaning:capture" data-type="' + type + '" data-kind="photo">' + icon('camera') + '<span>' + esc(T.job.photos.take) + '</span></button>' +
      '<button type="button" class="add" data-action="cleaning:capture" data-type="' + type + '" data-kind="video">' + icon('video') + '<span>' + esc(T.job.photos.record) + '</span></button>' +
      '<button type="button" class="add" data-action="cleaning:upload" data-type="' + type + '">' + icon('gallery') + '<span>' + esc(T.job.photos.upload) + '</span></button>' +
      '</div>' : '';
    return '<h3 class="h3 stitle">' + esc(type === 'before' ? T.job.photos.beforeTitle : T.job.photos.afterTitle) + '</h3>' +
      '<div class="grid">' + tiles + '</div>' + adds;
  }

  function checklistSection(job, draft) {
    var c = job.checklist;
    var saved = c.saved;
    var editable = job.status === 'in_progress';
    var answers = saved ? saved.items : draft.items;
    var problems = saved ? saved.problems : draft.problems;
    var total = checklistTotal(c);
    var answered = checklistAnswered(c, answers);
    var html = '<h2 class="h3">' + esc(T.job.checklist.title) + '</h2>' +
      (job.status === 'open'
        ? '<div class="secondary mt-1">' + esc(T.job.checklist.beforeStart) + '</div>'
        : '<div class="small mt-1" style="font-weight:600;color:' + (answered === total ? 'var(--success)' : 'var(--muted)') + '">' + esc(T.job.checklist.progress(answered, total)) + '</div>');
    c.sections.forEach(function (s) {
      html += '<div class="label muted mt-4">' + esc(s.title) + '</div>';
      s.items.forEach(function (item) {
        var st = answers[item.key];
        html += '<div class="cl-row" data-key="ci' + esc(item.key) + '"><span class="cl-mark ' + (st || '') + '">' + icon(st === 'done' ? 'tick' : st === 'na' ? 'minus' : 'circle') + '</span>' +
          '<span class="grow body-lg' + (st === 'na' ? ' muted' : '') + '">' + esc(item.label) + '</span>' +
          (editable ? '<span class="cl-choices">' +
            '<button type="button" class="choice' + (st === 'done' ? ' on done' : '') + '" data-action="cleaning:cl-item" data-item="' + esc(item.key) + '" data-state="done">' + esc(T.job.checklist.done) + '</button>' +
            '<button type="button" class="choice' + (st === 'na' ? ' on na' : '') + '" data-action="cleaning:cl-item" data-item="' + esc(item.key) + '" data-state="na">' + esc(T.job.checklist.na) + '</button>' +
            '</span>' : '') + '</div>';
      });
    });
    if (editable) {
      html += '<div class="label muted mt-4">' + esc(T.job.checklist.problemsTitle) + '</div><div class="small muted mt-1">' + esc(T.job.checklist.problemsHint) + '</div>' +
        '<div class="chips mt-3">' + c.problems.map(function (p) {
          var on = problems.indexOf(p.key) !== -1;
          return '<button type="button" class="choice' + (on ? ' on warn' : '') + '" data-action="cleaning:cl-problem" data-problem="' + esc(p.key) + '">' + esc(p.label) + '</button>';
        }).join('') + '</div>' +
        (problems.length ? '<textarea class="field mt-3" id="cl-note" rows="2" placeholder="' +
          esc(problems.indexOf('other') !== -1 ? T.job.checklist.notePlaceholderRequired : T.job.checklist.notePlaceholder) + '">' + esc(draft.problem_note) + '</textarea>' : '');
    } else if (saved) {
      html += '<div class="label muted mt-4">' + esc(T.job.checklist.problemsTitle) + '</div>';
      if (!saved.problems.length && !saved.problem_note) html += '<div class="muted mt-1">' + esc(T.job.checklist.noProblems) + '</div>';
      saved.problems.forEach(function (k) {
        var p = c.problems.find(function (x) { return x.key === k; });
        html += '<div class="body-lg mt-1">' + esc(p ? p.label : k) + '</div>';
      });
      if (saved.problem_note) html += '<div class="secondary mt-1">' + esc(saved.problem_note) + '</div>';
      if (saved.maintenance_job_id) html += '<div class="small mt-1" style="color:#2563EB">' + esc(T.job.checklist.maintenanceRaised) + '</div>';
    }
    return html;
  }

  function jobFooter(job, qs, blocked) {
    var html = '';
    if (qs.action) html += '<div class="mb-3">' + queueNote(qs.action) + '</div>';
    if (job.status === 'done') {
      return html + '<div class="banner done">' + icon('check', 'lg') + '<div><strong>' + esc(T.job.completedBanner) + '</strong>' +
        (job.duration_label ? '<div class="small secondary">' + esc(T.job.completedIn(job.duration_label)) + '</div>' : '') + '</div></div>';
    }
    if (job.status === 'cancelled') {
      return html + '<div class="banner">' + icon('alert', 'lg') + '<div><strong>' + esc(T.job.cancelledBanner) + '</strong><div class="small muted">' + esc(T.job.cancelledBody) + '</div></div></div>';
    }
    if (job.status === 'in_progress' && job.is_paused) {
      var reason = T.job.pauseReasons[job.pause_reason] || T.job.pauseReasons.other;
      return html + '<div class="paused">' + icon('pause') + esc(T.job.pausedBanner(reason, job.paused_at ? clockFromDateTime(job.paused_at) : '')) + '</div>' +
        button(T.job.resume, 'cleaning:resume', { icon: 'play', disabled: qs.sendingKinds.indexOf('resume') !== -1 });
    }
    var pauseBtn = job.status === 'in_progress'
      ? button(T.job.pause, 'cleaning:pause', { variant: 'secondary', icon: 'pause', disabled: qs.sendingKinds.indexOf('pause') !== -1, style: 'flex:2' })
      : '';
    if (blocked.start || blocked.finish) {
      return html + '<div class="hint">' + icon(blocked.start || blocked.photo ? 'camera' : 'list', 'sm') + esc(blocked.reason) + '</div>' +
        '<div class="action-row">' + (blocked.finish ? pauseBtn : '') +
        button(blocked.start ? T.job.start : T.job.finish, 'cleaning:noop', { disabled: true, style: 'flex:3' }) + '</div>';
    }
    var running = job.status === 'in_progress';
    return html + '<div class="action-row">' + pauseBtn +
      button(running ? T.job.finish : T.job.start, running ? 'cleaning:finish' : 'cleaning:start', {
        disabled: qs.sendingKinds.indexOf(running ? 'finish' : 'start') !== -1, style: 'flex:3',
      }) + '</div>';
  }

  function blockedState(job, qs, draft) {
    var evidence = (job.photos || []).filter(function (p) { return !(qs.photos[p.id] && qs.photos[p.id].state === 'failed'); });
    var hasBefore = evidence.some(function (p) { return p.photo_type === 'before'; });
    var hasAfter = evidence.some(function (p) { return p.photo_type === 'after'; });
    var startBlocked = job.status === 'open' && !hasBefore;
    var needPhoto = job.status === 'in_progress' && !hasAfter;
    var c = job.checklist;
    var left = c ? checklistTotal(c) - checklistAnswered(c, draft.items) : 0;
    var needList = job.status === 'in_progress' && left > 0;
    var needNote = job.status === 'in_progress' && !!c && draft.problems.indexOf('other') !== -1 && !draft.problem_note.trim();
    var needGas = job.status === 'in_progress' && !!openGasReading(job);
    var finish = needPhoto || needList || needNote || needGas;
    return {
      start: startBlocked,
      finish: finish,
      photo: needPhoto,
      reason: startBlocked ? T.job.startNeedsPhoto : needPhoto ? T.job.finishNeedsPhoto : needList ? T.job.finishNeedsChecklist(left)
        : needNote ? T.job.finishNeedsProblemNote : T.job.gas.finishNeedsAfter,
    };
  }

  // --- Gas (R410): weighed before and after, per unit ----------------------------

  function openGasReading(job) {
    return job.gas ? job.gas.readings.find(function (x) { return x.after_kg === null; }) : null;
  }

  function kgLabel(kg) {
    return (Math.round(Number(kg) * 1000) / 1000).toString() + ' kg';
  }

  /** "16.75" or "16,75" → 16.75; null when it is not a weight the server will take. */
  function parseKg(text) {
    var t = String(text || '').trim().replace(',', '.');
    if (!/^\d{1,3}(\.\d{1,3})?$/.test(t)) return null;
    var kg = Number(t);
    return kg > 0 && kg <= 100 ? kg : null;
  }

  function gasPhotoItem(reading, stage) {
    var local = stage === 'after' ? reading.after_local : reading.before_local;
    if (local) return { localKey: local };
    var path = stage === 'after' ? reading.after_photo_path : reading.before_photo_path;
    return path ? { url_path: path } : null;
  }

  function gasThumb(reading, stage) {
    var item = gasPhotoItem(reading, stage);
    if (!item) return '';
    var url = mediaUrl(item);
    return '<button type="button" class="thumb gthumb" data-action="cleaning:gas-view" data-ref="' + esc(reading.client_ref) + '" data-stage="' + stage + '">' +
      (url ? '<img src="' + esc(url) + '" alt="">' : '') + '</button>';
  }

  function gasSection(job, qs) {
    var g = job.gas;
    var editable = job.status === 'open' || job.status === 'in_progress';
    var open = openGasReading(job);
    var html = '<h2 class="h3">' + esc(T.job.gas.title) + '</h2>';
    if (editable) html += '<div class="secondary mt-1">' + esc(T.job.gas.hint) + '</div>';
    if (!g.readings.length && !editable) {
      html += '<div class="body-lg muted mt-2">' + esc(g.used === false ? T.job.gas.noGasUsed : T.job.gas.none) + '</div>';
    }
    g.readings.forEach(function (r) {
      var qb = qs.gas[r.client_ref + ':before'];
      var qa = qs.gas[r.client_ref + ':after'];
      html += '<div class="gas-row" data-key="g' + esc(r.client_ref) + '">' +
        '<div class="row"><span class="grow"><strong>' + esc(T.job.gas.unit) + ' ' + esc(r.unit_label) + '</strong></span>' +
        (r.used_kg !== null ? pill(T.job.gas.used + ' ' + kgLabel(r.used_kg), '#7C3AED', 700) : '') + '</div>' +
        '<div class="gas-pair mt-2">' +
        '<div class="gas-cell">' + gasThumb(r, 'before') + '<div><div class="small muted">' + esc(T.job.gas.before) + '</div><div class="body-lg"><strong>' + esc(kgLabel(r.before_kg)) + '</strong></div>' +
        '<div class="small muted">' + esc(clockFromDateTime(r.before_at)) + '</div></div></div>' +
        '<div class="gas-cell">' + (r.after_kg !== null ? gasThumb(r, 'after') + '<div><div class="small muted">' + esc(T.job.gas.after) + '</div><div class="body-lg"><strong>' + esc(kgLabel(r.after_kg)) + '</strong></div>' +
          '<div class="small muted">' + esc(clockFromDateTime(r.after_at)) + '</div></div>'
          : '<div class="small muted">' + esc(T.job.gas.waitingAfter) + '</div>') + '</div>' +
        '</div>' +
        (qb ? '<div class="mt-2">' + queueNote(qb) + '</div>' : '') +
        (qa ? '<div class="mt-2">' + queueNote(qa) + '</div>' : '') +
        '</div>';
    });
    if (editable) {
      html += '<div class="mt-3">' + (open
        ? button(T.job.gas.weighAfter + ' — ' + open.unit_label, 'cleaning:gas-after', { icon: 'camera', data: 'data-ref="' + esc(open.client_ref) + '"' })
        : button(T.job.gas.weighBefore, 'cleaning:gas-before', { variant: 'secondary', icon: 'camera' })) + '</div>';
    }
    return html;
  }

  function gasPlaces(job) {
    return (job.places || []).slice().sort(function (a, b) { return (a.kind === 'unit' ? 0 : 1) - (b.kind === 'unit' ? 0 : 1); });
  }

  function startGas(routeJobId, stage, ref) {
    var job = currentJob(routeJobId);
    if (!job || !job.gas) return;
    var places = gasPlaces(job);
    if (ui.gas && ui.gas.photo && ui.gas.photo.url) URL.revokeObjectURL(ui.gas.photo.url);
    ui.gas = {
      jobId: routeJobId, stage: stage,
      ref: stage === 'before' ? 'g' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8) : ref,
      place: stage === 'before' && places.length === 1 ? places[0] : null,
      unitText: '', kg: '', photo: null, saving: false,
    };
    S.go({ app: 'cleaning', view: 'gas', id: routeJobId });
    window.scrollTo(0, 0);
  }

  function gasProblem(g, reading) {
    if (g.stage === 'before' && !g.place && !g.unitText.trim()) return T.job.gas.needUnit;
    if (!g.photo) return T.job.gas.needPhoto;
    var kg = parseKg(g.kg);
    if (kg === null) return T.job.gas.badKg;
    if (g.stage === 'after' && reading && kg > reading.before_kg) return T.job.gas.moreThanBefore;
    return null;
  }

  function renderGas(route) {
    var job = currentJob(route.id);
    var g = ui.gas;
    var top = S.backBar('core:back', T.job.back);
    if (!job || !g || g.jobId !== route.id) {
      // Reopened from history with nothing in hand: back to the job.
      return '<main class="screen">' + top + '<div class="screen-body"><button type="button" class="btn" data-action="core:back">' + esc(T.job.back) + '</button></div></main>';
    }
    var reading = g.stage === 'after' ? (job.gas.readings || []).find(function (x) { return x.client_ref === g.ref; }) : null;
    var n = 1;
    var body = '<h1 class="h2">' + esc(g.stage === 'before' ? T.job.gas.screenBefore : T.job.gas.screenAfter) + '</h1>' +
      '<div class="secondary mt-1">' + esc(jobHeading(job).heading) + '</div>';

    if (g.stage === 'before') {
      var places = gasPlaces(job);
      body += '<div class="label muted mt-5">' + esc(T.job.gas.stepUnit) + '</div>';
      if (places.length) {
        body += '<div class="chips mt-2">' + places.map(function (p, i) {
          var on = g.place && g.place.kind === p.kind && g.place.id === p.id;
          return '<button type="button" class="choice' + (on ? ' on done' : '') + '" data-action="cleaning:gas-place" data-i="' + i + '">' + esc(p.label) + '</button>';
        }).join('') + '</div>';
      } else {
        body += '<input class="field mt-2" id="gas-unit" autocomplete="off" placeholder="' + esc(T.job.gas.unitPlaceholder) + '" value="' + esc(g.unitText) + '">';
      }
      n = 2;
    } else if (reading) {
      body += '<div class="banner mt-4"><div><strong>' + esc(T.job.gas.unit) + ' ' + esc(reading.unit_label) + '</strong>' +
        '<div class="small secondary">' + esc(T.job.gas.beforeWas(kgLabel(reading.before_kg))) + '</div></div></div>';
    }

    body += '<div class="label muted mt-5">' + esc(T.job.gas.stepPhoto(n)) + '</div>';
    body += g.photo
      ? '<div class="gas-shot mt-2"><img src="' + esc(g.photo.url) + '" alt=""></div>' +
        '<button type="button" class="btn plain mt-2" data-action="cleaning:gas-photo">' + icon('camera') + esc(T.job.gas.retake) + '</button>'
      : '<button type="button" class="add gas-take mt-2" data-action="cleaning:gas-photo">' + icon('camera', 'lg') + '<span>' + esc(T.job.gas.takePhoto) + '</span></button>';

    body += '<div class="label muted mt-5">' + esc(T.job.gas.stepKg(n + 1)) + '</div>' +
      '<div class="gas-kg mt-2"><input class="field" id="gas-kg" inputmode="decimal" autocomplete="off" placeholder="' + esc(T.job.gas.kgPlaceholder) + '" value="' + esc(g.kg) + '"><span>kg</span></div>';

    var kg = parseKg(g.kg);
    if (g.stage === 'after' && reading && kg !== null) {
      body += kg > reading.before_kg
        ? '<div class="qfail mt-3"><div class="row">' + icon('alert') + '<strong>' + esc(T.job.gas.moreThanBefore) + '</strong></div></div>'
        : '<div class="mt-3">' + pill(T.job.gas.usedNow(kgLabel(Math.round((reading.before_kg - kg) * 1000) / 1000)), '#7C3AED', 700) + '</div>';
    }

    var problem = gasProblem(g, reading);
    return '<main class="screen">' + top + '<div class="screen-body">' + body + '</div>' +
      '<div class="footer">' + (problem && (g.photo || g.kg) ? '<div class="hint">' + esc(problem) + '</div>' : '') +
      button(T.job.gas.save, 'cleaning:gas-save', { icon: 'check', disabled: !!problem || g.saving }) + '</div></main>';
  }

  function saveGas(routeJobId) {
    var g = ui.gas;
    var job = currentJob(routeJobId);
    if (!g || !job) return;
    var reading = g.stage === 'after' ? job.gas.readings.find(function (x) { return x.client_ref === g.ref; }) : null;
    var problem = gasProblem(g, reading);
    if (problem) { S.toast(problem); return; }
    g.saving = true;
    S.render();
    addGas(routeJobId, g).then(function () {
      if (g.photo && g.photo.url) URL.revokeObjectURL(g.photo.url);
      ui.gas = null;
      S.toast(T.job.gas.saved);
      S.back();
    }, function (e) {
      g.saving = false;
      S.toast(e.message || T.errors.couldNotSend);
      S.render();
    });
  }

  function openFinishSheet(routeJobId, gasUsed) {
    ui.finishNotes = '';
    S.sheet({
      title: T.job.finishSheet.title, body: T.job.finishSheet.body,
      html: function () {
        return '<textarea class="field mt-3" id="finish-notes" rows="3" placeholder="' + esc(T.job.finishSheet.notesPlaceholder) + '">' + esc(ui.finishNotes) + '</textarea>';
      },
      confirm: T.job.finishSheet.confirm, cancel: T.job.finishSheet.cancel,
      onConfirm: function () {
        var current = currentJob(routeJobId);
        finishJob(routeJobId, ui.finishNotes.trim(), current && current.checklist ? getDraft(routeJobId) : null, gasUsed);
      },
    });
  }

  function renderJob(route) {
    var job = currentJob(route.id);
    var id = resolveJobId(route.id);
    var q = query('job:' + id);
    var count = job ? (job.comments || []).length : 0;
    var top = S.backBar('core:back', T.job.back, job
      ? '<button type="button" class="icon-btn notes-btn" data-action="cleaning:notes" aria-label="' + esc(T.job.messages) + '">' + icon('note') +
        (count ? '<span class="nbadge">' + (count > 9 ? '9+' : count) + '</span>' : '') + '</button>'
      : '');
    if (!job) {
      var inner = q.error ? errorCard(q.error, 'cleaning:reload') : '<div class="skel" style="height:28px;width:40%"></div><div class="skel" style="height:40px"></div><div class="skel" style="height:120px"></div>';
      return '<main class="screen">' + top + '<div class="screen-body">' + inner + '</div></main>';
    }
    var qs = jobQueueState(route.id);
    var draft = getDraft(route.id);
    if (job.status === 'done' && job.checklist && job.checklist.saved) clearDraft(route.id);
    var blocked = blockedState(job, qs, draft);
    var h = jobHeading(job);
    var color = TYPE_COLOR[job.job_type] || '#10233C';
    var closed = job.status === 'done' || job.status === 'cancelled';

    var body = '<div class="headline"><div class="row"><span class="type" style="color:' + color + '">' + icon(TYPE_ICON[job.job_type] || 'sparkles', 'sm') +
      esc(T.jobType[job.job_type] || job.job_type_label) + '</span>' + (job.is_urgent ? pill(T.jobs.urgent, '#DC2626', 700) : '') + '</div>' +
      '<h1 class="h2 mt-3">' + esc(h.heading) + '</h1>' +
      h.lines.map(function (l) { return '<div class="meta big">' + icon('pin') + '<span>' + esc(l) + '</span></div>'; }).join('') +
      (job.location ? '<div class="meta big">' + icon('note') + '<span>' + esc(job.location) + '</span></div>' : '') +
      '<div class="meta big">' + icon('clock') + '<span>' + esc(whenLabel(job.scheduled_date, job.scheduled_time)) + '</span></div>' +
      '<div class="pills mt-4">' + statusPill(job) +
      (job.status === 'in_progress' && job.started_at ? '<span class="small muted">' + esc(T.job.startedAt(clockFromDateTime(job.started_at))) + '</span>' : '') +
      '</div></div>';

    if (job.description) body += section(T.job.whatToDo, '<div class="body-lg pre">' + esc(job.description) + '</div>');
    if (job.request_note && !job.description) body += section(T.job.whatToDo, '<div class="body-lg pre">' + esc(job.request_note) + '</div>');
    if (job.request_photos && job.request_photos.length) {
      body += section(T.job.tenantPhotos, '<div class="grid">' + job.request_photos.map(function (p) {
        var url = mediaUrl(p);
        return '<button type="button" class="thumb" data-key="rp' + p.id + '" data-action="cleaning:request-photo" data-id="' + p.id + '">' + (url ? '<img src="' + esc(url) + '" alt="">' : '') + '</button>';
      }).join('') + '</div>');
    }

    var started = job.status === 'in_progress';
    var photos = photoGroup(job, 'before', qs, job.status === 'open') +
      '<div class="mt-5">' + (started || closed ? photoGroup(job, 'after', qs, started) : '<div class="small muted">' + esc(T.job.photos.afterLocked) + '</div>') + '</div>';
    body += section(null, photos, job.status === 'open' && !job.checklist);

    if (job.checklist && (job.status !== 'done' || job.checklist.saved)) body += section(null, checklistSection(job, draft), job.status === 'open');

    if (job.gas && job.status !== 'cancelled') body += section(null, gasSection(job, qs));

    if (closed) {
      var used = (job.materials || []).filter(function (m) { return m.kind === 'used'; });
      body += section(T.job.materials.usedTitle, used.length ? used.map(function (m) {
        return '<div class="row mt-2">' + icon('box') + '<div><strong>' + esc(m.material_name) + '</strong><div class="small muted">' + esc(m.quantity + (m.unit ? ' ' + m.unit : '')) + '</div></div></div>';
      }).join('') : '<div class="body-lg muted">' + esc(T.job.materials.usedNone) + '</div>', true);
    } else if (started) {
      body += section(null, '<button type="button" class="ask-row" data-action="cleaning:ask">' + icon('box', 'lg') +
        '<span class="grow"><strong>' + esc(T.job.materials.requestAdd) + '</strong><span class="small muted" style="display:block">' + esc(T.job.materials.requestHint) + '</span></span>' + icon('chevron') + '</button>', true);
    }

    return '<main class="screen">' + top + '<div class="screen-body">' + body + '</div><div class="footer">' + jobFooter(job, qs, blocked) + '</div></main>';
  }

  // --- Messages -----------------------------------------------------------------

  function bubble(c, qitem) {
    var mine = c.is_mine;
    var media = c.media || [];
    var voice = media.filter(function (m) { return m.kind === 'voice'; });
    var visual = media.filter(function (m) { return m.kind !== 'voice'; });
    var html = '<div class="brow' + (mine ? ' mine' : '') + '" data-key="c' + c.id + '"><div class="bubble' + (mine ? ' mine' : '') + '">';
    if (!mine) html += '<div class="label gold-700">' + esc(c.author_name || T.notes.office) + '</div>';
    if (c.is_material_request) html += '<div class="mtag">' + icon('box', 'sm') + esc(T.notes.materialTag) + '</div>';
    voice.forEach(function (m) {
      var key = mediaKeyOf(m);
      mediaUrl(m); // fetched ahead, so the tap plays straight away
      var mineNow = player.key === key && player.audio;
      var playing = mineNow && player.playing;
      var a = mineNow ? player.audio : null;
      var total = a && isFinite(a.duration) && a.duration > 0 ? a.duration : 0;
      var width = total ? Math.min(100, (a.currentTime / total) * 100) : 0;
      var shown = a && a.currentTime > 0 ? a.currentTime : m.duration_seconds;
      html += '<button type="button" class="voice" data-action="cleaning:voice" data-comment="' + c.id + '" data-media="' + m.id + '">' +
        '<span class="vbtn">' + icon(playing ? 'pause' : 'play') + '</span><span class="grow"><span class="vtrack"><span class="vfill" style="width:' + width.toFixed(1) + '%" data-voice-fill="' + esc(key) + '"></span></span>' +
        '<span class="small muted" data-voice-time="' + esc(key) + '">' + (S.mediaFailed(key) ? esc(T.notes.voiceFailed) : clockLength(shown)) + '</span></span></button>';
    });
    if (visual.length) {
      html += '<div class="mgrid' + (visual.length === 1 ? ' one' : '') + '">' + visual.map(function (m) {
        if (m.kind === 'video') {
          return '<button type="button" class="mtile vid" data-action="cleaning:comment-media" data-comment="' + c.id + '" data-media="' + m.id + '"><span class="play">' + icon('play') + '</span></button>';
        }
        var url = mediaUrl(m);
        return '<button type="button" class="mtile" data-action="cleaning:comment-media" data-comment="' + c.id + '" data-media="' + m.id + '">' + (url ? '<img src="' + esc(url) + '" alt="">' : '') + '</button>';
      }).join('') + '</div>';
    }
    if (c.has_text) html += '<div class="body-lg pre">' + esc(c.comment) + '</div>';
    html += '<div class="small muted mt-1">' + esc(noteStamp(c.created_at)) + '</div>';
    if (qitem) html += '<div class="mt-2">' + queueNote(qitem) + '</div>';
    return html + '</div></div>';
  }

  function renderNotes(route) {
    var job = currentJob(route.id);
    var id = resolveJobId(route.id);
    var q = query('job:' + id);
    var qs = jobQueueState(route.id);
    var comments = job ? job.comments || [] : [];
    var thread;
    if (!job) thread = q.error ? errorCard(q.error, 'cleaning:reload') : '<div class="skel" style="height:48px;width:60%"></div><div class="skel" style="height:48px;width:70%;margin-left:auto"></div>';
    else if (!comments.length) thread = emptyState('note', T.notes.emptyTitle, T.notes.emptyBody);
    else thread = comments.map(function (c) { return bubble(c, qs.comments[c.id]); }).join('');

    var recording = rec.active;
    var hasText = ui.draft.trim().length > 0;
    var composer = '<div class="composer' + (recording ? ' recording' : '') + '">' +
      '<div class="recbar" data-key="bar"' + (recording ? '' : ' hidden') + '><span class="recdot"></span><strong data-rec-time>0:00</strong>' +
      '<span class="grow small' + (rec.cancel ? ' danger' : ' muted') + '" style="text-align:right">' + esc(rec.cancel ? T.notes.cancelled : T.notes.slideToCancel) + '</span></div>' +
      '<button type="button" class="cbtn" data-key="cam" data-action="cleaning:msg-camera" aria-label="Camera"' + (recording ? ' hidden' : '') + '>' + icon('camera') + '</button>' +
      '<button type="button" class="cbtn" data-key="gal" data-action="cleaning:msg-gallery" aria-label="Photos"' + (recording ? ' hidden' : '') + '>' + icon('gallery') + '</button>' +
      '<textarea class="cinput" data-key="txt" id="msg-input" rows="1" placeholder="' + esc(T.notes.placeholder) + '"' +
      (ui.draftHeight ? ' style="height:' + ui.draftHeight + 'px"' : '') + (recording ? ' hidden' : '') + '>' + esc(ui.draft) + '</textarea>' +
      '<button type="button" class="cbtn send" data-key="send" data-action="cleaning:msg-send" aria-label="Send"' + (hasText && !recording ? '' : ' hidden') + '>' + icon('send') + '</button>' +
      '<button type="button" class="cbtn mic' + (recording ? ' live' : '') + (rec.cancel ? ' cancel' : '') + '" data-key="mic" data-mic aria-label="Hold to record a voice message"' + (hasText && !recording ? ' hidden' : '') + '>' + icon('mic') + '</button>' +
      '</div>';

    return '<main class="screen notes">' +
      '<div class="notes-head">' + S.backBar('core:back', T.job.back) +
      '<div class="grow"><div class="h3">' + esc(T.notes.title) + '</div>' + (job ? '<div class="small muted ellipsis">' + esc(jobHeading(job).heading) + '</div>' : '') + '</div></div>' +
      '<div class="screen-body thread">' + thread + '</div>' +
      '<div class="footer notes-foot">' +
      (ui.asking ? '<div class="asking">' + icon('box') + '<div class="grow"><strong>' + esc(T.notes.askingTitle) + '</strong><div class="small secondary">' + esc(T.notes.askingBody) + '</div></div>' +
        '<button type="button" class="icon-btn" data-action="cleaning:ask-off" aria-label="Never mind">' + icon('close') + '</button></div>' : '') +
      composer + '</div></main>';
  }

  function sendMessage(routeJobId, media) {
    var text = ui.draft.trim();
    var asking = ui.asking;
    ui.draft = '';
    ui.draftHeight = 0;
    ui.asking = false;
    var input = document.getElementById('msg-input');
    if (input) { input.value = ''; input.style.height = ''; }
    addComment(routeJobId, text, media || [], asking).then(function () { S.render(); scrollThread(); }, function (e) {
      S.toast(e.message || T.errors.couldNotSend);
    });
    S.render();
  }

  function scrollThread() {
    requestAnimationFrame(function () { window.scrollTo(0, document.documentElement.scrollHeight); });
  }

  // --- New job ------------------------------------------------------------------

  function suggestTitle(jobType, places) {
    if (!places.length) return '';
    return (T.jobType[jobType] + ' — ' + places[0].label + (places.length > 1 ? ' +' + (places.length - 1) : '')).slice(0, 255);
  }

  function renderNew(route) {
    var n = ui.newJob || (ui.newJob = { jobType: route.kind || 'cleaning', places: [], picker: false, building: null, search: '' });
    if (n.picker) return renderPicker(n);
    // Opened from the Cleaning or Maintenance tile: the type is already chosen.
    var fixed = T.newJob.titleFor[route.kind];
    var body = '<h1 class="h2">' + esc(fixed || T.newJob.title) + '</h1>' +
      (fixed ? '' : '<div class="label muted mt-5">' + esc(T.newJob.typeLabel) + '</div><div class="types mt-2">' +
      ['cleaning', 'maintenance'].map(function (t) {
        var on = n.jobType === t;
        return '<button type="button" class="type-btn' + (on ? ' on' : '') + '" data-action="cleaning:new-type" data-type="' + t + '">' + icon(TYPE_ICON[t]) + esc(T.jobType[t]) + '</button>';
      }).join('') + '</div>') +
      '<div class="label muted mt-5">' + esc(T.newJob.placesLabel) + '</div>' +
      (n.places.length ? '<div class="chips mt-2">' + n.places.map(function (p, i) {
        return '<button type="button" class="chip" data-action="cleaning:new-unplace" data-i="' + i + '"><span class="ellipsis">' + esc(p.label) + '</span>' + icon('close', 'sm') + '</button>';
      }).join('') + '</div>' : '') +
      '<div class="mt-2">' + button(n.places.length ? T.newJob.addMorePlaces : T.newJob.choosePlaces, 'cleaning:new-picker', { variant: 'secondary', icon: 'pin' }) + '</div>';
    return '<main class="screen">' + S.backBar('core:back', T.newJob.back) + '<div class="screen-body">' + body + '</div>' +
      '<div class="footer">' + button(T.newJob.create, 'cleaning:new-create', { icon: 'tick', disabled: !n.places.length }) + '</div></main>';
  }

  var PICKER_ROWS = 250;

  function renderPicker(n) {
    var data = cacheGet('places');
    var buildings = (data && data.buildings) || [];
    var q = n.search.trim().toLowerCase();
    var searching = q.length > 0;
    var open = n.building || (buildings[0] && buildings[0].id) || null;
    var rows = [];
    (searching ? buildings : buildings.filter(function (b) { return b.id === open; })).forEach(function (b) {
      b.places.forEach(function (p) {
        if (searching && p.label.toLowerCase().indexOf(q) === -1 && b.name.toLowerCase().indexOf(q) === -1) return;
        rows.push({ p: p, b: b });
      });
    });
    var isOn = function (p) { return n.places.some(function (x) { return x.kind === p.kind && x.id === p.id; }); };
    var list = rows.slice(0, PICKER_ROWS).map(function (r) {
      var on = isOn(r.p);
      return '<button type="button" class="prow' + (on ? ' on' : '') + '" data-key="pl' + r.p.kind + r.p.id + '" data-action="cleaning:place" data-kind="' + r.p.kind + '" data-id="' + r.p.id + '" data-b="' + r.b.id + '">' +
        icon(r.p.kind === 'unit' ? 'box' : 'pin') + '<span class="grow"><strong class="ellipsis" style="display:block">' + esc(r.p.label) + '</strong>' +
        '<span class="small muted ellipsis" style="display:block">' + esc(searching ? r.b.name : r.p.note || '') + '</span></span>' + (on ? icon('tick') : '') + '</button>';
    }).join('');
    if (!rows.length) list = '<div class="muted center pad">' + esc(query('places').loading || !data ? T.places.loading : searching ? T.places.noMatch : T.places.none) + '</div>';
    if (rows.length > PICKER_ROWS) list += '<div class="muted center pad">' + esc(T.places.more) + '</div>';
    return '<main class="screen">' +
      '<div class="header"><h1 class="h2 grow">' + esc(T.places.title) + '</h1><button type="button" class="icon-btn" data-action="cleaning:picker-close" aria-label="' + esc(T.places.close) + '">' + icon('close') + '</button></div>' +
      '<div class="search">' + icon('search') + '<input type="search" id="place-search" autocomplete="off" placeholder="' + esc(T.places.searchPlaceholder) + '" value="' + esc(n.search) + '"></div>' +
      '<div class="btabs">' + buildings.map(function (b) {
        var on = !searching && b.id === open;
        return '<button type="button" class="btab' + (on ? ' on' : '') + '" data-key="b' + b.id + '" data-action="cleaning:building" data-id="' + b.id + '">' + esc(b.name) + '</button>';
      }).join('') + '</div>' +
      '<div class="screen-body">' + list + '</div>' +
      '<div class="footer">' + button(n.places.length ? T.places.doneCount(n.places.length) : T.places.done, 'cleaning:picker-close', { disabled: !n.places.length }) + '</div></main>';
  }

  // -------------------------------------------------------------------------
  // Taps
  // -------------------------------------------------------------------------

  function findPhoto(job, id) { return (job.photos || []).find(function (p) { return p.id === id; }); }

  function findCommentMedia(job, commentId, mediaId) {
    var c = (job.comments || []).find(function (x) { return x.id === commentId; });
    return c ? (c.media || []).find(function (m) { return m.id === mediaId; }) : null;
  }

  function withFiles(list, jobId, handle) {
    if (list.length > MAX_FILES) S.toast(T.job.photos.tooMany(MAX_FILES));
    list.slice(0, MAX_FILES).forEach(function (file) {
      prepareAny(file).then(function (p) { return handle(p); }).catch(function (e) { S.toast(e.message || T.errors.genericBody); });
    });
  }

  function action(name, el) {
    var r = S.state.route;
    var id = Number(el.getAttribute('data-id'));
    var job = r.id !== undefined ? currentJob(r.id) : null;
    switch (name) {
      case 'tab': ui.tab = el.getAttribute('data-tab'); refreshScreen(); break;
      case 'reload': markStale(''); Object.keys(queries).forEach(function (k) { queries[k].errAt = 0; }); refreshScreen(); break;
      case 'open': S.go({ app: 'cleaning', view: 'job', id: id }); refreshScreen(); break;
      case 'requests': S.go({ app: 'cleaning', view: 'requests', kind: el.getAttribute('data-kind') || undefined }); refreshScreen(); break;
      case 'claim': claim(id); break;
      case 'new': ui.newJob = null; S.go({ app: 'cleaning', view: 'new', kind: el.getAttribute('data-kind') || undefined }); refreshScreen(); break;
      case 'check-in': checkInOut('check-in'); break;
      case 'check-out':
        S.sheet({
          title: T.attendance.checkOutTitle, body: T.attendance.checkOutBody,
          confirm: T.attendance.checkOutConfirm, cancel: T.attendance.checkOutCancel,
          onConfirm: function () { checkInOut('check-out'); },
        });
        break;
      case 'notes': ui.asking = false; S.go({ app: 'cleaning', view: 'notes', id: r.id }); ui.lastThreadCount = -1; refreshScreen(); break;
      case 'ask': ui.asking = true; S.go({ app: 'cleaning', view: 'notes', id: r.id }); ui.lastThreadCount = -1; refreshScreen(); break;
      case 'ask-off': ui.asking = false; S.render(); break;
      case 'start':
        S.sheet({
          title: T.job.startSheet.title, body: T.job.startSheet.body, confirm: T.job.startSheet.confirm, cancel: T.job.startSheet.cancel,
          onConfirm: function () { startJob(r.id); },
        });
        break;
      case 'pause':
        S.sheet({
          title: T.job.pauseTitle, cancel: T.job.pauseCancel,
          actions: PAUSE_REASONS.map(function (reason) { return { label: T.job.pauseReasons[reason], action: 'cleaning:pause-reason', data: 'data-reason="' + reason + '"' }; }),
        });
        break;
      case 'pause-reason': S.closeSheet(); pauseJob(r.id, el.getAttribute('data-reason')); break;
      case 'resume': resumeJob(r.id); break;
      case 'finish': {
        // Maintenance: every job answers "did you use gas?". Weights already
        // on the job are the answer.
        if (job && job.gas) {
          if (job.gas.readings.length) { openFinishSheet(r.id, true); break; }
          S.sheet({
            title: T.job.gas.askTitle, body: T.job.gas.askBody,
            actions: [{ label: T.job.gas.askNo, action: 'cleaning:finish-nogas' }],
            confirm: T.job.gas.askYes, cancel: T.job.finishSheet.cancel,
            onConfirm: function () {
              setTimeout(function () {
                S.sheet({ title: T.job.gas.forgotTitle, body: T.job.gas.forgotBody, confirm: T.job.gas.forgotOk });
              }, 0);
            },
          });
          break;
        }
        openFinishSheet(r.id);
        break;
      }
      case 'finish-nogas': S.closeSheet(); openFinishSheet(r.id, false); break;
      case 'gas-before': startGas(r.id, 'before'); break;
      case 'gas-after': startGas(r.id, 'after', el.getAttribute('data-ref')); break;
      case 'gas-place': {
        if (!ui.gas || !job) break;
        var gp = gasPlaces(job)[Number(el.getAttribute('data-i'))];
        if (gp) ui.gas.place = gp;
        S.render();
        break;
      }
      case 'gas-photo': {
        if (!ui.gas) break;
        var gs = ui.gas;
        pickFiles('image/*', true, false).then(function (list) {
          if (!list.length) return;
          preparePhoto(list[0]).then(function (p) {
            if (gs.photo && gs.photo.url) URL.revokeObjectURL(gs.photo.url);
            gs.photo = { blob: p.blob, name: p.name, url: URL.createObjectURL(p.blob) };
            S.render();
          }, function (e) { S.toast(e.message || T.errors.genericBody); });
        });
        break;
      }
      case 'gas-save': saveGas(r.id); break;
      case 'gas-view': {
        if (!job || !job.gas) break;
        var gr = job.gas.readings.find(function (x) { return x.client_ref === el.getAttribute('data-ref'); });
        var gi = gr && gasPhotoItem(gr, el.getAttribute('data-stage'));
        if (gi) openPhoto(gi);
        break;
      }
      case 'capture': {
        var kind = el.getAttribute('data-kind');
        var type = el.getAttribute('data-type');
        pickFiles(kind === 'video' ? 'video/*' : 'image/*', true, false).then(function (list) {
          withFiles(list, r.id, function (p) { return addPhoto(r.id, type, p); });
        });
        break;
      }
      case 'upload': {
        var t = el.getAttribute('data-type');
        pickFiles('image/*,video/*', false, true).then(function (list) {
          withFiles(list, r.id, function (p) { return addPhoto(r.id, t, p); });
        });
        break;
      }
      case 'photo': {
        if (!job) break;
        var photo = findPhoto(job, id);
        if (!photo) break;
        var qitem = photo.id < 0 ? jobQueueState(r.id).photos[photo.id] : null;
        if (qitem && qitem.state === 'failed') {
          S.sheet({
            title: T.queue.failedTitle, body: qitem.reason || undefined, confirm: T.queue.tryAgain, cancel: T.queue.close,
            onConfirm: function () { retryOp(qitem.opId); },
          });
          break;
        }
        if (photo.media_kind === 'video') openVideo(photo); else openPhoto(photo);
        break;
      }
      case 'photo-delete': {
        if (!job) break;
        var p = findPhoto(job, id);
        if (!p) break;
        var video = p.media_kind === 'video';
        S.sheet({
          title: video ? T.job.photos.deleteVideoSheet.title : T.job.photos.deleteSheet.title,
          body: video ? T.job.photos.deleteVideoSheet.body : T.job.photos.deleteSheet.body,
          confirm: T.job.photos.deleteSheet.confirm, cancel: T.job.photos.deleteSheet.cancel, danger: true,
          onConfirm: function () { deletePhoto(r.id, p); },
        });
        break;
      }
      case 'request-photo': {
        if (!job) break;
        var rp = (job.request_photos || []).find(function (x) { return x.id === id; });
        if (rp) openPhoto(rp);
        break;
      }
      case 'cl-item': {
        var d = getDraft(r.id);
        var key = el.getAttribute('data-item');
        var st = el.getAttribute('data-state');
        if (d.items[key] === st) delete d.items[key]; else d.items[key] = st;
        setDraft(r.id, d);
        S.render();
        break;
      }
      case 'cl-problem': {
        var dd = getDraft(r.id);
        var pk = el.getAttribute('data-problem');
        dd.problems = dd.problems.indexOf(pk) !== -1 ? dd.problems.filter(function (x) { return x !== pk; }) : dd.problems.concat([pk]);
        setDraft(r.id, dd);
        S.render();
        break;
      }
      case 'retry': retryOp(el.getAttribute('data-op')); break;
      case 'discard': discardOp(el.getAttribute('data-op')); break;
      case 'msg-send': if (ui.draft.trim()) sendMessage(r.id); break;
      case 'msg-camera':
        pickFiles('image/*,video/*', true, false).then(function (list) { collectAndSend(list, r.id); });
        break;
      case 'msg-gallery':
        pickFiles('image/*,video/*', false, true).then(function (list) { collectAndSend(list, r.id); });
        break;
      case 'voice': {
        if (!job) break;
        var vm = findCommentMedia(job, Number(el.getAttribute('data-comment')), Number(el.getAttribute('data-media')));
        if (vm) toggleVoice(vm);
        break;
      }
      case 'comment-media': {
        if (!job) break;
        var cm = findCommentMedia(job, Number(el.getAttribute('data-comment')), Number(el.getAttribute('data-media')));
        if (!cm) break;
        if (cm.kind === 'video') openVideo(cm); else openPhoto(cm);
        break;
      }
      case 'new-type': ui.newJob.jobType = el.getAttribute('data-type'); S.render(); break;
      case 'new-unplace': ui.newJob.places.splice(Number(el.getAttribute('data-i')), 1); S.render(); break;
      case 'new-picker': ui.newJob.picker = true; ensurePlaces(); S.render(); window.scrollTo(0, 0); break;
      case 'picker-close': ui.newJob.picker = false; ui.newJob.search = ''; S.render(); window.scrollTo(0, 0); break;
      case 'building': ui.newJob.building = id; ui.newJob.search = ''; S.render(); window.scrollTo(0, 0); break;
      case 'place': {
        var n = ui.newJob;
        var pkind = el.getAttribute('data-kind');
        var at = n.places.findIndex(function (x) { return x.kind === pkind && x.id === id; });
        if (at !== -1) { n.places.splice(at, 1); S.render(); break; }
        var data = cacheGet('places');
        var b = data && data.buildings.find(function (x) { return x.id === Number(el.getAttribute('data-b')); });
        var place = b && b.places.find(function (x) { return x.kind === pkind && x.id === id; });
        if (place) n.places.push({ kind: pkind, id: id, label: b.name + ' — ' + place.label, building_id: b.id });
        S.render();
        break;
      }
      case 'new-create': {
        var nj = ui.newJob;
        if (!nj.places.length) { S.toast(T.newJob.placeRequired); break; }
        var created = createJob({ title: suggestTitle(nj.jobType, nj.places), jobType: nj.jobType, places: nj.places });
        ui.newJob = null;
        S.go({ app: 'cleaning', view: 'job', id: created.id }, true);
        break;
      }
    }
  }

  function collectAndSend(list, jobId) {
    if (!list.length) return;
    if (list.length > MAX_FILES) S.toast(T.job.photos.tooMany(MAX_FILES));
    Promise.all(list.slice(0, MAX_FILES).map(function (f) {
      return prepareAny(f).catch(function (e) { S.toast(e.message); return null; });
    })).then(function (items) {
      items = items.filter(Boolean);
      if (items.length) sendMessage(jobId, items);
    });
  }

  function input(e) {
    var id = e.target.id;
    var r = S.state.route;
    if (id === 'msg-input') {
      ui.draft = e.target.value;
      e.target.style.height = 'auto';
      ui.draftHeight = ui.draft ? Math.min(140, e.target.scrollHeight) : 0;
      e.target.style.height = ui.draftHeight ? ui.draftHeight + 'px' : '';
      S.render();
    } else if (id === 'gas-kg' && ui.gas) {
      ui.gas.kg = e.target.value;
      S.render();
    } else if (id === 'gas-unit' && ui.gas) {
      ui.gas.unitText = e.target.value;
      S.render();
    } else if (id === 'finish-notes') {
      ui.finishNotes = e.target.value;
    } else if (id === 'cl-note') {
      var d = getDraft(r.id);
      d.problem_note = e.target.value;
      setDraft(r.id, d);
      S.render();
    } else if (id === 'place-search' && ui.newJob) {
      ui.newJob.search = e.target.value;
      S.render();
    }
  }

  document.addEventListener('pointerdown', function (e) {
    var mic = e.target.closest && e.target.closest('[data-mic]');
    if (mic && S.state.route.app === 'cleaning' && S.state.route.view === 'notes') micDown(e);
  });
  document.addEventListener('contextmenu', function (e) {
    if (e.target.closest && e.target.closest('[data-mic]')) e.preventDefault();
  });

  // -------------------------------------------------------------------------
  // The module
  // -------------------------------------------------------------------------

  function render(route) {
    var view = route.view || 'list';
    if (view === 'requests') return renderRequests(route);
    if (view === 'job') return renderJob(route);
    if (view === 'notes') return renderNotes(route);
    if (view === 'gas') return renderGas(route);
    if (view === 'new') return renderNew(route);
    return renderList(route);
  }

  function afterRender() {
    var r = S.state.route;
    if (r.app !== 'cleaning' || r.view !== 'notes') { ui.lastThreadCount = -1; return; }
    var job = currentJob(r.id);
    var n = job ? (job.comments || []).length : 0;
    if (n !== ui.lastThreadCount) { ui.lastThreadCount = n; scrollThread(); }
  }

  var started = false;

  S.register('cleaning', {
    render: render,
    action: action,
    input: input,
    afterRender: afterRender,
    start: function () {
      if (!signedIn()) return;
      authPaused = false;
      resetIfUserChanged();
      if (!started) {
        started = true;
        dropUnsendable().then(flush);
      } else {
        flush();
      }
      ensureAttendance();
      ensureJobs('today');
      ensurePlaces();
      refreshScreen();
    },
    beforeSignOut: function () {
      if (player.audio) player.audio.pause();
      ui.newJob = null;
      ui.draft = '';
      return Promise.resolve();
    },
    onRefused: function () { authPaused = true; },
    attendanceBar: attendanceBar,
    tileStatus: function (kind) {
      var counts = countsOf(cacheGet('jobs:today'), kind);
      return counts ? T.jobs.tileToday(counts.today || 0) : null;
    },
  });

  // Keep what is on screen fresh while it is open.
  setInterval(function () { if (!document.hidden && signedIn()) refreshScreen(); }, 60000);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden || !signedIn()) return;
    flush();
    refreshScreen();
  });
  window.addEventListener('online', function () { if (signedIn()) { offlineStreak = 0; flush(); } });
})();
