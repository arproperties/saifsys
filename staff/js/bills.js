/**
 * Staff app — Bills. A bill (or money) handed from person to person, over
 * api/mobile/bills (rules: includes/staff_bills.php):
 *
 *   New bill   date received (today), amount, attachment, notes, given to
 *              whom on which day (today). They get a notification.
 *   Waiting    the person given it accepts it, or rejects it with a reason
 *              (it goes back to whoever gave it, who is told why).
 *   With you   give it to the next person, or keep it (that ends it).
 *
 * Every hand-over shows in the bill's history, here and in Operations → Staff Bills.
 * Notifications use the same web push as the check-out reminder; the Bills
 * screen offers to turn them on for this phone.
 */
(function () {
  'use strict';

  var S = window.Staff;
  var esc = S.esc;
  var icon = S.icon;
  var button = S.button;
  var API_BASE = String(S.CFG.billsBase || '../api/mobile/bills').replace(/\/+$/, '');

  // -------------------------------------------------------------------------
  // Words
  // -------------------------------------------------------------------------

  var T = {
    title: 'Bills',
    greeting: function (name) { return 'Hello, ' + name; },
    newBill: 'New bill',
    waitingTitle: 'Waiting for you',
    withTitle: 'With you',
    recentTitle: 'Sent and finished',
    none: 'No bills yet.',
    more: 'Show older',
    retry: 'Try again',
    accept: 'Accept',
    reject: 'Reject',
    keep: 'Keep it',
    giveNext: 'Give to next',
    from: function (name, day) { return 'From ' + name + ' · ' + day; },
    returned: function (name) { return 'Rejected by ' + name; },
    sel: {
      giveTo: 'Give to',
      selectAll: 'Select all',
      clear: 'Clear',
      tickFirst: 'Tick the bills you want to give first.',
      accept: function (n) { return 'Accept (' + n + ')'; },
      reject: function (n) { return 'Reject (' + n + ')'; },
      accepted: function (n) { return n === 1 ? 'Accepted. It is with you now.' : n + ' bills accepted. They are with you now.'; },
      rejected: function (n) { return n === 1 ? 'Rejected. It went back.' : n + ' bills rejected. They went back.'; },
      given: function (n, name) { return (n === 1 ? 'Given to ' : n + ' bills given to ') + name + '. They have been told.'; },
      failed: function (n) { return n === 1 ? '1 bill could not be done: ' : n + ' bills could not be done: '; },
      giveTitle: function (n) { return n === 1 ? 'Give or keep this bill' : 'Give or keep ' + n + ' bills'; },
      decideTitle: function (n) { return n === 1 ? 'Keep, give or reject this bill' : 'Keep, give or reject ' + n + ' bills'; },
      decideHint: 'Keep or Give accepts them first. Reject sends them back to whoever gave them. Back does nothing.',
      acceptedGiven: function (n, name) { return (n === 1 ? 'Accepted and given to ' : n + ' bills accepted and given to ') + name + '. They have been told.'; },
      acceptedKept: function (n) { return n === 1 ? 'Accepted and kept. This bill is finished.' : n + ' bills accepted and kept. They are finished.'; },
      keepN: function () { return 'Keep'; },
      keepSheet: function (n) {
        return n === 1
          ? { title: 'Keep this bill?', body: 'I accept this bill. It stays with me and is finished; it cannot be given to anyone after this.', confirm: 'I accept this bill' }
          : { title: 'Keep these ' + n + ' bills?', body: 'I accept these bills. They stay with me and are finished; they cannot be given to anyone after this.', confirm: 'I accept these bills' };
      },
      keptN: function (n) { return n === 1 ? 'Kept. This bill is finished.' : n + ' bills kept. They are finished.'; },
      // The sentence under "Given to"; the numbers and the name are drawn big and bold.
      giving: { a: 'You are giving ', b: ', ', c: ' in total to ', bills: function (n) { return n === 1 ? '1 bill' : n + ' bills'; } },
      tick: 'Tick',
    },
    status: {
      waiting: function (b, me) { return b.holder.user_id === me ? 'Waiting for you' : 'Waiting for ' + b.holder.name; },
      with: function (b, me) { return b.holder.user_id === me ? 'With you' : 'With ' + b.holder.name; },
      kept: function (b, me) { return b.holder.user_id === me ? 'Kept by you' : 'Kept by ' + b.holder.name; },
    },
    form: {
      back: 'Back',
      title: 'New bill',
      received: 'Date received',
      amount: 'Amount (AED)',
      attachment: 'Attachment',
      camera: 'Camera',
      gallery: 'Photo or PDF',
      notes: 'Notes',
      notesPlaceholder: 'What is it for? (optional)',
      givenTo: 'Given to',
      choose: 'Choose a person',
      onDate: 'On date',
      save: 'Save',
      saving: 'Saving…',
      saved: 'Saved. It is with you: give it from the list when you are ready.',
      billN: function (n) { return 'Bill ' + n; },
      addAnother: 'Add another bill',
      remove: 'Remove this bill',
      saveN: function (n) { return n === 1 ? 'Save' : 'Save (' + n + ' bills)'; },
      savingN: function (i, n) { return n === 1 ? 'Saving…' : 'Saving ' + i + ' of ' + n + '…'; },
      savedN: function (n) { return n === 1 ? 'Saved. It is with you: give it from the list when you are ready.' : n + ' bills saved. They are with you: tick them and give them when you are ready.'; },
      partly: function (done, left) { return done + ' saved. ' + left + ' not saved yet, still here: try again.'; },
      total: 'Total',
      fix: function (n) { return 'Bill ' + n + ': '; },
    },
    give: {
      title: 'Give to the next person',
      save: 'Give',
      done: function (name) { return 'Given to ' + name + '. They have been told.'; },
    },
    pick: { title: 'Given to', search: 'Search a name', none: 'Nobody else can take bills yet. They need a staff app PIN from HR.', noMatch: 'No name matches.' },
    note: {
      button: 'Add note',
      sheet: { title: 'Add a note', body: 'It shows in the bill\'s history. The bill itself does not change.', placeholder: 'Note', confirm: 'Add note', cancel: 'Back' },
      added: 'Note added.',
      need: 'Write the note.',
    },
    delReq: {
      button: 'Request delete',
      waiting: 'Delete requested — waiting for the office.',
      sheet: { title: 'Ask the office to delete this bill?', body: 'Only the office can delete it. Write why.', placeholder: 'Reason', confirm: 'Send request', cancel: 'Back' },
      sent: 'Request sent. The office will decide.',
      need: 'Write why it should be deleted.',
      short: 'Delete requested',
      holdTitle: 'Delete requested — waiting for the office',
    },
    rejectSheet: { title: 'Reject this bill?', body: 'It goes back to the person who gave it. Write why.', placeholder: 'Reason', confirm: 'Reject', cancel: 'Back' },
    keepSheet: { title: 'Keep this bill?', body: 'I accept this bill. It stays with me and is finished; it cannot be given to anyone after this.', confirm: 'I accept this bill', cancel: 'Back' },
    edit: {
      button: 'Edit',
      title: 'Change this bill',
      addFiles: 'Add attachment',
      reason: 'Why are you changing it?',
      reasonPlaceholder: 'Reason (needed)',
      save: 'Save changes',
      done: 'Changed. It shows in the history.',
      nothing: 'Nothing was changed.',
      amountLocked: 'The amount cannot be changed after the bill has been accepted.',
    },
    accepted: 'Accepted. It is with you now.',
    rejected: 'Rejected. It went back.',
    kept: 'Kept. This bill is finished.',
    detail: {
      back: 'Back',
      received: 'Received',
      started: 'Started by',
      notes: 'Notes',
      files: 'Attachment',
      history: 'History',
      startedLine: function (name) { return name + ' started it'; },
      givenLine: function (from, to) { return from + ' gave it to ' + to; },
      waitingLine: function (to) { return 'Waiting for ' + to + ' to accept'; },
      acceptedLine: function (to) { return to + ' accepted'; },
      rejectedLine: function (to) { return to + ' rejected'; },
      keptLine: function (name) { return name + ' kept it'; },
      editedLine: function (name) { return name + ' changed it'; },
      delRequestedLine: function (name) { return name + ' asked the office to delete it'; },
      noteLine: function (name) { return name + ' added a note'; },
      delDeclinedLine: function (name) { return 'The office (' + name + ') did not delete it'; },
      discardedLine: function (name) { return 'Deleted by the office (' + name + ')'; },
      reason: 'Reason',
      on: 'on',
    },
    need: {
      amount: 'Write the amount.',
      person: 'Choose who you are giving it to.',
      reason: 'Write why you are rejecting it.',
      editReason: 'Write why you are changing it.',
      received: 'Choose the date received.',
      maxFiles: 'That is enough attachments.',
      notFile: 'Only photos (JPG, PNG, WEBP) or a PDF.',
    },
    alerts: {
      title: 'Turn on bill alerts',
      body: 'Get a notification on this phone when someone gives you a bill.',
      button: 'Turn on',
      blocked: 'Notifications are blocked for this app. Allow them in the phone settings to hear about new bills.',
      ios: 'To get bill alerts on iPhone, add this app to the Home Screen and open it from there.',
      on: 'Bill alerts are on for this phone.',
      failed: 'Could not turn on alerts. Try again.',
    },
    genericBody: 'Please try again in a moment.',
  };

  Object.assign(S.ICON, {
    receipt: '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6"/><path d="M16 12H8"/><path d="M13 16H8"/>',
    file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    calendar: '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
  });

  // A few looks of our own, kept here so app.css stays as it is.
  (function () {
    var css = '.bill-tl{list-style:none;margin:0;padding:0}' +
      '.bill-tl li{position:relative;padding:0 0 14px 22px}' +
      '.bill-tl li:before{content:"";position:absolute;left:4px;top:6px;width:9px;height:9px;border-radius:50%;background:var(--navy-700,#1E3A5F)}' +
      '.bill-tl li:after{content:"";position:absolute;left:8px;top:17px;bottom:0;width:1px;background:var(--cream-200,#E5E0D5)}' +
      '.bill-tl li:last-child:after{display:none}' +
      '.bill-tl li.ok:before{background:#059669}.bill-tl li.no:before{background:#DC2626}.bill-tl li.wait:before{background:#D97706}' +
      '.bill-pick{display:flex;align-items:center;gap:10px;width:100%;text-align:left}' +
      '.bill-people{max-height:50vh;overflow:auto;margin-top:12px}' +
      '.bill-sel{display:flex;align-items:center;gap:10px}' +
      '.bill-tick{flex:none;width:30px;height:30px;border-radius:8px;border:2px solid var(--navy-700,#1E3A5F);display:grid;place-items:center;background:#fff;color:#fff}' +
      '.bill-tick.on{background:var(--navy-700,#1E3A5F)}' +
      '.bill-tick .icon{width:18px;height:18px;stroke-width:3}' +
      '.bill-sec{display:flex;align-items:baseline;justify-content:space-between;gap:8px}' +
      '.bill-sec .link{font-weight:600;color:var(--navy-700,#1E3A5F);text-decoration:underline;background:none;border:0;padding:4px 0}' +
      '.bill-give{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:999px;font-weight:700;background:var(--gold-500,#C9A15B);color:var(--text,#1F2937);white-space:nowrap}' +
      '.bill-give.off{opacity:.55}' +
      '.bill-fold{display:flex;align-items:center;justify-content:space-between;width:100%;padding:12px 4px;border-bottom:1px solid var(--cream-200,#E5E0D5);text-align:left}' +
      '.bill-fold-arrow{display:grid;place-items:center;width:36px;height:36px;border-radius:50%;background:var(--cream-200,#EFEAE0);transition:transform .15s}';
    var el = document.createElement('style');
    el.textContent = css;
    document.head.appendChild(el);
  })();

  var MAX_FILES = 6;

  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------

  var state = {
    open: null,        // bills waiting for me or with me
    recent: null,      // every bill I am part of, newest first
    more: false,
    loadError: null,
    loadingMore: false,
    detail: {},        // id -> bill
    detailError: null,
    people: null,
    busy: false,
    form: null,        // the new bill being typed: {ref, received_on, amount, notes, to, toName, given_on, files, error}
    give: null,        // {id, to, toName, given_on, error}
    pick: null,        // {target: 'form' | 'give', q}
    reject: null,      // {id, reason, error}
    edit: null,        // {id, received_on, amount, notes, files, reason, error}
    sel: { give: {}, answer: {} }, // ticked bills: give = with me (to give on), answer = waiting for me
    alerts: 'unknown', // unknown, unsupported, ios-browser, denied, off, on
    alertsBusy: false,
  };

  function signedIn() { return !!S.token('bills'); }
  function me() { var s = S.session(); return s ? Number(s.user.id) : 0; }

  function api(path, opts) {
    opts = opts || {};
    opts.token = S.token('bills');
    return S.request(API_BASE + path, opts);
  }

  // --- Money and days ------------------------------------------------------

  function money(n) {
    return 'AED ' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(ymd) {
    if (!ymd) return '';
    var p = String(ymd).slice(0, 10).split('-');
    return Number(p[2]) + ' ' + MONTHS[Number(p[1]) - 1] + ' ' + p[0];
  }
  function stamp(dt) {
    if (!dt) return '';
    var t = String(dt).slice(11, 16);
    return day(dt) + (t ? ', ' + t : '');
  }
  function today() {
    var d = new Date();
    return d.getFullYear() + '-' + S.pad(d.getMonth() + 1) + '-' + S.pad(d.getDate());
  }
  function num(v) {
    var s = String(v === null || v === undefined ? '' : v).replace(/,/g, '').trim();
    return s === '' || isNaN(Number(s)) ? NaN : Number(s);
  }

  // -------------------------------------------------------------------------
  // Talking to the server
  // -------------------------------------------------------------------------

  function load() {
    if (!signedIn()) return Promise.resolve();
    return api('/bills').then(function (r) {
      state.open = r.open;
      state.recent = r.recent;
      state.more = r.more;
      state.loadError = null;
      r.open.concat(r.recent).forEach(function (b) { state.detail[b.id] = b; });
    }, function (e) {
      state.loadError = e.message || T.genericBody;
    }).then(S.render);
  }

  function loadMore() {
    if (!state.recent || !state.recent.length || state.loadingMore) return;
    state.loadingMore = true;
    S.render();
    api('/bills?before=' + state.recent[state.recent.length - 1].id).then(function (r) {
      state.recent = state.recent.concat(r.recent);
      state.more = r.more;
      r.recent.forEach(function (b) { state.detail[b.id] = b; });
    }, function (e) { S.toast(e.message || T.genericBody); }).then(function () {
      state.loadingMore = false;
      S.render();
    });
  }

  function loadPeople() {
    if (state.people) return;
    api('/people').then(function (r) { state.people = r.people; S.render(); }, function () { state.people = []; S.render(); });
  }

  var loadingOne = {};
  function loadOne(id) {
    if (loadingOne[id]) return;
    loadingOne[id] = true;
    state.detailError = null;
    api('/bills/' + id).then(function (b) {
      delete loadingOne[id];
      remember(b);
    }, function (e) {
      delete loadingOne[id];
      state.detailError = e.message || T.genericBody;
      S.render();
    });
  }

  /** A bill as the server now has it: into the cache and both lists. */
  function remember(b) {
    state.detail[b.id] = b;
    var mine = b.holder.user_id === me() && b.status !== 'kept';
    if (state.open) {
      state.open = state.open.filter(function (x) { return x.id !== b.id; });
      if (mine) state.open.unshift(b);
    }
    if (state.recent) {
      var at = state.recent.findIndex(function (x) { return x.id === b.id; });
      if (at === -1) state.recent.unshift(b);
      else state.recent[at] = b;
    }
    S.render();
  }

  /** accept / keep / reject / give on one bill. */
  function act(id, what, body, done) {
    if (state.busy) return;
    state.busy = true;
    S.render();
    api('/bills/' + id + '/' + what, { method: 'POST', json: body || {} }).then(function (b) {
      state.busy = false;
      remember(b);
      if (done) done(b);
    }, function (e) {
      state.busy = false;
      S.toast(e.message || T.genericBody);
      // The bill may have moved on: show it as it is now.
      if (e.kind === 'conflict') loadOne(id);
      // Discarded by the office meanwhile: gone from every list.
      if (e.kind === 'not_found') {
        delete state.detail[id];
        if (state.open) state.open = state.open.filter(function (x) { return x.id !== id; });
        if (state.recent) state.recent = state.recent.filter(function (x) { return x.id !== id; });
        S.go({ app: 'bills' }, true);
      }
      S.render();
    });
  }

  /** accept / reject / give on several bills in one go; done(list of bills now). */
  function doMany(what, ids, body, done) {
    if (state.busy || !ids.length) return;
    state.busy = true;
    S.render();
    api('/bills/many', { method: 'POST', json: Object.assign({ action: what, ids: ids }, body || {}), timeout: 60000 }).then(function (r) {
      state.busy = false;
      r.bills.forEach(remember);
      clearSel();
      if (r.failed.length) S.toast(T.sel.failed(r.failed.length) + r.failed[0].message);
      if (done) done(r.bills);
      load();
    }, function (e) {
      state.busy = false;
      S.toast(e.message || T.genericBody);
      S.render();
    });
  }

  function clearSel() { state.sel = { give: {}, answer: {} }; }

  /**
   * Bills waiting for me: open the decision screen — Reject, Keep or Give.
   * Nothing happens until one is tapped; Keep and Give accept first.
   */
  function openDecide(ids) {
    if (!ids.length) return;
    state.give = null;
    loadPeople();
    S.go({ app: 'bills', view: 'give', ids: ids, answer: true });
  }

  /** For bills still waiting: accept them, then run next(accepted bills). */
  function acceptThen(g, next) {
    if (!g.answer) { next(g.ids.map(function (i) { return state.detail[i]; }).filter(Boolean)); return; }
    doMany('accept', g.ids, null, function (done) { if (done.length) next(done); });
  }

  function selIds(group) {
    return Object.keys(state.sel[group]).filter(function (k) { return state.sel[group][k]; }).map(Number);
  }

  /** What stops this bill from being saved, or null. */
  function entryProblem(en) {
    if (!en.received_on) return T.need.received;
    if (!(num(en.amount) > 0)) return T.need.amount;
    return null;
  }

  /**
   * Save every bill on the screen, one after the other. All are checked first:
   * nothing is sent while one still has a problem. If the signal drops part way,
   * the saved ones leave the screen and the rest stay, to try again (each keeps
   * its own ref, so trying again never saves one twice).
   */
  function saveNew() {
    var f = state.form;
    if (!f || state.busy) return;
    for (var i = 0; i < f.entries.length; i++) {
      var problem = entryProblem(f.entries[i]);
      f.entries[i].error = problem;
      if (problem) {
        f.error = f.entries.length > 1 ? T.form.fix(i + 1) + problem : problem;
        S.render();
        var at = document.querySelector('[data-key="entry' + i + '"]');
        if (at && at.scrollIntoView) at.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
      }
    }
    var total = f.entries.length;
    var saved = 0;
    state.busy = true;
    f.error = null;
    var next = function () {
      if (!state.form || !state.form.entries.length) {
        state.busy = false;
        f.saving = 0;
        dropForm();
        S.toast(T.form.savedN(saved));
        S.go({ app: 'bills' }, true);
        load();
        return;
      }
      var en = state.form.entries[0];
      f.saving = saved + 1;
      S.render();
      var form = new FormData();
      form.append('data', JSON.stringify({ received_on: en.received_on, amount: num(en.amount), notes: en.notes, client_ref: en.ref }));
      en.files.forEach(function (x) { form.append('files[]', x.blob, x.name); });
      api('/bills', { method: 'POST', form: form, timeout: 90000 }).then(function (b) {
        saved++;
        remember(b);
        dropEntryFiles(en);
        state.form.entries.shift();
        next();
      }, function (e) {
        state.busy = false;
        f.saving = 0;
        en.error = e.message || T.genericBody;
        f.error = saved ? T.form.partly(saved, state.form.entries.length) : en.error;
        if (saved) load();
        S.render();
      });
    };
    f.total = total;
    next();
  }

  /** One bill on the New bill screen. Its ref makes a resend save it once. */
  function newEntry() {
    return { ref: S.uid(), received_on: today(), amount: '', notes: '', files: [], error: null };
  }

  function startForm() {
    dropForm();
    state.form = { entries: [newEntry()], error: null, saving: 0 };
    S.go({ app: 'bills', view: 'new' });
  }

  function dropEntryFiles(en) { en.files.forEach(function (x) { if (x.url) URL.revokeObjectURL(x.url); }); }

  function dropForm() {
    if (state.form) state.form.entries.forEach(dropEntryFiles);
    state.form = null;
  }

  /** Where new attachments go: the bill being changed, or bill number `entry` on the New bill screen. */
  function filesTarget(entry) {
    if (S.state.route.view === 'edit') return state.edit;
    return state.form ? state.form.entries[Number(entry) || 0] || null : null;
  }

  function addFiles(fromCamera, entry) {
    var f = filesTarget(entry);
    if (!f) return;
    if (f.files.length >= MAX_FILES) { S.toast(T.need.maxFiles); return; }
    S.pickFiles(fromCamera ? 'image/*' : 'image/*,application/pdf', fromCamera, !fromCamera).then(function (files) {
      files = files.slice(0, MAX_FILES - f.files.length);
      return Promise.all(files.map(function (file) {
        if (file.type === 'application/pdf' || S.extOf(file.name) === 'pdf') return { blob: file, name: 'bill.pdf', pdf: true };
        return S.preparePhoto(file, T.need.notFile).then(function (p) { return { blob: p.blob, name: p.name, url: URL.createObjectURL(p.blob) }; });
      }));
    }).then(function (list) {
      if (filesTarget(entry) !== f) return;
      f.files = f.files.concat(list);
      f.error = null;
      S.render();
    }, function (e) { S.toast(e.message || T.need.notFile); });
  }

  function fileUrl(bid, f) {
    return S.media('bill-' + bid + '-' + f.id, function () {
      return api('/bills/' + bid + '/files/' + f.id, { raw: true, timeout: 60000 });
    });
  }

  function openFile(bid, fid) {
    var b = state.detail[bid];
    var f = b && b.files.filter(function (x) { return x.id === fid; })[0];
    if (!f) return;
    var url = fileUrl(bid, f);
    if (!url) { S.toast('Loading…'); return; }
    if (f.mime === 'application/pdf') { window.open(url, '_blank'); return; }
    S.openViewer('<img src="' + esc(url) + '" alt="">');
  }

  // -------------------------------------------------------------------------
  // Bill alerts (web push). The same subscription table as the check-out
  // reminder: a phone signed up in either place gets both.
  // -------------------------------------------------------------------------

  function alertsSupported() { return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window; }

  function b64uToBytes(s) {
    var b64 = (s + '===='.slice(0, (4 - s.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(b64), out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  /** Work out what the card shows; when allowed already, quietly tell the server this phone is this person's. */
  function alertsCheck() {
    if (!signedIn()) return;
    if (S.isIOS && !S.isStandalone()) { state.alerts = 'ios-browser'; return; }
    if (!alertsSupported()) { state.alerts = 'unsupported'; return; }
    if (Notification.permission === 'denied') { state.alerts = 'denied'; return; }
    if (Notification.permission === 'granted') {
      alertsSubscribe().catch(function () { state.alerts = 'off'; }).then(S.render);
      return;
    }
    state.alerts = 'off';
  }

  /** Subscribe this phone (permission granted) and tell the server. */
  function alertsSubscribe() {
    return api('/push/key', { background: true }).then(function (cfg) {
      if (!cfg || cfg.available === false || !cfg.public_key) throw new Error(T.alerts.failed);
      return navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.getSubscription().then(function (old) {
          return old || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(cfg.public_key) });
        });
      });
    }).then(function (sub) {
      return api('/push/subscribe', { method: 'POST', json: sub.toJSON(), background: true });
    }).then(function () { state.alerts = 'on'; });
  }

  function alertsTurnOn() {
    if (state.alertsBusy) return;
    state.alertsBusy = true;
    S.render();
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') { state.alerts = perm === 'denied' ? 'denied' : 'off'; return null; }
      return alertsSubscribe().then(function () { S.toast(T.alerts.on); });
    }).catch(function () { S.toast(T.alerts.failed); }).then(function () {
      state.alertsBusy = false;
      S.render();
    });
  }

  /** On sign-out: this phone stops getting this person's bill alerts (it may be shared). */
  function alertsOff() {
    if (!alertsSupported() || !signedIn()) return Promise.resolve();
    return navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
      if (!sub) return null;
      return api('/push/unsubscribe', { method: 'POST', json: { endpoint: sub.endpoint }, background: true }).catch(function () {});
    }).catch(function () {}).then(function () { state.alerts = 'unknown'; });
  }

  function alertsCard() {
    var s = state.alerts;
    if (s === 'on' || s === 'unknown' || s === 'unsupported') return '';
    var head = '<div class="row">' + icon(s === 'denied' ? 'bell-off' : 'bell') + '<div class="grow"><div class="h3">' + esc(T.alerts.title) + '</div>';
    if (s === 'denied') return '<div class="card warn">' + head + '<div class="small mt-1">' + esc(T.alerts.blocked) + '</div></div></div></div>';
    if (s === 'ios-browser') return '<div class="card info">' + head + '<div class="small secondary mt-1">' + esc(T.alerts.ios) + '</div></div></div></div>';
    return '<div class="card info">' + head + '<div class="small secondary mt-1">' + esc(T.alerts.body) + '</div></div></div>' +
      '<div class="mt-3">' + button(T.alerts.button, 'bills:alerts-on', { variant: 'secondary', icon: 'bell', loading: state.alertsBusy }) + '</div></div>';
  }

  // -------------------------------------------------------------------------
  // Screens
  // -------------------------------------------------------------------------

  function statusPill(b) {
    var color = b.status === 'waiting' ? 'background:#FEF3C7;color:#92400E'
      : b.status === 'kept' ? 'background:#D1FAE5;color:#065F46'
      : 'background:var(--cream-200);color:var(--navy-700)';
    return '<span class="pill" style="' + color + '">' + esc(T.status[b.status](b, me())) + '</span>';
  }

  function lastMove(b) { return b.moves.length ? b.moves[b.moves.length - 1] : null; }

  function billCard(b, key) {
    var sub = b.returned ? T.returned(lastMove(b).to.name) : day(b.received_on) + ' · ' + b.by.name;
    if (b.delete_request) sub = T.delReq.short + ' · ' + sub;
    return '<button type="button" class="card row" data-key="' + key + b.id + '" data-action="bills:open" data-id="' + b.id + '">' +
      '<span class="pur-icon">' + icon('receipt', 'lg') + '</span>' +
      '<span class="grow"><span class="h3" style="display:block">' + esc(money(b.amount)) + '</span>' +
      '<span class="secondary ellipsis" style="display:block">' + esc(sub) + '</span></span>' +
      statusPill(b) + '</button>';
  }

  /** The tick box in front of a bill the person can act on (group: give / answer). */
  function tickBox(group, b) {
    var on = !!state.sel[group][b.id];
    return '<button type="button" class="bill-tick' + (on ? ' on' : '') + '" data-action="bills:tick" data-group="' + group + '" data-id="' + b.id + '"' +
      ' role="checkbox" aria-checked="' + (on ? 'true' : 'false') + '" aria-label="' + esc(T.sel.tick + ' ' + money(b.amount)) + '">' + (on ? icon('tick') : '') + '</button>';
  }

  /** A bill I can act on: tick box, then the card (tap opens it). */
  function selCard(b, group, sub) {
    if (b.delete_request) sub = T.delReq.short + ' · ' + sub;
    return '<div class="card bill-sel" data-key="' + group + b.id + '">' + tickBox(group, b) +
      '<button type="button" class="bill-pick grow" data-action="bills:open" data-id="' + b.id + '">' +
      '<span class="grow"><span class="h3" style="display:block">' + esc(money(b.amount)) + '</span>' +
      '<span class="secondary ellipsis" style="display:block">' + esc(sub) + '</span>' +
      (b.notes ? '<span class="small muted ellipsis" style="display:block">' + esc(b.notes) + '</span>' : '') + '</span>' +
      statusPill(b) + '</button></div>';
  }

  function waitingCard(b) {
    var m = lastMove(b);
    return selCard(b, 'answer', T.from(m ? m.from.name : b.by.name, day(m ? m.given_on : b.received_on)));
  }

  function withCard(b) {
    return selCard(b, 'give', b.returned ? T.returned(lastMove(b).to.name) : day(b.received_on) + ' · ' + b.by.name);
  }

  function sectionHead(title, group, list, first) {
    var all = list.length && list.every(function (b) { return state.sel[group][b.id]; });
    return '<div class="bill-sec ' + (first ? 'mt-3' : 'mt-4') + '"><h2 class="h3 section-title" style="margin:0">' + esc(title) + '</h2>' +
      (list.length > 1 ? '<button type="button" class="link" data-action="bills:tick-all" data-group="' + group + '">' + esc(all ? T.sel.clear : T.sel.selectAll) + '</button>' : '') + '</div>';
  }

  function renderHome() {
    var s = S.session();
    var body = S.installCard() + alertsCard();

    if (state.open === null && !state.loadError) {
      body += '<div class="skel mt-3" style="height:120px"></div><div class="skel" style="height:76px"></div>';
    }
    if (state.loadError) {
      body += '<div class="card mt-3"><div class="body-lg">' + esc(state.loadError) + '</div><div class="mt-3">' +
        button(T.retry, 'bills:reload', { variant: 'secondary' }) + '</div></div>';
    }
    var waiting = [], withMe = [];
    if (state.open) {
      waiting = state.open.filter(function (b) { return b.status === 'waiting' && b.can_answer && !b.on_hold; });
      withMe = state.open.filter(function (b) { return b.status === 'with' && b.can_pass; });
      var onHold = state.open.filter(function (b) { return b.on_hold; });
      pruneSel('answer', waiting);
      pruneSel('give', withMe);
      if (waiting.length) {
        body += sectionHead(T.waitingTitle + ' (' + waiting.length + ')', 'answer', waiting, true);
        waiting.forEach(function (b) { body += waitingCard(b); });
      }
      if (withMe.length) {
        body += sectionHead(T.withTitle + ' (' + withMe.length + ')', 'give', withMe, !waiting.length);
        withMe.forEach(function (b) { body += withCard(b); });
      }
      if (onHold.length) {
        body += '<h2 class="h3 section-title mt-4">' + esc(T.delReq.holdTitle) + ' (' + onHold.length + ')</h2>';
        onHold.forEach(function (b) { body += billCard(b, 'h'); });
      }
      var openIds = state.open.map(function (b) { return b.id; });
      var others = (state.recent || []).filter(function (b) { return openIds.indexOf(b.id) === -1; });
      if (!others.length && !state.open.length) {
        body += '<div class="card mt-3"><div class="body-lg secondary">' + esc(T.none) + '</div></div>';
      } else if (others.length) {
        // Folded by default: it is a long list. Tap the heading or the arrow to open it.
        var openR = !!state.recentOpen;
        body += '<button type="button" class="bill-fold mt-4" data-key="fold" data-action="bills:fold" aria-expanded="' + (openR ? 'true' : 'false') + '">' +
          '<span class="h3">' + esc(T.recentTitle) + ' (' + others.length + (state.more ? '+' : '') + ')</span>' +
          '<span class="bill-fold-arrow" style="transform:rotate(' + (openR ? '-90' : '90') + 'deg)">' + icon('chevron') + '</span></button>';
        if (openR) {
          others.forEach(function (b) { body += billCard(b, 'r'); });
          if (state.more) body += '<div class="mt-3">' + button(T.more, 'bills:more', { variant: 'secondary', loading: state.loadingMore }) + '</div>';
        }
      }
    }

    var giveN = selIds('give').length;
    var answerN = selIds('answer').length;
    var giveBtn = withMe.length
      ? '<button type="button" class="bill-give' + (giveN ? '' : ' off') + '" data-action="bills:give-selected">' + icon('send') + esc(T.sel.giveTo + (giveN ? ' (' + giveN + ')' : '')) + '</button>'
      : '';
    var footer = answerN
      ? '<div class="action-row">' + button(T.sel.reject(answerN), 'bills:reject-selected', { variant: 'secondary', style: 'flex:1', disabled: state.busy }) +
        button(T.sel.accept(answerN), 'bills:accept-selected', { style: 'flex:1', loading: state.busy }) + '</div>'
      : button(T.newBill, 'bills:new', { icon: 'plus' });
    var choice = S.apps().length > 1;
    var header = '<header class="header">' +
      (choice ? '<button type="button" class="icon-btn" data-action="core:home" aria-label="' + esc(S.T.home.back) + '">' + icon('home') + '</button>' : '') +
      '<div class="grow"><h1 class="h2">' + esc(T.title) + '</h1><div class="secondary">' + esc(T.greeting(s ? String(s.user.name).trim().split(/\s+/)[0] : '')) + '</div></div>' +
      giveBtn + S.headerBell() +
      '<button type="button" class="icon-btn" data-action="core:settings" aria-label="' + esc(S.T.home.settings) + '">' + icon('settings') + '</button></header>';
    return '<main class="screen">' + header +
      '<div class="screen-body">' + body + '</div>' +
      '<div class="footer">' + footer + '</div>' +
      '</main>';
  }

  /** Forget ticks on bills no longer in that list (moved on, done elsewhere). */
  function pruneSel(group, list) {
    var ids = list.map(function (b) { return String(b.id); });
    Object.keys(state.sel[group]).forEach(function (k) { if (ids.indexOf(k) === -1) delete state.sel[group][k]; });
  }

  /** The "Given to" box: shows the chosen name, opens the list of people. */
  function personField(target, name) {
    return '<button type="button" class="field bill-pick mt-1" data-action="bills:pick" data-target="' + target + '">' +
      icon('user') + '<span class="grow' + (name ? '' : ' muted') + '">' + esc(name || T.form.choose) + '</span>' + icon('chevron') + '</button>';
  }

  function label(text, forId) {
    return '<label class="small secondary mt-3" style="display:block"' + (forId ? ' for="' + forId + '"' : '') + '>' + esc(text) + '</label>';
  }

  /** Camera / Photo-or-PDF buttons and the picked files, for a new bill or a change. */
  function attachTools(files, entry) {
    var en = entry === undefined ? '' : ' data-entry="' + entry + '"';
    var thumbs = files.map(function (x, i) {
      return '<div class="qthumb" data-key="nf' + (entry || 0) + '-' + i + '">' + (x.pdf ? '<span class="thumb" style="display:grid;place-items:center;width:88px;height:88px">' + icon('file', 'lg') + '</span>' : '<img src="' + esc(x.url) + '" alt="">') +
        '<button type="button" class="remove" data-action="bills:file-del"' + en + ' data-i="' + i + '" aria-label="Delete">' + icon('close') + '</button></div>';
    }).join('');
    return '<div class="qtools mt-1">' +
      '<button type="button" class="qtool" data-action="bills:file-add"' + en + ' data-camera="1">' + icon('camera', 'lg') + '<span>' + esc(T.form.camera) + '</span></button>' +
      '<button type="button" class="qtool" data-action="bills:file-add"' + en + '>' + icon('gallery', 'lg') + '<span>' + esc(T.form.gallery) + '</span></button>' +
      '</div>' + (thumbs ? '<div class="qthumbs">' + thumbs + '</div>' : '');
  }

  function renderForm() {
    var f = state.form;
    if (!f) { S.go({ app: 'bills' }, true); return renderHome(); }
    var many = f.entries.length > 1;
    var body = '<h1 class="h2 mt-2">' + esc(T.form.title) + '</h1>';
    f.entries.forEach(function (en, i) {
      body += '<div class="card mt-3" data-key="entry' + i + '"' + (en.error ? ' style="border:2px solid var(--danger,#DC2626)"' : '') + '>' +
        (many ? '<div class="row between"><span class="h3">' + esc(T.form.billN(i + 1)) + '</span>' +
          '<button type="button" class="icon-btn" data-action="bills:entry-del" data-entry="' + i + '" aria-label="' + esc(T.form.remove) + '">' + icon('trash') + '</button></div>' : '') +
        label(T.form.received, 'bill-received-' + i) +
        '<input class="field mt-1" id="bill-received-' + i + '" type="date" max="' + today() + '" value="' + esc(en.received_on) + '">' +
        label(T.form.amount, 'bill-amount-' + i) +
        '<input class="field mt-1" id="bill-amount-' + i + '" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" value="' + esc(en.amount) + '">' +
        label(T.form.attachment) + attachTools(en.files, i) +
        label(T.form.notes, 'bill-notes-' + i) +
        '<textarea class="field mt-1" id="bill-notes-' + i + '" rows="2" maxlength="500" placeholder="' + esc(T.form.notesPlaceholder) + '">' + esc(en.notes) + '</textarea>' +
        (en.error ? '<div class="small mt-2" style="color:var(--danger,#DC2626)">' + esc(en.error) + '</div>' : '') +
        '</div>';
    });
    body += '<div class="mt-3">' + button(T.form.addAnother, 'bills:entry-add', { variant: 'secondary', icon: 'plus', disabled: state.busy }) + '</div>';
    if (many) {
      var sum = f.entries.reduce(function (t, en) { var a = num(en.amount); return t + (a > 0 ? a : 0); }, 0);
      body += '<div class="trip-card mt-3 row between"><span class="label gold">' + esc(T.form.total) + '</span><span class="h2">' + esc(money(sum)) + '</span></div>';
    }
    var label2 = state.busy ? T.form.savingN(f.saving || 1, f.total || f.entries.length) : T.form.saveN(f.entries.length);
    return '<main class="screen">' + S.backBar('bills:back', T.form.back) +
      '<div class="screen-body">' + body + (f.error ? '<div class="card alert">' + esc(f.error) + '</div>' : '') + '</div>' +
      '<div class="footer">' + button(label2, 'bills:save', { loading: state.busy }) + '</div></main>';
  }

  function renderGive(route) {
    var ids = route.ids || [route.id];
    var key = ids.join(',');
    if (!state.give || state.give.key !== key) state.give = { key: key, ids: ids, answer: !!route.answer, to: 0, toName: '', given_on: today(), error: null };
    var g = state.give;
    var bills = ids.map(function (i) { return state.detail[i]; }).filter(Boolean);
    var total = bills.reduce(function (t, b) { return t + Number(b.amount); }, 0);
    var body = '<h1 class="h2 mt-2">' + esc(g.answer ? T.sel.decideTitle(ids.length) : T.sel.giveTitle(ids.length)) + '</h1>' +
      (g.answer ? '<div class="small secondary mt-1">' + esc(T.sel.decideHint) + '</div>' : '') +
      '<div class="card mt-3"><div class="small secondary">' + esc(T.form.givenTo) + '</div>' + personField('give', g.toName) + '</div>' +
      '<p class="body-lg mt-3" style="line-height:1.6">' + esc(T.sel.giving.a) +
      '<strong style="font-size:1.35em">' + esc(T.sel.giving.bills(ids.length)) + '</strong>' + esc(T.sel.giving.b) +
      '<strong style="font-size:1.35em">' + esc(money(total)) + '</strong>' + esc(T.sel.giving.c) +
      (g.toName ? '<strong style="font-size:1.35em">' + esc(g.toName) + '</strong>.' : '<span class="muted">………………………</span>') + '</p>';
    return '<main class="screen">' + S.backBar('bills:back', T.form.back) +
      '<div class="screen-body">' + body + (g.error ? '<div class="card alert">' + esc(g.error) + '</div>' : '') + '</div>' +
      '<div class="footer"><div class="action-row">' +
      (g.answer ? button(T.reject, 'bills:reject-many', { variant: 'plain', style: 'flex:2;color:var(--danger,#DC2626)', disabled: state.busy }) : '') +
      button(T.sel.keepN(ids.length), 'bills:keep-many', { variant: 'secondary', style: 'flex:2', disabled: state.busy }) +
      button(state.busy ? T.form.saving : T.give.save, 'bills:give-save', { style: 'flex:3', loading: state.busy }) +
      '</div></div></main>';
  }

  function renderEdit(route) {
    var e = state.edit;
    if (!e || e.id !== route.id) { S.go({ app: 'bills', view: 'detail', id: route.id }, true); return renderDetail(route); }
    var body = '<h1 class="h2 mt-2">' + esc(T.edit.title) + ' #' + e.id + '</h1>' +
      label(T.form.received, 'bill-e-received') +
      '<input class="field mt-1" id="bill-e-received" type="date" max="' + today() + '" value="' + esc(e.received_on) + '">' +
      label(T.form.amount, 'bill-e-amount') +
      (e.amountLocked
        ? '<input class="field mt-1" id="bill-e-amount" type="text" value="' + esc(money(num(e.amount))) + '" disabled>' +
          '<div class="small muted mt-1">' + esc(T.edit.amountLocked) + '</div>'
        : '<input class="field mt-1" id="bill-e-amount" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" value="' + esc(e.amount) + '">') +
      label(T.form.notes, 'bill-e-notes') +
      '<textarea class="field mt-1" id="bill-e-notes" rows="2" maxlength="500" placeholder="' + esc(T.form.notesPlaceholder) + '">' + esc(e.notes) + '</textarea>' +
      label(T.edit.addFiles) + attachTools(e.files) +
      '<div class="card mt-4">' + label(T.edit.reason, 'bill-e-reason') +
      '<textarea class="field mt-1" id="bill-e-reason" rows="2" maxlength="255" placeholder="' + esc(T.edit.reasonPlaceholder) + '">' + esc(e.reason) + '</textarea></div>';
    return '<main class="screen">' + S.backBar('bills:back', T.form.back) +
      '<div class="screen-body">' + body + (e.error ? '<div class="card alert">' + esc(e.error) + '</div>' : '') + '</div>' +
      '<div class="footer">' + button(state.busy ? T.form.saving : T.edit.save, 'bills:edit-save', { loading: state.busy }) + '</div></main>';
  }

  function startEdit(id) {
    var b = state.detail[id];
    if (!b) return;
    dropEdit();
    state.edit = { id: id, received_on: b.received_on, amount: String(b.amount), amountLocked: !!b.amount_locked, notes: b.notes || '', files: [], reason: '', error: null };
    S.go({ app: 'bills', view: 'edit', id: id });
  }

  function dropEdit() {
    if (state.edit) state.edit.files.forEach(function (x) { if (x.url) URL.revokeObjectURL(x.url); });
    state.edit = null;
  }

  function saveEdit() {
    var e = state.edit;
    var b = e && state.detail[e.id];
    if (!e || !b || state.busy) return;
    var amount = num(e.amount);
    if (!String(e.reason).trim()) { e.error = T.need.editReason; S.render(); return; }
    if (!(amount > 0)) { e.error = T.need.amount; S.render(); return; }
    if (!e.received_on) { e.error = T.need.received; S.render(); return; }
    var data = { reason: e.reason };
    if (e.received_on !== b.received_on) data.received_on = e.received_on;
    if (!e.amountLocked && Math.abs(amount - b.amount) >= 0.005) data.amount = amount;
    if (String(e.notes).trim() !== String(b.notes || '')) data.notes = e.notes;
    if (!data.received_on && data.amount === undefined && data.notes === undefined && !e.files.length) { e.error = T.edit.nothing; S.render(); return; }
    var form = new FormData();
    form.append('data', JSON.stringify(data));
    e.files.forEach(function (x) { form.append('files[]', x.blob, x.name); });
    state.busy = true;
    e.error = null;
    S.render();
    api('/bills/' + e.id + '/edit', { method: 'POST', form: form, timeout: 90000 }).then(function (nb) {
      state.busy = false;
      dropEdit();
      remember(nb);
      S.toast(T.edit.done);
      S.go({ app: 'bills', view: 'detail', id: nb.id }, true);
    }, function (err) {
      state.busy = false;
      if (state.edit) state.edit.error = err.message || T.genericBody;
      S.render();
    });
  }

  function historyHtml(b) {
    var when = function (ev) { return ev.at ? '<div class="small muted">' + esc(stamp(ev.at)) + '</div>' : ''; };
    var items = (b.events || []).map(function (ev) {
      switch (ev.kind) {
        case 'started': return '<li><div class="h3">' + esc(T.detail.startedLine(ev.by)) + '</div>' + when(ev) + '</li>';
        case 'given': return '<li><div>' + esc(T.detail.givenLine(ev.by, ev.to)) + '</div><div class="small muted">' + esc(T.detail.on + ' ' + day(ev.given_on)) + '</div></li>';
        case 'accepted': return '<li class="ok"><div>' + esc(T.detail.acceptedLine(ev.by)) + '</div>' + when(ev) + '</li>';
        case 'rejected': return '<li class="no"><div>' + esc(T.detail.rejectedLine(ev.by)) + '</div><div class="small">' + esc(T.detail.reason + ': ' + (ev.reason || '')) + '</div>' + when(ev) + '</li>';
        case 'edited': return '<li class="wait"><div>' + esc(T.detail.editedLine(ev.by)) + '</div>' +
          (ev.changes || []).map(function (c) {
            return '<div class="small">' + esc(c[0] + ': ' + (c[1] ? c[1] + ' \u2192 ' : '') + (c[2] || '(empty)')) + '</div>';
          }).join('') +
          '<div class="small">' + esc(T.detail.reason + ': ' + (ev.reason || '')) + '</div>' + when(ev) + '</li>';
        case 'kept': return '<li class="ok"><div class="h3">' + esc(T.detail.keptLine(ev.by)) + '</div>' + when(ev) + '</li>';
        case 'note': return '<li><div>' + esc(T.detail.noteLine(ev.by)) + '</div><div class="body-lg">' + esc(ev.reason || '') + '</div>' + when(ev) + '</li>';
        case 'delete_requested': return '<li class="no"><div>' + esc(T.detail.delRequestedLine(ev.by)) + '</div><div class="small">' + esc(T.detail.reason + ': ' + (ev.reason || '')) + '</div>' + when(ev) + '</li>';
        case 'delete_declined': return '<li class="ok"><div>' + esc(T.detail.delDeclinedLine(ev.by)) + '</div>' + (ev.reason ? '<div class="small">' + esc(T.detail.reason + ': ' + ev.reason) + '</div>' : '') + when(ev) + '</li>';
        case 'discarded': return '<li class="no"><div>' + esc(T.detail.discardedLine(ev.by)) + '</div>' + when(ev) + '</li>';
        case 'waiting': return '<li class="wait"><div>' + esc(T.detail.waitingLine(ev.by)) + '</div></li>';
      }
      return '';
    });
    return '<ul class="bill-tl">' + items.join('') + '</ul>';
  }

  function renderDetail(route) {
    var b = state.detail[route.id];
    var top = S.backBar('bills:back', T.detail.back);
    if (!b) {
      if (!state.detailError) loadOne(route.id);
      return '<main class="screen">' + top + '<div class="screen-body">' +
        (state.detailError ? '<div class="card"><div class="body-lg">' + esc(state.detailError) + '</div></div>' : '<div class="skel mt-3" style="height:160px"></div>') +
        '</div></main>';
    }
    var files = b.files.map(function (f) {
      var pdf = f.mime === 'application/pdf';
      var url = pdf ? null : fileUrl(b.id, f);
      return '<div class="qthumb" data-key="f' + f.id + '">' +
        '<button type="button" class="thumb" style="width:88px;height:88px" data-action="bills:view-file" data-id="' + b.id + '" data-file="' + f.id + '">' +
        (pdf ? icon('file', 'lg') : url ? '<img src="' + esc(url) + '" alt="">' : '<span class="spinner"></span>') + '</button></div>';
    }).join('');
    var body = '<div class="trip-card mt-2"><div class="label gold">' + esc(T.title) + ' #' + b.id + '</div>' +
      '<div class="h1">' + esc(money(b.amount)) + '</div>' +
      '<div class="mt-2">' + statusPill(b) + '</div></div>' +
      '<div class="card"><div class="kv"><span class="secondary">' + esc(T.detail.received) + '</span><span>' + esc(day(b.received_on)) + '</span></div>' +
      '<div class="kv mt-2"><span class="secondary">' + esc(T.detail.started) + '</span><span>' + esc(b.by.name) + '</span></div>' +
      (b.notes ? '<div class="mt-2"><span class="secondary">' + esc(T.detail.notes) + '</span><div class="body-lg">' + esc(b.notes) + '</div></div>' : '') +
      (b.can_edit ? '<div class="mt-3">' + button(T.edit.button, 'bills:edit', { variant: 'secondary', icon: 'edit', data: 'data-id="' + b.id + '"', disabled: state.busy }) + '</div>' : '') +
      (!b.can_edit && b.can_note ? '<div class="mt-3">' + button(T.note.button, 'bills:note', { variant: 'secondary', icon: 'note', data: 'data-id="' + b.id + '"', disabled: state.busy }) + '</div>' : '') +
      '</div>' +
      (b.on_hold ? '<div class="card warn"><div class="row">' + icon('alert') + '<div class="grow"><div class="h3">' + esc(T.delReq.waiting) + '</div>' +
        '<div class="small mt-1">' + esc(b.delete_request.by.name + ': ' + b.delete_request.reason) + '</div></div></div></div>' : '') +
      (files ? '<h2 class="h3 section-title mt-4">' + esc(T.detail.files) + '</h2><div class="qthumbs">' + files + '</div>' : '') +
      '<h2 class="h3 section-title mt-4">' + esc(T.detail.history) + '</h2><div class="card">' + historyHtml(b) + '</div>' +
      (b.can_request_delete ? '<div class="mt-4">' + button(T.delReq.button, 'bills:del-request', { variant: 'plain', icon: 'trash', style: 'color:var(--danger,#DC2626)', data: 'data-id="' + b.id + '"', disabled: state.busy }) + '</div>' : '');

    var footer = '';
    var data = 'data-id="' + b.id + '"';
    if (b.on_hold) {
      footer = b.can_answer ? button(T.reject, 'bills:reject', { variant: 'secondary', data: data, disabled: state.busy }) : '';
    } else if (b.can_answer) {
      footer = '<div class="action-row">' + button(T.reject, 'bills:reject', { variant: 'secondary', style: 'flex:1', data: data, disabled: state.busy }) +
        button(T.accept, 'bills:accept', { style: 'flex:1', data: data, loading: state.busy }) + '</div>';
    } else if (b.can_pass) {
      footer = '<div class="action-row">' + button(T.keep, 'bills:keep', { variant: 'secondary', style: 'flex:1', data: data, disabled: state.busy }) +
        button(T.giveNext, 'bills:give', { style: 'flex:1', data: data, disabled: state.busy }) + '</div>';
    }
    return '<main class="screen">' + top + '<div class="screen-body">' + body + '</div>' +
      (footer ? '<div class="footer">' + footer + '</div>' : '') + '</main>';
  }

  function render(route) {
    if (route.view === 'new') return renderForm();
    if (route.view === 'detail') return renderDetail(route);
    if (route.view === 'give') return renderGive(route);
    if (route.view === 'edit') return renderEdit(route);
    if (state.open === null && !state.loadError && signedIn() && !load.pending) {
      load.pending = true;
      load().then(function () { load.pending = false; });
    }
    return renderHome();
  }

  // --- Sheets: choosing a person, rejecting --------------------------------------

  function pickSheet() {
    var p = state.pick;
    if (!p) return '';
    var list = state.people;
    var q = String(p.q || '').trim().toLowerCase();
    var rows;
    if (!list) rows = '<div class="skel" style="height:56px"></div>';
    else if (!list.length) rows = '<div class="small secondary">' + esc(T.pick.none) + '</div>';
    else {
      var shown = list.filter(function (x) { return !q || x.name.toLowerCase().indexOf(q) !== -1; });
      rows = shown.length ? shown.map(function (x) {
        return '<button type="button" class="card vehicle" data-key="p' + x.id + '" data-action="bills:pick-person" data-id="' + x.id + '">' +
          '<span class="veh-icon">' + icon('user') + '</span><span class="grow h3">' + esc(x.name) + '</span></button>';
      }).join('') : '<div class="small secondary">' + esc(T.pick.noMatch) + '</div>';
    }
    return (list && list.length > 6 ? '<input class="field" id="bill-pick-q" type="search" autocomplete="off" placeholder="' + esc(T.pick.search) + '" value="' + esc(p.q || '') + '">' : '') +
      '<div class="bill-people">' + rows + '</div>';
  }

  function openPick(target) {
    state.pick = { target: target, q: '' };
    loadPeople();
    S.sheet({ title: T.pick.title, html: pickSheet, cancel: T.form.back, tall: true, onCancel: function () { state.pick = null; } });
  }

  function noteSheet() {
    var r = state.note;
    return '<textarea class="field mt-2" id="bill-note-text" rows="3" maxlength="255" placeholder="' + esc(T.note.sheet.placeholder) + '">' + esc(r ? r.text : '') + '</textarea>' +
      (r && r.error ? '<div class="small mt-2" style="color:var(--danger)">' + esc(r.error) + '</div>' : '');
  }

  function openNote(id) {
    state.note = { id: id, text: '', error: null };
    S.sheet({
      title: T.note.sheet.title,
      body: T.note.sheet.body,
      html: noteSheet,
      actions: [{ label: T.note.sheet.confirm, action: 'bills:note-send', variant: '' }],
      cancel: T.note.sheet.cancel,
      onCancel: function () { state.note = null; },
    });
    setTimeout(function () { var el = document.getElementById('bill-note-text'); if (el) el.focus(); }, 80);
  }

  function sendNote() {
    var r = state.note;
    if (!r) return;
    if (!String(r.text).trim()) { r.error = T.note.need; S.render(); return; }
    S.closeSheet();
    state.note = null;
    act(r.id, 'note', { note: r.text }, function () { S.toast(T.note.added); });
  }

  function delReqSheet() {
    var r = state.delreq;
    return '<textarea class="field mt-2" id="bill-del-reason" rows="3" maxlength="255" placeholder="' + esc(T.delReq.sheet.placeholder) + '">' + esc(r ? r.reason : '') + '</textarea>' +
      (r && r.error ? '<div class="small mt-2" style="color:var(--danger)">' + esc(r.error) + '</div>' : '');
  }

  function openDelReq(id) {
    state.delreq = { id: id, reason: '', error: null };
    S.sheet({
      title: T.delReq.sheet.title,
      body: T.delReq.sheet.body,
      html: delReqSheet,
      actions: [{ label: T.delReq.sheet.confirm, action: 'bills:del-request-send', variant: 'danger' }],
      cancel: T.delReq.sheet.cancel,
      onCancel: function () { state.delreq = null; },
    });
    setTimeout(function () { var el = document.getElementById('bill-del-reason'); if (el) el.focus(); }, 80);
  }

  function sendDelReq() {
    var r = state.delreq;
    if (!r) return;
    if (!String(r.reason).trim()) { r.error = T.delReq.need; S.render(); return; }
    S.closeSheet();
    state.delreq = null;
    act(r.id, 'request-delete', { reason: r.reason }, function () { S.toast(T.delReq.sent); });
  }

  function rejectSheet() {
    var r = state.reject;
    return '<textarea class="field mt-2" id="bill-reason" rows="3" maxlength="255" placeholder="' + esc(T.rejectSheet.placeholder) + '">' + esc(r ? r.reason : '') + '</textarea>' +
      (r && r.error ? '<div class="small mt-2" style="color:var(--danger)">' + esc(r.error) + '</div>' : '');
  }

  function openReject(ids) {
    ids = [].concat(ids);
    state.reject = { ids: ids, reason: '', error: null };
    S.sheet({
      title: T.rejectSheet.title,
      body: T.rejectSheet.body,
      html: rejectSheet,
      actions: [{ label: T.rejectSheet.confirm, action: 'bills:reject-send', variant: 'danger' }],
      cancel: T.rejectSheet.cancel,
      onCancel: function () { state.reject = null; },
    });
    setTimeout(function () { var el = document.getElementById('bill-reason'); if (el) el.focus(); }, 80);
  }

  function sendReject() {
    var r = state.reject;
    if (!r) return;
    if (!String(r.reason).trim()) { r.error = T.need.reason; S.render(); return; }
    var ids = r.ids;
    S.closeSheet();
    state.reject = null;
    doMany('reject', ids, { reason: r.reason }, function (done) {
      if (done.length) S.toast(T.sel.rejected(done.length));
      if (S.state.route.view === 'detail' || S.state.route.view === 'give') { state.give = null; S.go({ app: 'bills' }, true); }
    });
  }

  // -------------------------------------------------------------------------
  // Taps and typing
  // -------------------------------------------------------------------------

  function action(name, el) {
    var id = Number(el.getAttribute('data-id'));
    switch (name) {
      case 'reload': state.loadError = null; state.open = null; S.render(); break;
      case 'bell': load(); S.go({ app: 'bills' }); break;
      case 'more': loadMore(); break;
      case 'fold': state.recentOpen = !state.recentOpen; S.render(); break;
      case 'new': startForm(); break;
      case 'open': state.detailError = null; S.go({ app: 'bills', view: 'detail', id: id }); break;
      case 'back': S.back(); break;
      case 'alerts-on': alertsTurnOn(); break;
      case 'file-add': addFiles(el.hasAttribute('data-camera'), el.getAttribute('data-entry')); break;
      case 'entry-add':
        if (!state.form || state.busy) break;
        state.form.entries.push(newEntry());
        state.form.error = null;
        S.render();
        setTimeout(function () {
          var n = state.form ? state.form.entries.length - 1 : 0;
          var card = document.querySelector('[data-key="entry' + n + '"]');
          if (card && card.scrollIntoView) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
          var amt = document.getElementById('bill-amount-' + n);
          if (amt) amt.focus();
        }, 60);
        break;
      case 'entry-del': {
        if (!state.form || state.busy || state.form.entries.length < 2) break;
        var gone2 = state.form.entries.splice(Number(el.getAttribute('data-entry')), 1)[0];
        if (gone2) dropEntryFiles(gone2);
        state.form.error = null;
        S.render();
        break;
      }
      case 'file-del': {
        var ft = filesTarget(el.getAttribute('data-entry'));
        var gone = ft ? ft.files.splice(Number(el.getAttribute('data-i')), 1)[0] : null;
        if (gone && gone.url) URL.revokeObjectURL(gone.url);
        S.render();
        break;
      }
      case 'view-file': openFile(id, Number(el.getAttribute('data-file'))); break;
      case 'pick': openPick(el.getAttribute('data-target')); break;
      case 'pick-person': {
        var person = (state.people || []).filter(function (x) { return x.id === id; })[0];
        var target = state.pick && state.pick.target === 'give' ? state.give : state.form;
        if (person && target) { target.to = person.id; target.toName = person.name; target.error = null; }
        state.pick = null;
        S.closeSheet();
        break;
      }
      case 'save': saveNew(); break;
      case 'edit': startEdit(id); break;
      case 'edit-save': saveEdit(); break;
      case 'accept': openDecide([id]); break;
      case 'reject': openReject(id); break;
      case 'tick': {
        var grp = el.getAttribute('data-group');
        if (state.sel[grp][id]) delete state.sel[grp][id]; else state.sel[grp][id] = true;
        S.render();
        break;
      }
      case 'tick-all': {
        var g2 = el.getAttribute('data-group');
        var list2 = (state.open || []).filter(function (b) { return g2 === 'give' ? (b.status === 'with' && b.can_pass) : (b.status === 'waiting' && b.can_answer); });
        var allOn = list2.every(function (b) { return state.sel[g2][b.id]; });
        state.sel[g2] = {};
        if (!allOn) list2.forEach(function (b) { state.sel[g2][b.id] = true; });
        S.render();
        break;
      }
      case 'accept-selected': {
        openDecide(selIds('answer'));
        break;
      }
      case 'reject-selected': if (selIds('answer').length) openReject(selIds('answer')); break;
      case 'give-selected': {
        var gi = selIds('give');
        if (!gi.length) { S.toast(T.sel.tickFirst); break; }
        state.give = null;
        loadPeople();
        S.go({ app: 'bills', view: 'give', ids: gi });
        break;
      }
      case 'reject-send': sendReject(); break;
      case 'del-request': openDelReq(id); break;
      case 'note': openNote(id); break;
      case 'note-send': sendNote(); break;
      case 'del-request-send': sendDelReq(); break;
      case 'keep':
        S.sheet({
          title: T.keepSheet.title,
          body: T.keepSheet.body,
          confirm: T.keepSheet.confirm,
          cancel: T.keepSheet.cancel,
          onConfirm: function () { act(id, 'keep', null, function () { S.toast(T.kept); }); },
        });
        break;
      case 'give': state.give = null; loadPeople(); S.go({ app: 'bills', view: 'give', ids: [id] }); break;
      case 'keep-many': {
        var gk = state.give;
        if (!gk || state.busy) break;
        var ks = T.sel.keepSheet(gk.ids.length);
        S.sheet({
          title: ks.title,
          body: ks.body,
          confirm: ks.confirm,
          cancel: T.keepSheet.cancel,
          onConfirm: function () {
            acceptThen(gk, function (accepted) {
              doMany('keep', accepted.map(function (b) { return b.id; }), null, function (done) {
                if (done.length) S.toast(gk.answer ? T.sel.acceptedKept(done.length) : T.sel.keptN(done.length));
                state.give = null;
                S.go({ app: 'bills' }, true);
              });
            });
          },
        });
        break;
      }
      case 'give-save': {
        var g = state.give;
        if (!g) break;
        if (!g.to) { g.error = T.need.person; S.render(); break; }
        g.error = null;
        acceptThen(g, function (accepted) {
          doMany('give', accepted.map(function (b) { return b.id; }), { to: g.to }, function (done) {
            if (done.length) S.toast(g.answer ? T.sel.acceptedGiven(done.length, g.toName) : T.sel.given(done.length, g.toName));
            state.give = null;
            S.go({ app: 'bills' }, true);
          });
        });
        break;
      }
      case 'reject-many': {
        var gr = state.give;
        if (gr && gr.answer && !state.busy) openReject(gr.ids);
        break;
      }
    }
  }

  function input(e) {
    var t = e.target;
    var f = state.form;
    var m = /^bill-(received|amount|notes)-(\d+)$/.exec(t.id || '');
    if (m && f && f.entries[Number(m[2])]) {
      var en = f.entries[Number(m[2])];
      en[{ received: 'received_on', amount: 'amount', notes: 'notes' }[m[1]]] = t.value;
      var hadError = !!(en.error || f.error);
      en.error = null;
      f.error = null;
      // Redraw for the red border going, and for the running total.
      if (hadError || (m[1] === 'amount' && f.entries.length > 1)) S.render();
      return;
    }
    switch (t.id) {
      case 'bill-e-received': case 'bill-e-amount': case 'bill-e-notes': case 'bill-e-reason':
        if (state.edit) {
          state.edit[{ 'bill-e-received': 'received_on', 'bill-e-amount': 'amount', 'bill-e-notes': 'notes', 'bill-e-reason': 'reason' }[t.id]] = t.value;
          if (state.edit.error) { state.edit.error = null; S.render(); }
        }
        return;
      case 'bill-pick-q': if (state.pick) { state.pick.q = t.value; S.render(); } return;
      case 'bill-note-text': if (state.note) { state.note.text = t.value; if (state.note.error) { state.note.error = null; S.render(); } } return;
      case 'bill-del-reason': if (state.delreq) { state.delreq.reason = t.value; if (state.delreq.error) { state.delreq.error = null; S.render(); } } return;
      case 'bill-reason': if (state.reject) { state.reject.reason = t.value; if (state.reject.error) { state.reject.error = null; S.render(); } } return;
      default: return;
    }
  }

  // A tap on "You got a bill" while the app is open.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function (e) {
      if (e.data && e.data.type === 'open-bills' && signedIn()) {
        load();
        S.go({ app: 'bills' });
      }
    });
  }

  // Opened from a notification while closed: ./#bills
  if (location.hash === '#bills') {
    setTimeout(function () {
      try { history.replaceState(history.state, '', location.pathname + location.search); } catch (e) { /* ignore */ }
      if (signedIn()) S.go({ app: 'bills' }, true);
    }, 0);
  }

  // Back to the app: what changed while it was away.
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && signedIn() && state.open !== null) load();
  });

  // While the app is open: look for new bills every minute, so the bell and its number stay right.
  setInterval(function () {
    if (!document.hidden && signedIn() && state.open !== null && !state.busy) load();
  }, 60000);

  S.register('bills', {
    render: render,
    action: action,
    input: input,
    start: function () {
      if (!signedIn()) return;
      state.open = null;
      state.recent = null;
      state.loadError = null;
      state.people = null;
      // Loaded now, not when Bills opens, so the home tile can say "2 waiting for you".
      load.pending = true;
      load().then(function () { load.pending = false; });
      alertsCheck();
    },
    beforeSignOut: function () {
      var off = alertsOff();
      dropForm();
      state.open = null;
      state.recent = null;
      state.detail = {};
      state.people = null;
      state.give = null;
      state.pick = null;
      state.reject = null;
      clearSel();
      dropEdit();
      return off;
    },
    /** The bell at the top: how many bills wait for this person to accept; tap opens Bills. */
    bell: function () {
      if (!signedIn()) return '';
      var n = (state.open || []).filter(function (b) { return b.status === 'waiting'; }).length;
      if (!n) return '';
      return '<button type="button" class="icon-btn" style="position:relative" data-key="bill-bell" data-action="bills:bell" aria-label="' + esc(n + ' ' + (n === 1 ? 'bill' : 'bills') + ' waiting for you') + '">' +
        icon('bell') + '<span class="nbadge" style="background:#DC2626;color:#fff">' + (n > 9 ? '9+' : n) + '</span></button>';
    },
    tileStatus: function () {
      var waiting = (state.open || []).filter(function (b) { return b.status === 'waiting'; }).length;
      return waiting ? waiting + ' waiting for you' : null;
    },
  });
})();
