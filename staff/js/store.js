/**
 * Staff app — Store. The store keeper records what goes in and out of the
 * stores, over api/mobile/store (rules: modules/operations/includes/ops_store.php):
 *
 *   Goods in    items → from where (photo) → Save           stock goes up
 *   Goods out   items → who took them (for what) → Save     stock goes down
 *   Move        items → which store → Save                  down here, up there
 *   Count       items with the real number → why → Save     stock set to it
 *   Stock       search an item: how many in each store, its photo
 *
 * He picks the store he is in once; the phone remembers it. One step per
 * screen, big buttons, like the Driver daily check. The draft is kept on the
 * phone as it is typed (not the photo), and each save carries its own id so a
 * double tap or a resend makes one entry. He may change or delete his own
 * entry on the day he made it; after that the office does it.
 */
(function () {
  'use strict';

  var S = window.Staff;
  var esc = S.esc;
  var icon = S.icon;
  var button = S.button;
  var API_BASE = String(S.CFG.storeBase || '../api/mobile/store').replace(/\/+$/, '');

  // -------------------------------------------------------------------------
  // Words
  // -------------------------------------------------------------------------

  var T = {
    title: 'Store',
    greeting: function (name) { return 'Hello, ' + name; },
    youAreIn: 'You are in',
    change: 'Change',
    pickStore: 'Which store are you in?',
    noStores: 'No stores yet. Ask the office to add one.',
    what: 'What are you doing?',
    kinds: {
      in: { title: 'Goods in', hint: 'Things came into the store', icon: 'download' },
      out: { title: 'Goods out', hint: 'Someone took things', icon: 'upload' },
      move: { title: 'Move to another store', hint: 'Send things to a different store', icon: 'truck' },
      count: { title: 'Correct a count', hint: 'The real number is different', icon: 'list' },
    },
    stock: 'Stock',
    stockHint: 'How many of each item',
    recent: 'My entries',
    none: 'Nothing recorded yet.',
    more: 'Show older',
    retry: 'Try again',
    draftTitle: 'Not saved yet',
    draftBody: function (k) { return 'Your ' + k.toLowerCase() + ' is not saved.'; },
    draftGo: 'Carry on',
    draftDrop: 'Throw away',
    step: {
      back: 'Back',
      next: 'Next',
      itemsIn: 'What came in?',
      itemsOut: 'What did they take?',
      itemsMove: 'What are you moving?',
      itemsCount: 'Count the items',
      addItem: 'Add an item',
      howMany: 'How many',
      unit: 'Unit',
      unitFixed: 'This item is counted in this unit. The office can change it.',
      counted: 'Real number',
      here: function (q) { return q + ' in this store'; },
      system: function (q) { return 'The system says ' + q; },
      remove: 'Remove',
      from: 'Where did it come from?',
      fromPlaceholder: 'Shop, returned, other (optional)',
      photo: 'Photo (optional)',
      camera: 'Camera',
      gallery: 'Gallery',
      who: 'Who took them?',
      whoSearch: 'Search a name',
      forNote: 'For (optional)',
      forPlaceholder: 'Building, unit or job',
      toStore: 'Which store are they going to?',
      reason: 'Why is it different?',
      reasons: { lost: 'Lost', damaged: 'Damaged', counted: 'Just counted' },
      checkTitle: 'Check and save',
      notes: 'Note (optional)',
      save: 'Save',
      saveChanges: 'Save changes',
      saving: 'Saving…',
    },
    pick: {
      title: 'Pick an item',
      search: 'Search an item',
      newTitle: 'New item',
      newName: function (n) { return 'Add "' + n + '" as a new item'; },
      unit: 'Unit (pc, box, litre…)',
      add: 'Add item',
      empty: 'No item with that name.',
      newOnlyIn: 'Only items already in the store can go out. New items are added with Goods in.',
    },
    need: {
      store: 'Pick the store you are in first.',
      lines: 'Add at least one item.',
      qty: function (n) { return n + ': write how many.'; },
      counted: function (n) { return n + ': write the number you counted.'; },
      notEnough: function (n, q) { return 'Only ' + q + ' ' + n + ' in this store.'; },
      who: 'Pick who took the items.',
      toStore: 'Pick the store they are going to.',
      reason: 'Say why the number is different.',
    },
    entry: {
      store: 'Store',
      to: 'To',
      who: 'Taken by',
      for: 'For',
      from: 'From',
      reason: 'Why',
      notes: 'Note',
      change: 'Change',
      delete: 'Delete',
      locked: 'From an earlier day. Ask the office to change it.',
      countLocked: 'A count cannot be changed. Delete it and count again.',
      deleteSheet: { title: 'Delete this entry?', body: 'The stock goes back to what it was before.', confirm: 'Yes, delete', cancel: 'Keep it' },
      deleted: 'Deleted.',
      saved: 'Saved.',
    },
    item: {
      perStore: 'In each store',
      low: 'Low',
      photo: 'Take its photo',
      newPhoto: 'New photo',
      photoSaved: 'Photo saved.',
    },
    genericBody: 'Please try again in a moment.',
  };

  Object.assign(S.ICON, {
    trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
  });

  var KEYS = { store: 'staff.store.storeId.v1', draft: 'staff.store.draft.v1' };

  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------

  var state = {
    start: null,       // {stores, items, people}
    startError: null,
    list: null,
    more: false,
    loadingMore: false,
    detail: {},
    detailError: null,
    busy: false,
    error: null,
    photo: null,       // the draft's photo {blob, name, url}
    pick: null,        // the item picker {q, unit}
    whoQ: '',
    stockQ: '',
  };

  function signedIn() { return !!S.token('store'); }

  function api(path, opts) {
    opts = opts || {};
    opts.token = S.token('store');
    return S.request(API_BASE + path, opts);
  }

  function qtyText(n) { return String(Math.round(Number(n || 0) * 1000) / 1000); }

  function num(v) {
    var s = String(v === null || v === undefined ? '' : v).replace(/,/g, '.').trim();
    return s === '' || isNaN(Number(s)) ? NaN : Number(s);
  }

  function storeId() { return S.readJson(KEYS.store, null); }

  function stores() { return (state.start && state.start.stores) || []; }

  function storeName(id) {
    var s = stores().filter(function (x) { return x.id === id; })[0];
    return s ? s.name : '';
  }

  function item(id) {
    return ((state.start && state.start.items) || []).filter(function (i) { return i.id === id; })[0] || null;
  }

  function stockOf(it, store) {
    var q = it && it.stock ? it.stock[store] : 0;
    return Number(q || 0);
  }

  function unitText(it, q) { return qtyText(q) + (it && it.unit ? ' ' + it.unit : ''); }

  // An item has one unit. Until it has one, the line asks for it (pc unless typed) and saving sets it.
  var UNITS = ['pc', 'litre', 'box', 'kg', 'bag', 'bucket', 'm'];
  var DEFAULT_UNIT = 'pc';
  function unitsList() {
    return '<datalist id="st-units">' + UNITS.map(function (u) { return '<option value="' + esc(u) + '">'; }).join('') + '</datalist>';
  }
  function lineUnit(it, l) {
    if (it && it.unit) return it.unit;
    return l.unit === undefined || l.unit === null ? DEFAULT_UNIT : l.unit;
  }

  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function when(stamp) {
    if (!stamp) return '';
    var d = String(stamp).split(' ');
    var p = d[0].split('-');
    return Number(p[2]) + ' ' + MONTHS[Number(p[1]) - 1] + (d[1] ? ' ' + d[1].slice(0, 5) : '');
  }

  // -------------------------------------------------------------------------
  // The draft
  // -------------------------------------------------------------------------

  function draft() { return S.readJson(KEYS.draft, null); }
  function saveDraft(d) { S.writeJson(KEYS.draft, d); }

  function clearPhoto() {
    if (state.photo) URL.revokeObjectURL(state.photo.url);
    state.photo = null;
  }

  function steps(d) {
    if (d.kind === 'in') return d.id ? ['items', 'from', 'check'] : ['items', 'from', 'check'];
    if (d.kind === 'out') return ['items', 'who', 'check'];
    if (d.kind === 'move') return ['items', 'to', 'check'];
    return ['items', 'reason', 'check'];
  }

  function startDraft(kind) {
    if (!storeId()) { chooseStore(function () { startDraft(kind); }); return; }
    clearPhoto();
    state.pick = null;
    state.whoQ = '';
    state.error = null;
    saveDraft({ ref: S.uid(), id: null, kind: kind, storeId: storeId(), toStoreId: null, lines: [], givenId: null, givenName: '', forNote: '', source: '', reason: '', notes: '', step: 'items' });
    S.go({ app: 'store', view: 'flow' });
  }

  function editDraft(m) {
    clearPhoto();
    state.pick = null;
    state.whoQ = '';
    state.error = null;
    saveDraft({
      ref: S.uid(), id: m.id, kind: m.kind, storeId: m.store.id, toStoreId: m.to_store ? m.to_store.id : null,
      lines: m.lines.map(function (l) { return { item_id: l.item_id, qty: qtyText(l.qty) }; }),
      givenId: m.given_to ? m.given_to.id : null, givenName: m.given_to ? m.given_to.name : '',
      forNote: m.for_note || '', source: m.source || '', reason: m.reason || '', notes: m.notes || '', step: 'items',
    });
    S.go({ app: 'store', view: 'flow' });
  }

  function dropDraft() {
    clearPhoto();
    state.pick = null;
    saveDraft(null);
  }

  /** What stops this step, or null. */
  function stepProblem(d, step) {
    if (step === 'items') {
      if (!d.lines.length) return T.need.lines;
      for (var i = 0; i < d.lines.length; i++) {
        var l = d.lines[i];
        var it = item(l.item_id);
        var name = it ? it.name : '';
        var q = num(l.qty);
        if (d.kind === 'count') {
          if (!(q >= 0)) return T.need.counted(name);
        } else {
          if (!(q > 0)) return T.need.qty(name);
          // Changing a saved entry: what it already took is back on the shelf for this check.
          if ((d.kind === 'out' || d.kind === 'move') && !d.id && it && q > stockOf(it, d.storeId)) {
            return T.need.notEnough(name, unitText(it, stockOf(it, d.storeId)));
          }
        }
      }
    }
    if (step === 'who' && !d.givenId) return T.need.who;
    if (step === 'to' && !d.toStoreId) return T.need.toStore;
    if (step === 'reason' && !d.reason) return T.need.reason;
    return null;
  }

  function moveStep(by) {
    var d = draft();
    if (!d) return;
    var list = steps(d);
    var at = list.indexOf(d.step);
    if (by > 0) {
      var problem = stepProblem(d, d.step);
      if (problem) { state.error = problem; S.render(); return; }
    }
    state.error = null;
    var next = at + by;
    if (next < 0) { S.back(); return; }
    d.step = list[Math.min(next, list.length - 1)];
    saveDraft(d);
    S.render();
    window.scrollTo(0, 0);
  }

  // -------------------------------------------------------------------------
  // Talking to the server
  // -------------------------------------------------------------------------

  var loadingStart = false;
  function loadStart() {
    if (loadingStart) return Promise.resolve();
    loadingStart = true;
    return api('/start').then(function (r) {
      state.start = r;
      state.startError = null;
      // The remembered store was closed: ask again.
      if (storeId() && !r.stores.some(function (s) { return s.id === storeId(); })) S.writeJson(KEYS.store, null);
      if (!storeId() && r.stores.length === 1) S.writeJson(KEYS.store, r.stores[0].id);
    }, function (e) {
      state.startError = e.message || T.genericBody;
    }).then(function () { loadingStart = false; S.render(); });
  }

  var loadingList = false;
  function loadList() {
    if (loadingList) return Promise.resolve();
    loadingList = true;
    return api('/moves').then(function (r) {
      state.list = r.moves;
      state.more = r.more;
      r.moves.forEach(function (m) { state.detail[m.id] = m; });
    }, function () { state.list = state.list || []; }).then(function () { loadingList = false; S.render(); });
  }

  function loadMore() {
    if (!state.list || !state.list.length || state.loadingMore) return;
    state.loadingMore = true;
    S.render();
    api('/moves?before=' + state.list[state.list.length - 1].id).then(function (r) {
      state.list = state.list.concat(r.moves);
      state.more = r.more;
      r.moves.forEach(function (m) { state.detail[m.id] = m; });
    }, function (e) { S.toast(e.message || T.genericBody); }).then(function () {
      state.loadingMore = false;
      S.render();
    });
  }

  var loadingOne = {};
  function loadOne(id) {
    if (loadingOne[id]) return;
    loadingOne[id] = true;
    api('/moves/' + id).then(function (m) {
      delete loadingOne[id];
      state.detail[m.id] = m;
      S.render();
    }, function (e) {
      delete loadingOne[id];
      state.detailError = e.message || T.genericBody;
      S.render();
    });
  }

  function addNewItem(name, unit) {
    state.busy = true;
    S.render();
    api('/items', { method: 'POST', json: { name: name, unit: unit } }).then(function (it) {
      state.busy = false;
      if (state.start && !item(it.id)) state.start.items.push(it);
      addLine(it.id);
    }, function (e) {
      state.busy = false;
      S.toast(e.message || T.genericBody);
      S.render();
    });
  }

  function addLine(itemId) {
    var d = draft();
    if (!d) return;
    if (!d.lines.some(function (l) { return l.item_id === itemId; })) d.lines.push({ item_id: itemId, qty: '' });
    saveDraft(d);
    state.pick = null;
    state.error = null;
    S.render();
    setTimeout(function () {
      var el = document.querySelector('[data-qty-item="' + itemId + '"]');
      if (el) el.focus();
    }, 60);
  }

  function save() {
    var d = draft();
    if (!d || state.busy) return;
    var list = steps(d);
    for (var i = 0; i < list.length; i++) {
      var problem = stepProblem(d, list[i]);
      if (problem) { d.step = list[i]; saveDraft(d); state.error = problem; S.render(); return; }
    }
    var body = {
      kind: d.kind,
      store_id: d.storeId,
      to_store_id: d.toStoreId || undefined,
      given_to_employee_id: d.givenId || undefined,
      for_note: d.forNote,
      source: d.source,
      reason: d.reason || undefined,
      notes: d.notes,
      client_ref: d.ref,
      lines: d.lines.map(function (l) {
        var it = item(l.item_id);
        var line = { item_id: l.item_id, qty: num(l.qty) };
        if (it && !it.unit) line.unit = String(lineUnit(it, l)).trim() || DEFAULT_UNIT;
        return line;
      }),
    };
    var req;
    if (d.id) {
      req = api('/moves/' + d.id, { method: 'PUT', json: body });
    } else if (state.photo) {
      var form = new FormData();
      form.append('data', JSON.stringify(body));
      form.append('photo', state.photo.blob, state.photo.name);
      req = api('/moves', { method: 'POST', form: form, timeout: 90000 });
    } else {
      req = api('/moves', { method: 'POST', json: body });
    }
    state.busy = true;
    state.error = null;
    S.render();
    req.then(function (m) {
      state.busy = false;
      dropDraft();
      state.detail[m.id] = m;
      S.toast(T.entry.saved);
      loadStart();
      loadList();
      S.go({ app: 'store', view: 'entry', id: m.id }, true);
    }, function (e) {
      state.busy = false;
      state.error = e.message || T.genericBody;
      S.render();
    });
  }

  // -------------------------------------------------------------------------
  // Choosing the store
  // -------------------------------------------------------------------------

  function chooseStore(then) {
    if (!stores().length) { S.toast(T.noStores); return; }
    S.sheet({
      title: T.pickStore,
      html: '<div class="st-stores">' + stores().map(function (s) {
        return button(s.name, 'store:set-store', { variant: s.id === storeId() ? '' : 'secondary', data: 'data-id="' + s.id + '"' });
      }).join('') + '</div>',
      cancel: T.step.back,
    });
    chooseStore.then = then || null;
  }

  // -------------------------------------------------------------------------
  // Screens
  // -------------------------------------------------------------------------

  function photoSrc(kind, id, has) {
    if (!has) return null;
    return S.media('st-' + kind + '-' + id, function () {
      return api('/' + (kind === 'item' ? 'items' : 'moves') + '/' + id + '/photo', { raw: true, timeout: 60000 });
    });
  }

  function entryTitle(m) {
    var names = m.lines.map(function (l) { return l.name; });
    return names.slice(0, 2).join(', ') + (names.length > 2 ? ' +' + (names.length - 2) : '');
  }

  function entryLine(m) {
    if (m.kind === 'out') return (m.given_to ? m.given_to.name : '') + (m.for_note ? ' · ' + m.for_note : '');
    if (m.kind === 'move') return m.store.name + ' → ' + (m.to_store ? m.to_store.name : '');
    if (m.kind === 'count') return T.step.reasons[m.reason] || '';
    return m.source || m.store.name;
  }

  function entryCard(m) {
    return '<button type="button" class="card row" data-key="m' + m.id + '" data-action="store:open" data-id="' + m.id + '">' +
      '<span class="pur-icon st-' + m.kind + '">' + icon(T.kinds[m.kind].icon, 'lg') + '</span>' +
      '<span class="grow"><span class="h3 ellipsis" style="display:block">' + esc(entryTitle(m)) + '</span>' +
      '<span class="secondary ellipsis" style="display:block">' + esc(T.kinds[m.kind].title + ' · ' + entryLine(m)) + '</span></span>' +
      '<span class="small muted" style="white-space:nowrap">' + esc(when(m.created_at)) + '</span></button>';
  }

  function renderHome() {
    var s = S.session();
    var body = S.installCard();
    var d = draft();
    var here = storeId();

    if (state.start === null && !state.startError) {
      loadStart();
      body += '<div class="skel mt-3" style="height:76px"></div><div class="skel" style="height:220px"></div>';
    } else if (state.startError) {
      body += '<div class="card"><div class="body-lg">' + esc(state.startError) + '</div><div class="mt-3">' +
        button(T.retry, 'store:reload', { variant: 'secondary' }) + '</div></div>';
    } else {
      body += '<button type="button" class="card row" data-action="store:choose-store">' +
        '<span class="pur-icon">' + icon('home', 'lg') + '</span>' +
        '<span class="grow"><span class="small muted" style="display:block">' + esc(T.youAreIn) + '</span>' +
        '<span class="h2" style="display:block">' + esc(here ? storeName(here) : T.pickStore) + '</span></span>' +
        '<span class="secondary">' + esc(T.change) + '</span></button>';

      if (d) {
        body += '<div class="card info"><div class="row">' + icon('alert') + '<div class="grow"><div class="h3">' + esc(T.draftTitle) + '</div>' +
          '<div class="small secondary mt-1">' + esc(T.draftBody(T.kinds[d.kind].title)) + '</div></div></div>' +
          '<div class="action-row mt-3">' + button(T.draftDrop, 'store:draft-drop', { variant: 'plain', style: 'flex:2' }) +
          button(T.draftGo, 'store:draft-go', { style: 'flex:3' }) + '</div></div>';
      }

      body += '<h2 class="h3 section-title mt-4">' + esc(T.what) + '</h2><div class="st-grid">' +
        ['in', 'out', 'move', 'count'].map(function (k) {
          return '<button type="button" class="st-tile st-' + k + '" data-action="store:new" data-kind="' + k + '">' +
            icon(T.kinds[k].icon, 'xl') + '<span class="h3">' + esc(T.kinds[k].title) + '</span>' +
            '<span class="small secondary">' + esc(T.kinds[k].hint) + '</span></button>';
        }).join('') + '</div>' +
        '<button type="button" class="app-tile" data-action="store:stock">' +
        '<span class="app-icon">' + icon('box', 'xl') + '</span>' +
        '<span class="grow"><span class="h2" style="display:block">' + esc(T.stock) + '</span><span class="secondary" style="display:block">' + esc(T.stockHint) + '</span></span>' +
        icon('chevron') + '</button>';

      if (state.list === null) loadList();
      else {
        body += '<h2 class="h3 section-title mt-5">' + esc(T.recent) + '</h2>';
        if (!state.list.length) body += '<div class="card"><div class="body-lg secondary">' + esc(T.none) + '</div></div>';
        state.list.forEach(function (m) { body += entryCard(m); });
        if (state.more) body += '<div class="mt-3">' + button(T.more, 'store:more', { variant: 'secondary', loading: state.loadingMore }) + '</div>';
      }
    }

    return '<main class="screen">' +
      S.topHeader(T.title, T.greeting(s ? String(s.user.name).trim().split(/\s+/)[0] : '')) +
      '<div class="screen-body">' + body + '</div></main>';
  }

  function stepTop(d) {
    var list = steps(d);
    var n = list.indexOf(d.step) + 1;
    return '<div class="topbar"><button type="button" class="back-btn" data-action="store:step-back">' + icon('back') + esc(T.step.back) + '</button>' +
      '<span class="qcount">' + n + ' / ' + list.length + '</span></div>' +
      '<div class="qprogress"><span style="width:' + Math.round((n / list.length) * 100) + '%"></span></div>';
  }

  function renderPicker(d) {
    var p = state.pick;
    var q = String(p.q || '').trim();
    var ql = q.toLowerCase();
    var all = (state.start && state.start.items) || [];
    var shown = all.filter(function (it) {
      if (ql && it.name.toLowerCase().indexOf(ql) === -1) return false;
      // Going out or moving: only what this store has.
      if ((d.kind === 'out' || d.kind === 'move') && !d.id && stockOf(it, d.storeId) <= 0) return false;
      return true;
    }).slice(0, 40);
    var exact = all.some(function (it) { return it.name.toLowerCase() === ql; });
    var body = '<h1 class="h2 mt-2">' + esc(T.pick.title) + '</h1>' +
      '<input class="field mt-3" id="st-pick-q" type="search" autocomplete="off" placeholder="' + esc(T.pick.search) + '" value="' + esc(p.q) + '">' +
      shown.map(function (it) {
        var have = stockOf(it, d.storeId);
        var chosen = d.lines.some(function (l) { return l.item_id === it.id; });
        return '<button type="button" class="card row' + (chosen ? ' st-chosen' : '') + '" data-key="i' + it.id + '" data-action="store:pick-item" data-id="' + it.id + '">' +
          '<span class="grow h3">' + esc(it.name) + '</span>' +
          '<span class="st-qty' + (have <= 0 ? ' muted' : '') + '">' + esc(unitText(it, have)) + '</span>' +
          (chosen ? '<span class="check">' + icon('check') + '</span>' : '') + '</button>';
      }).join('') +
      (!shown.length ? '<div class="card"><div class="secondary">' + esc(T.pick.empty) + '</div></div>' : '');
    if (q && !exact) {
      if (d.kind === 'in' || d.kind === 'count') {
        body += '<div class="card info mt-3"><div class="h3">' + esc(T.pick.newName(q)) + '</div>' +
          '<input class="field mt-2" id="st-pick-unit" type="text" list="st-units" autocomplete="off" maxlength="20" placeholder="' + esc(T.pick.unit) + '" value="' + esc(p.unit) + '">' + unitsList() +
          '<div class="mt-3">' + button(T.pick.add, 'store:new-item', { icon: 'plus', loading: state.busy }) + '</div></div>';
      } else {
        body += '<div class="card info mt-3"><div class="small">' + esc(T.pick.newOnlyIn) + '</div></div>';
      }
    }
    return '<main class="screen qscreen"><div class="topbar"><button type="button" class="back-btn" data-action="store:pick-close">' + icon('back') + esc(T.step.back) + '</button></div>' +
      '<div class="screen-body">' + body + '</div></main>';
  }

  function renderFlow() {
    var d = draft();
    if (!d) { S.go({ app: 'store' }, true); return renderHome(); }
    if (state.start === null) { loadStart(); return '<main class="screen"><div class="skel mt-3" style="height:240px"></div></main>'; }
    if (state.pick) return renderPicker(d);
    var k = T.kinds[d.kind];
    var body;
    var footer = button(T.step.next, 'store:step-next', { icon: 'chevron' });

    if (d.step === 'items') {
      var title = d.kind === 'in' ? T.step.itemsIn : d.kind === 'out' ? T.step.itemsOut : d.kind === 'move' ? T.step.itemsMove : T.step.itemsCount;
      body = '<div class="label muted mt-2">' + esc(k.title + ' · ' + storeName(d.storeId)) + '</div>' +
        '<h1 class="h2">' + esc(title) + '</h1>' +
        d.lines.map(function (l, i) {
          var it = item(l.item_id) || { name: '?', unit: null };
          var have = stockOf(it, d.storeId);
          var unit = lineUnit(it, l);
          var shown = { unit: String(unit).trim() };
          return '<div class="card" data-key="l' + l.item_id + '"><div class="row"><span class="grow h3">' + esc(it.name) + '</span>' +
            '<button type="button" class="icon-btn" data-action="store:line-del" data-i="' + i + '" aria-label="' + esc(T.step.remove) + '">' + icon('trash') + '</button></div>' +
            '<div class="row mt-2" style="align-items:flex-end"><label class="grow"><span class="small muted">' + esc(d.kind === 'count' ? T.step.counted : T.step.howMany) + '</span>' +
            '<input class="field st-num" data-qty-item="' + l.item_id + '" data-i="' + i + '" type="text" inputmode="decimal" autocomplete="off" placeholder="0" value="' + esc(l.qty) + '"></label>' +
            '<label style="width:40%"><span class="small muted">' + esc(T.step.unit) + '</span>' +
            '<input class="field" data-unit-item="' + l.item_id + '" data-i="' + i + '" type="text" list="st-units" autocomplete="off" maxlength="20" placeholder="' + esc(DEFAULT_UNIT) + '" value="' + esc(unit) + '"' +
            (it.unit ? ' disabled title="' + esc(T.step.unitFixed) + '"' : '') + '></label></div>' +
            '<div class="small secondary mt-2">' + esc(d.kind === 'count' ? T.step.system(unitText(shown, have)) : T.step.here(unitText(shown, have))) + '</div></div>';
        }).join('') + unitsList() +
        '<div class="mt-3">' + button(T.step.addItem, 'store:pick-open', { variant: 'secondary', icon: 'plus' }) + '</div>';
    } else if (d.step === 'from') {
      body = '<div class="qcard"><div class="qicon">' + icon('download', 'huge') + '</div><div class="qtext">' + esc(T.step.from) + '</div></div>' +
        '<input class="field mt-3" id="st-source" type="text" maxlength="120" autocomplete="off" placeholder="' + esc(T.step.fromPlaceholder) + '" value="' + esc(d.source) + '">' +
        (d.id ? '' : '<div class="small muted mt-4">' + esc(T.step.photo) + '</div>' +
          '<div class="qtools"><button type="button" class="qtool" data-action="store:photo" data-camera="1">' + icon('camera', 'lg') + '<span>' + esc(T.step.camera) + '</span></button>' +
          '<button type="button" class="qtool" data-action="store:photo">' + icon('gallery', 'lg') + '<span>' + esc(T.step.gallery) + '</span></button></div>' +
          (state.photo ? '<div class="qthumbs"><div class="qthumb"><img src="' + esc(state.photo.url) + '" alt="">' +
            '<button type="button" class="remove" data-action="store:photo-del" aria-label="Delete">' + icon('close') + '</button></div></div>' : ''));
    } else if (d.step === 'who') {
      var wq = state.whoQ.trim().toLowerCase();
      var people = ((state.start && state.start.people) || []).filter(function (p) {
        return !wq || p.name.toLowerCase().indexOf(wq) !== -1 || (p.code && p.code.toLowerCase().indexOf(wq) !== -1);
      }).slice(0, 30);
      body = '<h1 class="h2 mt-2">' + esc(T.step.who) + '</h1>' +
        (d.givenId ? '<div class="card row st-chosen"><span class="pur-icon">' + icon('user', 'lg') + '</span><span class="grow h3">' + esc(d.givenName) + '</span><span class="check">' + icon('check') + '</span></div>' : '') +
        '<label class="mt-3" style="display:block"><span class="small muted">' + esc(T.step.forNote) + '</span>' +
        '<input class="field mt-1" id="st-for" type="text" maxlength="160" autocomplete="off" placeholder="' + esc(T.step.forPlaceholder) + '" value="' + esc(d.forNote) + '"></label>' +
        '<input class="field mt-4" id="st-who-q" type="search" autocomplete="off" placeholder="' + esc(T.step.whoSearch) + '" value="' + esc(state.whoQ) + '">' +
        people.map(function (p) {
          var on = d.givenId === p.id;
          return '<button type="button" class="card vehicle' + (on ? ' selected' : '') + '" data-key="p' + p.id + '" data-action="store:pick-person" data-id="' + p.id + '">' +
            '<span class="veh-icon">' + icon('user') + '</span><span class="grow"><span class="h3" style="display:block">' + esc(p.name) + '</span>' +
            (p.code ? '<span class="small muted">' + esc(p.code) + '</span>' : '') + '</span>' +
            (on ? '<span class="check">' + icon('check') + '</span>' : '') + '</button>';
        }).join('');
    } else if (d.step === 'to') {
      body = '<div class="qcard"><div class="qicon">' + icon('truck', 'huge') + '</div><div class="qtext">' + esc(T.step.toStore) + '</div></div>' +
        stores().filter(function (s) { return s.id !== d.storeId; }).map(function (s) {
          var on = d.toStoreId === s.id;
          return '<button type="button" class="card vehicle' + (on ? ' selected' : '') + '" data-key="t' + s.id + '" data-action="store:pick-to" data-id="' + s.id + '">' +
            '<span class="veh-icon">' + icon('home') + '</span><span class="grow h3">' + esc(s.name) + '</span>' +
            (on ? '<span class="check">' + icon('check') + '</span>' : '') + '</button>';
        }).join('');
    } else if (d.step === 'reason') {
      body = '<div class="qcard"><div class="qicon">' + icon('list', 'huge') + '</div><div class="qtext">' + esc(T.step.reason) + '</div></div>' +
        '<div class="qanswers mt-3">' + ['lost', 'damaged', 'counted'].map(function (r) {
          return '<button type="button" class="qbtn st-reason' + (d.reason === r ? ' on' : '') + '" data-action="store:pick-reason" data-reason="' + r + '">' + esc(T.step.reasons[r]) + '</button>';
        }).join('') + '</div>';
    } else {
      body = '<h1 class="h2 mt-2">' + esc(T.step.checkTitle) + '</h1>' +
        '<div class="trip-card mt-3"><div class="label gold">' + esc(k.title) + '</div>' +
        '<div class="h2 mt-1">' + esc(storeName(d.storeId) + (d.kind === 'move' ? ' → ' + storeName(d.toStoreId) : '')) + '</div>' +
        (d.kind === 'out' ? '<div class="on-navy-muted mt-1">' + esc(d.givenName + (d.forNote ? ' · ' + d.forNote : '')) + '</div>' : '') +
        (d.kind === 'in' && d.source ? '<div class="on-navy-muted mt-1">' + esc(d.source) + '</div>' : '') +
        (d.kind === 'count' ? '<div class="on-navy-muted mt-1">' + esc(T.step.reasons[d.reason] || '') + '</div>' : '') + '</div>' +
        '<div class="card">' + d.lines.map(function (l) {
          var it = item(l.item_id) || { name: '?', unit: null };
          return '<div class="kv pur-kv"><span>' + esc(it.name) + '</span><span>' + esc((d.kind === 'count' ? '= ' : '') + unitText(it, num(l.qty))) + '</span></div>';
        }).join('') + '</div>' +
        '<textarea class="field mt-3" id="st-notes" rows="2" maxlength="500" placeholder="' + esc(T.step.notes) + '">' + esc(d.notes) + '</textarea>';
      footer = button(state.busy ? T.step.saving : d.id ? T.step.saveChanges : T.step.save, 'store:save', { loading: state.busy });
    }

    return '<main class="screen qscreen">' + stepTop(d) +
      '<div class="screen-body">' + body + (state.error ? '<div class="card alert">' + esc(state.error) + '</div>' : '') + '</div>' +
      '<div class="footer">' + footer + '</div></main>';
  }

  function renderStock() {
    var here = storeId();
    var q = state.stockQ.trim().toLowerCase();
    var items = ((state.start && state.start.items) || []).filter(function (it) { return !q || it.name.toLowerCase().indexOf(q) !== -1; });
    if (state.start === null) loadStart();
    var body = '<h1 class="h2 mt-2">' + esc(T.stock) + '</h1>' +
      '<div class="small muted">' + esc(storeName(here)) + '</div>' +
      '<input class="field mt-3" id="st-stock-q" type="search" autocomplete="off" placeholder="' + esc(T.pick.search) + '" value="' + esc(state.stockQ) + '">' +
      items.slice(0, 100).map(function (it) {
        var have = stockOf(it, here);
        var low = it.min_qty !== null && have < it.min_qty;
        var other = it.total - have;
        return '<button type="button" class="card row" data-key="s' + it.id + '" data-action="store:item" data-id="' + it.id + '">' +
          '<span class="grow"><span class="h3" style="display:block">' + esc(it.name) + '</span>' +
          (other ? '<span class="small muted">' + esc(qtyText(other) + ' in other stores') + '</span>' : '') + '</span>' +
          (low ? '<span class="pill" style="background:#FEE2E2;color:#991B1B">' + esc(T.item.low) + '</span>' : '') +
          '<span class="st-qty' + (have <= 0 ? ' muted' : '') + '">' + esc(unitText(it, have)) + '</span></button>';
      }).join('');
    return '<main class="screen">' + S.backBar('store:back', T.step.back) + '<div class="screen-body">' + body + '</div></main>';
  }

  function renderItem(route) {
    var it = item(route.id);
    if (!it) return renderStock();
    var src = photoSrc('item', it.id, it.photo);
    var body = '<div class="trip-card mt-2"><div class="label gold">' + esc(T.stock) + '</div><div class="h1">' + esc(it.name) + '</div>' +
      '<div class="h2 mt-2">' + esc(unitText(it, it.total)) + '</div></div>' +
      (it.photo ? '<button type="button" class="card" style="padding:0;overflow:hidden" data-action="store:view-item-photo" data-id="' + it.id + '">' +
        (src ? '<img src="' + esc(src) + '" alt="" style="width:100%;max-height:260px;object-fit:cover;display:block">' : '<div class="skel" style="height:200px;margin:0"></div>') + '</button>' : '') +
      '<div class="mt-3">' + button(it.photo ? T.item.newPhoto : T.item.photo, 'store:item-photo', { variant: 'secondary', icon: 'camera', data: 'data-id="' + it.id + '"', loading: state.busy }) + '</div>' +
      '<h2 class="h3 section-title mt-4">' + esc(T.item.perStore) + '</h2><div class="card">' +
      stores().map(function (s) {
        var have = stockOf(it, s.id);
        var low = it.min_qty !== null && have < it.min_qty;
        return '<div class="kv pur-kv"><span>' + esc(s.name) + '</span><span' + (low ? ' style="color:var(--danger)"' : '') + '>' + esc(unitText(it, have)) + '</span></div>';
      }).join('') + '</div>';
    return '<main class="screen">' + S.backBar('store:back', T.step.back) + '<div class="screen-body">' + body + '</div></main>';
  }

  function renderEntry(route) {
    var m = state.detail[route.id];
    var top = S.backBar('store:back', T.step.back);
    if (!m) {
      if (!state.detailError) loadOne(route.id);
      return '<main class="screen">' + top + '<div class="screen-body">' +
        (state.detailError ? '<div class="card"><div class="body-lg">' + esc(state.detailError) + '</div></div>' : '<div class="skel mt-3" style="height:160px"></div>') +
        '</div></main>';
    }
    var src = photoSrc('move', m.id, m.photo);
    var kv = function (k, v) { return v ? '<div class="kv pur-kv"><span class="secondary">' + esc(k) + '</span><span>' + esc(v) + '</span></div>' : ''; };
    var body = '<div class="trip-card mt-2"><div class="label gold">' + esc(T.kinds[m.kind].title) + '</div>' +
      '<div class="h2 mt-1">' + esc(m.store.name + (m.to_store ? ' → ' + m.to_store.name : '')) + '</div>' +
      '<div class="on-navy-muted mt-1">' + esc(when(m.created_at)) + '</div></div>' +
      '<div class="card">' + m.lines.map(function (l) {
        var q = m.kind === 'count' ? '= ' + qtyText(l.counted) + ' (' + (l.qty > 0 ? '+' : '') + qtyText(l.qty) + ')' : qtyText(l.qty);
        return '<div class="kv pur-kv"><span>' + esc(l.name) + '</span><span>' + esc(q + (l.unit ? ' ' + l.unit : '')) + '</span></div>';
      }).join('') + '</div>' +
      '<div class="card">' + kv(T.entry.who, m.given_to && m.given_to.name) + kv(T.entry.for, m.for_note) + kv(T.entry.from, m.source) +
      kv(T.entry.reason, m.reason && T.step.reasons[m.reason]) + kv(T.entry.notes, m.notes) + kv(T.entry.store, m.store.name) + '</div>' +
      (m.photo ? '<button type="button" class="card" style="padding:0;overflow:hidden" data-action="store:view-move-photo" data-id="' + m.id + '">' +
        (src ? '<img src="' + esc(src) + '" alt="" style="width:100%;max-height:260px;object-fit:cover;display:block">' : '<div class="skel" style="height:200px;margin:0"></div>') + '</button>' : '');
    if (!m.can_change) body += '<div class="card info mt-4"><div class="small">' + esc(T.entry.locked) + '</div></div>';
    else if (m.kind === 'count') body += '<div class="card info mt-4"><div class="small">' + esc(T.entry.countLocked) + '</div></div>';
    var footer = '';
    if (m.can_change) {
      footer = '<div class="action-row">' + button(T.entry.delete, 'store:delete', { variant: 'plain', style: 'flex:2', data: 'data-id="' + m.id + '"' }) +
        (m.kind !== 'count' ? button(T.entry.change, 'store:edit', { variant: 'secondary', style: 'flex:3', icon: 'edit', data: 'data-id="' + m.id + '"' }) : '') + '</div>';
    }
    return '<main class="screen">' + top + '<div class="screen-body">' + body + '</div>' + (footer ? '<div class="footer">' + footer + '</div>' : '') + '</main>';
  }

  function render(route) {
    if (route.view === 'flow') return renderFlow();
    if (route.view === 'stock') return renderStock();
    if (route.view === 'item') return renderItem(route);
    if (route.view === 'entry') return renderEntry(route);
    return renderHome();
  }

  // -------------------------------------------------------------------------
  // Taps and typing
  // -------------------------------------------------------------------------

  function action(name, el) {
    var id = Number(el.getAttribute('data-id'));
    var d;
    switch (name) {
      case 'reload': state.start = null; state.startError = null; S.render(); break;
      case 'choose-store': chooseStore(null); break;
      case 'set-store': {
        S.writeJson(KEYS.store, id);
        var then = chooseStore.then;
        chooseStore.then = null;
        S.closeSheet();
        if (then) then();
        break;
      }
      case 'new': startDraft(el.getAttribute('data-kind')); break;
      case 'draft-go': state.error = null; S.go({ app: 'store', view: 'flow' }); break;
      case 'draft-drop': dropDraft(); S.render(); break;
      case 'more': loadMore(); break;
      case 'stock': if (!storeId()) { chooseStore(function () { S.go({ app: 'store', view: 'stock' }); }); break; } state.stockQ = ''; S.go({ app: 'store', view: 'stock' }); break;
      case 'item': S.go({ app: 'store', view: 'item', id: id }); break;
      case 'open': state.detailError = null; S.go({ app: 'store', view: 'entry', id: id }); break;
      case 'back': S.back(); break;
      case 'step-back': moveStep(-1); break;
      case 'step-next': moveStep(1); break;
      case 'pick-open': state.pick = { q: '', unit: DEFAULT_UNIT }; S.render(); window.scrollTo(0, 0); setTimeout(function () { var q = document.getElementById('st-pick-q'); if (q) q.focus(); }, 60); break;
      case 'pick-close': state.pick = null; S.render(); break;
      case 'pick-item': addLine(id); break;
      case 'new-item': {
        var nm = String(state.pick && state.pick.q || '').trim();
        if (nm) addNewItem(nm, String(state.pick.unit || '').trim());
        break;
      }
      case 'line-del':
        d = draft();
        d.lines.splice(Number(el.getAttribute('data-i')), 1);
        saveDraft(d);
        S.render();
        break;
      case 'pick-person': {
        d = draft();
        var p = ((state.start && state.start.people) || []).filter(function (x) { return x.id === id; })[0];
        if (!p) break;
        d.givenId = p.id;
        d.givenName = p.name;
        saveDraft(d);
        state.error = null;
        S.render();
        break;
      }
      case 'pick-to':
        d = draft();
        d.toStoreId = id;
        saveDraft(d);
        state.error = null;
        moveStep(1);
        break;
      case 'pick-reason':
        d = draft();
        d.reason = el.getAttribute('data-reason');
        saveDraft(d);
        state.error = null;
        moveStep(1);
        break;
      case 'photo':
        S.pickFiles('image/*', el.hasAttribute('data-camera'), false).then(function (files) {
          return files[0] ? S.preparePhoto(files[0]) : null;
        }).then(function (p) {
          if (!p) return;
          clearPhoto();
          state.photo = { blob: p.blob, name: p.name, url: URL.createObjectURL(p.blob) };
          S.render();
        }, function (e) { S.toast(e.message || T.genericBody); });
        break;
      case 'photo-del': clearPhoto(); S.render(); break;
      case 'save': save(); break;
      case 'item-photo':
        S.pickFiles('image/*', true, false).then(function (files) {
          return files[0] ? S.preparePhoto(files[0]) : null;
        }).then(function (p) {
          if (!p) return;
          var form = new FormData();
          form.append('photo', p.blob, p.name);
          state.busy = true;
          S.render();
          return api('/items/' + id + '/photo', { method: 'POST', form: form, timeout: 90000 }).then(function () {
            state.busy = false;
            S.mediaForget('st-item-' + id);
            var it = item(id);
            if (it) it.photo = true;
            S.toast(T.item.photoSaved);
            S.render();
          });
        }).catch(function (e) { state.busy = false; S.toast(e.message || T.genericBody); S.render(); });
        break;
      case 'view-item-photo':
      case 'view-move-photo': {
        var src = photoSrc(name === 'view-item-photo' ? 'item' : 'move', id, true);
        if (src) S.openViewer('<img src="' + esc(src) + '" alt="">');
        break;
      }
      case 'edit': if (state.detail[id]) editDraft(state.detail[id]); break;
      case 'delete':
        S.sheet({
          title: T.entry.deleteSheet.title,
          body: T.entry.deleteSheet.body,
          confirm: T.entry.deleteSheet.confirm,
          cancel: T.entry.deleteSheet.cancel,
          danger: true,
          onConfirm: function () {
            api('/moves/' + id, { method: 'DELETE' }).then(function () {
              delete state.detail[id];
              if (state.list) state.list = state.list.filter(function (x) { return x.id !== id; });
              S.toast(T.entry.deleted);
              loadStart();
              S.go({ app: 'store' }, true);
            }, function (e) { S.toast(e.message || T.genericBody); });
          },
        });
        break;
    }
  }

  function input(e) {
    var t = e.target;
    var r = S.state.route;
    if (r.app !== 'store') return;
    if (t.id === 'st-stock-q') { state.stockQ = t.value; S.render(); return; }
    if (t.id === 'st-pick-q' && state.pick) { state.pick.q = t.value; S.render(); return; }
    if (t.id === 'st-pick-unit' && state.pick) { state.pick.unit = t.value; return; }
    if (t.id === 'st-who-q') { state.whoQ = t.value; S.render(); return; }
    var d = r.view === 'flow' ? draft() : null;
    if (!d) return;
    if (t.hasAttribute('data-qty-item')) {
      var l = d.lines[Number(t.getAttribute('data-i'))];
      if (l) l.qty = t.value;
    } else if (t.hasAttribute('data-unit-item')) {
      var lu = d.lines[Number(t.getAttribute('data-i'))];
      if (lu) lu.unit = t.value;
    } else if (t.id === 'st-source') {
      d.source = t.value;
    } else if (t.id === 'st-for') {
      d.forNote = t.value;
    } else if (t.id === 'st-notes') {
      d.notes = t.value;
    } else {
      return;
    }
    state.error = null;
    saveDraft(d);
    S.render();
  }

  S.register('store', {
    render: render,
    action: action,
    input: input,
    start: function () { if (signedIn()) { state.start = null; state.list = null; } },
    beforeSignOut: function () {
      dropDraft();
      state.start = null;
      state.list = null;
      state.detail = {};
      return Promise.resolve();
    },
    tileStatus: function () {
      if (draft()) return T.draftTitle;
      var here = storeId();
      return here && state.start ? storeName(here) : null;
    },
  });
})();
