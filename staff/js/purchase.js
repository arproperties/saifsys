/**
 * Staff app — Purchase. The buyer records what he bought, as it happens, over
 * api/mobile/purchase (rules: modules/operations/includes/ops_purchases.php):
 *
 *   Paid cash   shop → items → photo of the bill → check → Save
 *   Order       supplier → items → (photo) → check → Save; later "Goods arrived"
 *               with a photo of the delivery note
 *
 * One step per screen, big buttons, like the Driver daily check. The draft is
 * kept on the phone as it is typed (not the photos), so a call or a lost signal
 * does not lose it. Each save carries the draft's own id, so a double tap or a
 * resend makes one purchase.
 *
 * Each item line has a unit and a note, both optional. On the last step the
 * buyer can tick "For outsource" and say who / what for.
 *
 * He may change or delete a purchase on the day he made it; after that the
 * office does it (Operations → Purchases).
 */
(function () {
  'use strict';

  var S = window.Staff;
  var esc = S.esc;
  var icon = S.icon;
  var button = S.button;
  var API_BASE = String(S.CFG.purchaseBase || '../api/mobile/purchase').replace(/\/+$/, '');

  // -------------------------------------------------------------------------
  // Words
  // -------------------------------------------------------------------------

  var T = {
    title: 'Purchase',
    greeting: function (name) { return 'Hello, ' + name; },
    newTitle: 'What are you buying?',
    cash: 'Paid cash at a shop',
    cashHint: 'Take a photo of the bill',
    order: 'Order from a supplier',
    orderHint: 'The goods come later',
    draftTitle: 'Not saved yet',
    draftBody: function (shop) { return shop ? 'Your purchase at ' + shop + ' is not saved.' : 'You started a purchase and did not save it.'; },
    draftGo: 'Carry on',
    draftDrop: 'Throw away',
    waitingTitle: 'Waiting to arrive',
    arrived: 'Arrived',
    mineTitle: 'My purchases',
    none: 'Nothing recorded yet.',
    more: 'Show older',
    retry: 'Try again',
    items: function (n) { return n === 1 ? '1 item' : n + ' items'; },
    status: { paid: 'Paid cash', ordered: 'Waiting', received: 'Received' },
    step: {
      back: 'Back',
      next: 'Next',
      shopCash: 'Which shop?',
      shopOrder: 'Which supplier?',
      shopPlaceholder: 'Name of the shop',
      supplierPlaceholder: 'Name of the supplier',
      used: 'Used before',
      itemsTitle: 'What did you buy?',
      itemsTitleOrder: 'What did you order?',
      itemName: 'Item, e.g. Bulb 9W',
      qty: 'How many',
      unit: 'Unit',
      lineNote: 'Note (optional), e.g. 1 box = 2 kg',
      price: 'Price for one',
      add: 'Add another item',
      remove: 'Remove',
      total: 'Total',
      photoCash: 'Photo of the bill',
      photoOrder: 'Photo of the order',
      photoOrderHint: 'If you have one. You can skip this.',
      camera: 'Camera',
      gallery: 'Gallery',
      checkTitle: 'Check and save',
      notes: 'Note (optional)',
      outsource: 'For outsource / outside work',
      outsourceHint: 'Not for our own use',
      outsourceNote: 'Who / what for (optional)',
      save: 'Save',
      saveChanges: 'Save changes',
      saving: 'Saving…',
      photos: function (n) { return n === 1 ? '1 photo' : n + ' photos'; },
      noPhoto: 'No photo',
    },
    need: {
      shop: 'Write the name of the shop or supplier.',
      itemName: function (n) { return 'Item ' + n + ': write what you bought.'; },
      qty: function (name) { return name + ': write how many.'; },
      price: function (name) { return name + ': write the price for one.'; },
      bill: 'Take a photo of the bill.',
      delivery: 'Take a photo of the delivery note or the invoice.',
      maxPhotos: 'That is enough photos.',
      notPhoto: 'Only JPG, PNG, GIF or WEBP photos are allowed.',
    },
    detail: {
      bought: 'Bought',
      received: 'Received',
      by: 'By',
      notes: 'Note',
      outsource: 'For outsource',
      photos: 'Photos',
      bill: 'Bill',
      delivery: 'Delivery note',
      addPhoto: 'Add a photo',
      arrived: 'Goods arrived',
      change: 'Change',
      delete: 'Delete',
      locked: 'From an earlier day. Ask the office to change it.',
      deleteSheet: { title: 'Delete this purchase?', body: 'It is removed with its photos.', confirm: 'Yes, delete', cancel: 'Keep it' },
      deleted: 'Deleted.',
      saved: 'Saved.',
      photoDeleteSheet: { title: 'Delete this photo?', confirm: 'Yes, delete', cancel: 'Keep it' },
    },
    receive: {
      title: 'Did the goods arrive?',
      hint: 'Take a photo of the delivery note or the invoice.',
      save: 'Save — goods arrived',
      done: 'Marked as arrived.',
    },
    genericBody: 'Please try again in a moment.',
  };

  Object.assign(S.ICON, {
    cart: '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
    cash: '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
    store: '<path d="M3 9l1-5h16l1 5"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8h14v-8"/><path d="M10 20v-5h4v5"/>',
    receipt: '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6"/><path d="M16 12H8"/><path d="M13 16H8"/>',
    file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
  });

  var MAX_PHOTOS = 6;
  var DRAFT_KEY = 'staff.purchase.draft.v1';

  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------

  var state = {
    list: null,        // his purchases, newest first
    more: false,
    loadError: null,
    loadingMore: false,
    suppliers: null,
    detail: {},        // id -> purchase
    detailError: null,
    busy: false,
    error: null,
    photos: [],        // the draft's photos: {blob, name, url} — on the phone only
    receive: null,     // {id, photos, error, saving}
  };

  function signedIn() { return !!S.token('purchase'); }

  function api(path, opts) {
    opts = opts || {};
    opts.token = S.token('purchase');
    return S.request(API_BASE + path, opts);
  }

  // --- Money and days ------------------------------------------------------

  function money(n) {
    return 'AED ' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function day(ymd) {
    if (!ymd) return '';
    var p = String(ymd).split('-');
    return Number(p[2]) + ' ' + MONTHS[Number(p[1]) - 1] + ' ' + p[0];
  }

  function num(v) {
    var s = String(v === null || v === undefined ? '' : v).replace(/,/g, '.').trim();
    return s === '' || isNaN(Number(s)) ? NaN : Number(s);
  }

  function qtyText(n) { return String(Math.round(n * 1000) / 1000); }

  // Unit is optional and any text; these are only suggestions.
  var UNITS = ['pc', 'kg', 'litre', 'box', 'bag', 'roll', 'm', 'set'];
  function unitsList() {
    return '<datalist id="pur-units">' + UNITS.map(function (u) { return '<option value="' + esc(u) + '">'; }).join('') + '</datalist>';
  }

  function lineQty(qty, unit) { return qtyText(qty) + (unit ? ' ' + unit : ''); }

  function emptyLine() { return { name: '', qty: '', unit: '', price: '', note: '' }; }

  // -------------------------------------------------------------------------
  // The draft — what is being typed, kept on the phone
  // -------------------------------------------------------------------------

  function draft() { return S.readJson(DRAFT_KEY, null); }

  function saveDraft(d) { S.writeJson(DRAFT_KEY, d); }

  function startDraft(kind) {
    clearPhotos();
    saveDraft({ ref: S.uid(), id: null, kind: kind, step: 'shop', supplier: '', supplierId: null, lines: [emptyLine()], notes: '', outsource: false, outsourceNote: '' });
    state.error = null;
    S.go({ app: 'purchase', view: 'new' });
    loadSuppliers();
  }

  /** Change a saved one: the same screens, filled in, without the photo step. */
  function editDraft(p) {
    clearPhotos();
    saveDraft({
      ref: S.uid(), id: p.id, kind: p.kind, step: 'shop', supplier: p.supplier.name, supplierId: p.supplier.id,
      lines: p.lines.map(function (l) { return { name: l.name, qty: qtyText(l.qty), price: String(l.price), unit: l.unit || '', note: l.note || '' }; }),
      notes: p.notes || '',
      outsource: !!p.outsource, outsourceNote: p.outsource ? p.outsource.note : '',
    });
    state.error = null;
    S.go({ app: 'purchase', view: 'new' });
    loadSuppliers();
  }

  function dropDraft() {
    clearPhotos();
    saveDraft(null);
  }

  function clearPhotos() {
    state.photos.forEach(function (p) { URL.revokeObjectURL(p.url); });
    state.photos = [];
  }

  function steps(d) {
    return d.id ? ['shop', 'items', 'check'] : ['shop', 'items', 'photo', 'check'];
  }

  function draftTotal(d) {
    return d.lines.reduce(function (sum, l) {
      var q = num(l.qty), p = num(l.price);
      return sum + (q > 0 && p >= 0 ? Math.round(q * p * 100) / 100 : 0);
    }, 0);
  }

  /** What stops this step, or null. */
  function stepProblem(d, step) {
    if (step === 'shop' && !String(d.supplier).trim()) return T.need.shop;
    if (step === 'items') {
      for (var i = 0; i < d.lines.length; i++) {
        var l = d.lines[i];
        var name = String(l.name).trim();
        if (!name) return T.need.itemName(i + 1);
        if (!(num(l.qty) > 0)) return T.need.qty(name);
        if (!(num(l.price) >= 0)) return T.need.price(name);
      }
    }
    if (step === 'photo' && d.kind === 'cash' && !state.photos.length) return T.need.bill;
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

  function load() {
    return api('/purchases').then(function (r) {
      state.list = r.purchases;
      state.more = r.more;
      state.loadError = null;
      r.purchases.forEach(function (p) { state.detail[p.id] = p; });
    }, function (e) {
      state.loadError = e.message || T.genericBody;
    }).then(S.render);
  }

  function loadMore() {
    if (!state.list || !state.list.length || state.loadingMore) return;
    state.loadingMore = true;
    S.render();
    api('/purchases?before=' + state.list[state.list.length - 1].id).then(function (r) {
      state.list = state.list.concat(r.purchases);
      state.more = r.more;
      r.purchases.forEach(function (p) { state.detail[p.id] = p; });
    }, function (e) { S.toast(e.message || T.genericBody); }).then(function () {
      state.loadingMore = false;
      S.render();
    });
  }

  function loadSuppliers() {
    api('/suppliers').then(function (r) { state.suppliers = r.suppliers; S.render(); }, function () { state.suppliers = []; S.render(); });
  }

  var loadingOne = {};
  function loadOne(id) {
    if (loadingOne[id]) return;
    loadingOne[id] = true;
    state.detailError = null;
    api('/purchases/' + id).then(function (p) {
      delete loadingOne[id];
      remember(p);
    }, function (e) {
      delete loadingOne[id];
      state.detailError = e.message || T.genericBody;
      S.render();
    });
  }

  /** A purchase as the server now has it: into the detail cache and the list. */
  function remember(p) {
    state.detail[p.id] = p;
    if (state.list) {
      var at = state.list.findIndex(function (x) { return x.id === p.id; });
      if (at === -1) state.list.unshift(p);
      else state.list[at] = p;
    }
    S.render();
  }

  function forget(id) {
    delete state.detail[id];
    if (state.list) state.list = state.list.filter(function (x) { return x.id !== id; });
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
      supplier: String(d.supplier).trim(),
      supplier_id: d.supplierId || undefined,
      lines: d.lines.map(function (l) { return { name: l.name, qty: num(l.qty), price: num(l.price), unit: l.unit || '', note: l.note || '' }; }),
      notes: d.notes,
      outsource: !!d.outsource,
      outsource_note: d.outsource ? d.outsourceNote || '' : '',
      client_ref: d.ref,
    };
    var req;
    if (d.id) {
      req = api('/purchases/' + d.id, { method: 'PUT', json: body });
    } else {
      var form = new FormData();
      form.append('data', JSON.stringify(body));
      state.photos.forEach(function (p) { form.append('photos[]', p.blob, p.name); });
      req = api('/purchases', { method: 'POST', form: form, timeout: 90000 });
    }
    state.busy = true;
    state.error = null;
    S.render();
    req.then(function (p) {
      state.busy = false;
      dropDraft();
      remember(p);
      S.toast(T.detail.saved);
      S.go({ app: 'purchase', view: 'detail', id: p.id }, true);
    }, function (e) {
      state.busy = false;
      state.error = e.message || T.genericBody;
      S.render();
    });
  }

  // --- Photos --------------------------------------------------------------

  /** Pick or take photos, shrink them, hand them to `done(list)`. */
  function takePhotos(fromCamera, have, done) {
    if (have >= MAX_PHOTOS) { S.toast(T.need.maxPhotos); return; }
    S.pickFiles('image/*', fromCamera, !fromCamera).then(function (files) {
      files = files.slice(0, MAX_PHOTOS - have);
      return Promise.all(files.map(function (f) { return S.preparePhoto(f, T.need.notPhoto); }));
    }).then(function (list) {
      done(list.map(function (p) { return { blob: p.blob, name: p.name, url: URL.createObjectURL(p.blob) }; }));
    }, function (e) { S.toast(e.message || T.need.notPhoto); });
  }

  function photoUrl(pid, f) {
    return S.media('pur-' + pid + '-' + f.id, function () {
      return api('/purchases/' + pid + '/photos/' + f.id, { raw: true, timeout: 60000 });
    });
  }

  function openPhoto(pid, fid) {
    var p = state.detail[pid];
    var f = p && p.files.filter(function (x) { return x.id === fid; })[0];
    if (!f) return;
    var url = photoUrl(pid, f);
    if (!url) { S.toast('Loading…'); return; }
    if (f.mime === 'application/pdf') { window.open(url, '_blank'); return; }
    S.openViewer('<img src="' + esc(url) + '" alt="">');
  }

  function uploadPhotos(pid, kind, list) {
    var form = new FormData();
    form.append('data', JSON.stringify({ kind: kind }));
    list.forEach(function (p) { form.append('photos[]', p.blob, p.name); URL.revokeObjectURL(p.url); });
    state.busy = true;
    S.render();
    api('/purchases/' + pid + '/photos', { method: 'POST', form: form, timeout: 90000 }).then(function (p) {
      state.busy = false;
      remember(p);
    }, function (e) {
      state.busy = false;
      S.toast(e.message || T.genericBody);
      S.render();
    });
  }

  function receive() {
    var r = state.receive;
    if (!r || r.saving) return;
    if (!r.photos.length) { r.error = T.need.delivery; S.render(); return; }
    var form = new FormData();
    r.photos.forEach(function (p) { form.append('photos[]', p.blob, p.name); });
    r.saving = true;
    r.error = null;
    S.render();
    api('/purchases/' + r.id + '/received', { method: 'POST', form: form, timeout: 90000 }).then(function (p) {
      r.photos.forEach(function (x) { URL.revokeObjectURL(x.url); });
      state.receive = null;
      remember(p);
      S.toast(T.receive.done);
      S.go({ app: 'purchase', view: 'detail', id: p.id }, true);
    }, function (e) {
      r.saving = false;
      r.error = e.message || T.genericBody;
      S.render();
    });
  }

  // -------------------------------------------------------------------------
  // Screens
  // -------------------------------------------------------------------------

  function statusPill(p) {
    var color = p.status === 'ordered' ? 'background:#FEF3C7;color:#92400E' : p.status === 'received' ? 'background:#D1FAE5;color:#065F46' : 'background:var(--cream-200);color:var(--navy-700)';
    return '<span class="pill" style="' + color + '">' + esc(T.status[p.status] || p.status) + '</span>';
  }

  function purchaseCard(p) {
    return '<button type="button" class="card row" data-key="p' + p.id + '" data-action="purchase:open" data-id="' + p.id + '">' +
      '<span class="pur-icon">' + icon(p.kind === 'cash' ? 'cash' : 'truck', 'lg') + '</span>' +
      '<span class="grow"><span class="h3 ellipsis" style="display:block">' + esc(p.supplier.name) + '</span>' +
      '<span class="secondary" style="display:block">' + esc(day(p.bought_on) + ' · ' + T.items(p.lines.length) + (p.outsource ? ' · ' + T.detail.outsource : '')) + '</span></span>' +
      '<span style="text-align:right"><span class="h3" style="display:block;white-space:nowrap">' + esc(money(p.total)) + '</span>' + statusPill(p) + '</span>' +
      '</button>';
  }

  function renderHome() {
    var s = S.session();
    var body = S.installCard();
    var d = draft();

    if (d) {
      body += '<div class="card info"><div class="row">' + icon('alert') + '<div class="grow"><div class="h3">' + esc(T.draftTitle) + '</div>' +
        '<div class="small secondary mt-1">' + esc(T.draftBody(String(d.supplier || '').trim())) + '</div></div></div>' +
        '<div class="action-row mt-3">' + button(T.draftDrop, 'purchase:draft-drop', { variant: 'plain', style: 'flex:2' }) +
        button(T.draftGo, 'purchase:draft-go', { style: 'flex:3' }) + '</div></div>';
    }

    body += '<h2 class="h3 section-title mt-3">' + esc(T.newTitle) + '</h2>' +
      '<button type="button" class="app-tile" data-action="purchase:new" data-kind="cash">' +
      '<span class="app-icon">' + icon('cash', 'xl') + '</span>' +
      '<span class="grow"><span class="h2" style="display:block">' + esc(T.cash) + '</span><span class="secondary" style="display:block">' + esc(T.cashHint) + '</span></span>' +
      icon('chevron') + '</button>' +
      '<button type="button" class="app-tile" data-action="purchase:new" data-kind="order">' +
      '<span class="app-icon">' + icon('truck', 'xl') + '</span>' +
      '<span class="grow"><span class="h2" style="display:block">' + esc(T.order) + '</span><span class="secondary" style="display:block">' + esc(T.orderHint) + '</span></span>' +
      icon('chevron') + '</button>';

    if (state.list === null && !state.loadError) {
      body += '<div class="skel mt-4" style="height:76px"></div><div class="skel" style="height:76px"></div>';
    }
    if (state.loadError) {
      body += '<div class="card mt-4"><div class="body-lg">' + esc(state.loadError) + '</div><div class="mt-3">' +
        button(T.retry, 'purchase:reload', { variant: 'secondary' }) + '</div></div>';
    }
    if (state.list) {
      var waiting = state.list.filter(function (p) { return p.status === 'ordered'; });
      if (waiting.length) {
        body += '<h2 class="h3 section-title mt-5">' + esc(T.waitingTitle) + '</h2>';
        waiting.forEach(function (p) {
          body += '<div class="card row" data-key="w' + p.id + '">' +
            '<span class="pur-icon">' + icon('truck', 'lg') + '</span>' +
            '<button type="button" class="grow" style="text-align:left" data-action="purchase:open" data-id="' + p.id + '">' +
            '<span class="h3 ellipsis" style="display:block">' + esc(p.supplier.name) + '</span>' +
            '<span class="secondary" style="display:block">' + esc(day(p.bought_on) + ' · ' + money(p.total)) + '</span></button>' +
            button(T.arrived, 'purchase:receive', { variant: 'auto', data: 'data-id="' + p.id + '"' }) +
            '</div>';
        });
      }
      body += '<h2 class="h3 section-title mt-5">' + esc(T.mineTitle) + '</h2>';
      if (!state.list.length) body += '<div class="card"><div class="body-lg secondary">' + esc(T.none) + '</div></div>';
      state.list.forEach(function (p) { body += purchaseCard(p); });
      if (state.more) body += '<div class="mt-3">' + button(T.more, 'purchase:more', { variant: 'secondary', loading: state.loadingMore }) + '</div>';
    }

    return '<main class="screen">' +
      S.topHeader(T.title, T.greeting(s ? String(s.user.name).trim().split(/\s+/)[0] : '')) +
      '<div class="screen-body">' + body + '</div>' +
      '</main>';
  }

  function stepTop(d) {
    var list = steps(d);
    var n = list.indexOf(d.step) + 1;
    return '<div class="topbar"><button type="button" class="back-btn" data-action="purchase:step-back">' + icon('back') + esc(T.step.back) + '</button>' +
      '<span class="qcount">' + n + ' / ' + list.length + '</span></div>' +
      '<div class="qprogress"><span style="width:' + Math.round((n / list.length) * 100) + '%"></span></div>';
  }

  function photoTools(prefix, photos) {
    var thumbs = photos.map(function (p, i) {
      return '<div class="qthumb" data-key="ph' + i + '"><img src="' + esc(p.url) + '" alt="">' +
        '<button type="button" class="remove" data-action="purchase:' + prefix + '-photo-del" data-i="' + i + '" aria-label="Delete">' + icon('close') + '</button></div>';
    }).join('');
    return '<div class="qtools">' +
      '<button type="button" class="qtool" data-action="purchase:' + prefix + '-photo" data-camera="1">' + icon('camera', 'lg') + '<span>' + esc(T.step.camera) + '</span></button>' +
      '<button type="button" class="qtool" data-action="purchase:' + prefix + '-photo">' + icon('gallery', 'lg') + '<span>' + esc(T.step.gallery) + '</span></button>' +
      '</div>' + (thumbs ? '<div class="qthumbs">' + thumbs + '</div>' : '');
  }

  function renderForm() {
    var d = draft();
    if (!d) { S.go({ app: 'purchase' }, true); return renderHome(); }
    var body;
    var footer = button(T.step.next, 'purchase:step-next', { icon: 'chevron' });

    if (d.step === 'shop') {
      var typed = String(d.supplier || '').trim().toLowerCase();
      var picks = (state.suppliers || []).filter(function (s) { return !typed || s.name.toLowerCase().indexOf(typed) !== -1; }).slice(0, 8);
      body = '<div class="qcard"><div class="qicon">' + icon(d.kind === 'cash' ? 'store' : 'truck', 'huge') + '</div>' +
        '<div class="qtext">' + esc(d.kind === 'cash' ? T.step.shopCash : T.step.shopOrder) + '</div></div>' +
        '<input class="field mt-3" id="pur-supplier" type="text" autocomplete="off" maxlength="80" placeholder="' +
        esc(d.kind === 'cash' ? T.step.shopPlaceholder : T.step.supplierPlaceholder) + '" value="' + esc(d.supplier) + '">' +
        (picks.length ? '<div class="small muted mt-3">' + esc(T.step.used) + '</div>' : '') +
        picks.map(function (s) {
          var on = d.supplierId === s.id;
          return '<button type="button" class="card vehicle' + (on ? ' selected' : '') + '" data-key="s' + s.id + '" data-action="purchase:pick-supplier" data-id="' + s.id + '">' +
            '<span class="veh-icon">' + icon('store') + '</span><span class="grow h3">' + esc(s.name) + '</span>' +
            (on ? '<span class="check">' + icon('check') + '</span>' : '') + '</button>';
        }).join('');
    } else if (d.step === 'items') {
      body = '<h1 class="h2 mt-3">' + esc(d.kind === 'cash' ? T.step.itemsTitle : T.step.itemsTitleOrder) + '</h1>' +
        '<div class="small muted">' + esc(d.supplier) + '</div>' +
        d.lines.map(function (l, i) {
          var q = num(l.qty), p = num(l.price);
          var amount = q > 0 && p >= 0 ? money(Math.round(q * p * 100) / 100) : '';
          return '<div class="card pur-line" data-key="l' + i + '">' +
            '<div class="row"><input class="field grow" data-line="' + i + '" data-field="name" type="text" maxlength="120" autocomplete="off" placeholder="' + esc(T.step.itemName) + '" value="' + esc(l.name) + '">' +
            (d.lines.length > 1 ? '<button type="button" class="icon-btn" data-action="purchase:line-del" data-i="' + i + '" aria-label="' + esc(T.step.remove) + '">' + icon('trash') + '</button>' : '') + '</div>' +
            '<div class="pur-nums pur-nums3 mt-2">' +
            '<label><span class="small muted">' + esc(T.step.qty) + '</span><input class="field" data-line="' + i + '" data-field="qty" type="text" inputmode="decimal" autocomplete="off" placeholder="1" value="' + esc(l.qty) + '"></label>' +
            '<label><span class="small muted">' + esc(T.step.unit) + '</span><input class="field" data-line="' + i + '" data-field="unit" type="text" list="pur-units" maxlength="20" autocomplete="off" placeholder="pc" value="' + esc(l.unit || '') + '"></label>' +
            '<label><span class="small muted">' + esc(T.step.price) + ' (AED)</span><input class="field" data-line="' + i + '" data-field="price" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00" value="' + esc(l.price) + '"></label>' +
            '</div>' +
            '<input class="field mt-2 pur-note" data-line="' + i + '" data-field="note" type="text" maxlength="160" autocomplete="off" placeholder="' + esc(T.step.lineNote) + '" value="' + esc(l.note || '') + '">' +
            '<div class="secondary mt-2" style="text-align:right">' + esc(amount) + '</div></div>';
        }).join('') + unitsList() +
        '<div class="mt-3">' + button(T.step.add, 'purchase:line-add', { variant: 'secondary', icon: 'plus' }) + '</div>' +
        '<div class="trip-card mt-4 row between"><span class="label gold">' + esc(T.step.total) + '</span><span class="h2">' + esc(money(draftTotal(d))) + '</span></div>';
    } else if (d.step === 'photo') {
      body = '<div class="qcard"><div class="qicon">' + icon('receipt', 'huge') + '</div>' +
        '<div class="qtext">' + esc(d.kind === 'cash' ? T.step.photoCash : T.step.photoOrder) + '</div>' +
        (d.kind === 'order' ? '<div class="secondary mt-2">' + esc(T.step.photoOrderHint) + '</div>' : '') + '</div>' +
        photoTools('draft', state.photos);
    } else {
      body = '<h1 class="h2 mt-3">' + esc(T.step.checkTitle) + '</h1>' +
        '<div class="trip-card mt-3"><div class="label gold">' + esc(d.kind === 'cash' ? T.cash : T.order) + '</div>' +
        '<div class="h1">' + esc(d.supplier) + '</div><div class="h2 mt-2">' + esc(money(draftTotal(d))) + '</div></div>' +
        '<div class="card">' + d.lines.map(function (l) {
          return '<div class="kv pur-kv"><span>' + esc(lineQty(num(l.qty), String(l.unit || '').trim()) + ' × ' + l.name) +
            (String(l.note || '').trim() ? '<span class="small muted" style="display:block">' + esc(l.note) + '</span>' : '') + '</span>' +
            '<span>' + esc(money(Math.round(num(l.qty) * num(l.price) * 100) / 100)) + '</span></div>';
        }).join('') + '</div>' +
        '<button type="button" class="card vehicle mt-3' + (d.outsource ? ' selected' : '') + '" data-action="purchase:outsource">' +
        '<span class="veh-icon">' + icon('truck') + '</span><span class="grow"><span class="h3" style="display:block">' + esc(T.step.outsource) + '</span>' +
        '<span class="small muted">' + esc(T.step.outsourceHint) + '</span></span>' +
        (d.outsource ? '<span class="check">' + icon('check') + '</span>' : '') + '</button>' +
        (d.outsource ? '<input class="field mt-2" id="pur-outsource" type="text" maxlength="160" autocomplete="off" placeholder="' + esc(T.step.outsourceNote) + '" value="' + esc(d.outsourceNote || '') + '">' : '') +
        (d.id ? '' : '<div class="card kv"><span class="secondary">' + esc(T.detail.photos) + '</span><span>' +
          esc(state.photos.length ? T.step.photos(state.photos.length) : T.step.noPhoto) + '</span></div>') +
        '<textarea class="field mt-3" id="pur-notes" rows="2" maxlength="500" placeholder="' + esc(T.step.notes) + '">' + esc(d.notes) + '</textarea>';
      footer = button(state.busy ? T.step.saving : d.id ? T.step.saveChanges : T.step.save, 'purchase:save', { loading: state.busy });
    }

    return '<main class="screen qscreen">' + stepTop(d) +
      '<div class="screen-body">' + body + (state.error ? '<div class="card alert">' + esc(state.error) + '</div>' : '') + '</div>' +
      '<div class="footer">' + footer + '</div></main>';
  }

  function fileTiles(p, kind) {
    return p.files.filter(function (f) { return f.kind === kind; }).map(function (f) {
      var pdf = f.mime === 'application/pdf';
      var url = pdf ? null : photoUrl(p.id, f);
      var mayDelete = p.can_change || (kind === 'delivery' && p.received_on === today());
      return '<div class="qthumb" data-key="f' + f.id + '">' +
        '<button type="button" class="thumb" style="width:88px;height:88px" data-action="purchase:view-photo" data-id="' + p.id + '" data-file="' + f.id + '">' +
        (pdf ? icon('file', 'lg') : url ? '<img src="' + esc(url) + '" alt="">' : '<span class="spinner"></span>') + '</button>' +
        (mayDelete ? '<button type="button" class="remove" data-action="purchase:file-del" data-id="' + p.id + '" data-file="' + f.id + '" aria-label="Delete">' + icon('close') + '</button>' : '') +
        '</div>';
    }).join('');
  }

  function today() {
    var d = new Date();
    return d.getFullYear() + '-' + S.pad(d.getMonth() + 1) + '-' + S.pad(d.getDate());
  }

  function renderDetail(route) {
    var p = state.detail[route.id];
    var top = S.backBar('purchase:back', T.step.back);
    if (!p) {
      if (!state.detailError) loadOne(route.id);
      return '<main class="screen">' + top + '<div class="screen-body">' +
        (state.detailError ? '<div class="card"><div class="body-lg">' + esc(state.detailError) + '</div></div>' : '<div class="skel mt-3" style="height:160px"></div>') +
        '</div></main>';
    }
    var bills = fileTiles(p, 'bill');
    var notes = fileTiles(p, 'delivery');
    var addable = p.can_change || (p.status === 'received' && p.received_on === today());
    var body = '<div class="trip-card mt-2"><div class="label gold">' + esc(p.kind === 'cash' ? T.cash : T.order) + '</div>' +
      '<div class="h1">' + esc(p.supplier.name) + '</div>' +
      '<div class="h2 mt-2">' + esc(money(p.total)) + '</div>' +
      '<div class="mt-2">' + statusPill(p) + '</div></div>' +
      '<div class="card">' + p.lines.map(function (l) {
        return '<div class="kv pur-kv"><span>' + esc(lineQty(l.qty, l.unit) + ' × ' + l.name) +
          '<span class="small muted" style="display:block">' + esc(money(l.price) + (l.note ? ' · ' + l.note : '')) + '</span></span><span>' + esc(money(l.amount)) + '</span></div>';
      }).join('') + '</div>' +
      '<div class="card"><div class="kv"><span class="secondary">' + esc(T.detail.bought) + '</span><span>' + esc(day(p.bought_on)) + '</span></div>' +
      (p.received_on ? '<div class="kv mt-2"><span class="secondary">' + esc(T.detail.received) + '</span><span>' + esc(day(p.received_on)) + '</span></div>' : '') +
      (p.outsource ? '<div class="kv mt-2"><span class="secondary">' + esc(T.detail.outsource) + '</span><span>' + esc(p.outsource.note || 'Yes') + '</span></div>' : '') +
      (p.notes ? '<div class="mt-2"><span class="secondary">' + esc(T.detail.notes) + '</span><div class="body-lg">' + esc(p.notes) + '</div></div>' : '') +
      '</div>';

    if (bills || p.can_change) {
      body += '<h2 class="h3 section-title mt-4">' + esc(T.detail.bill) + '</h2>' + (bills ? '<div class="qthumbs">' + bills + '</div>' : '') +
        (p.can_change ? '<div class="mt-3">' + button(T.detail.addPhoto, 'purchase:file-add', { variant: 'secondary', icon: 'camera', data: 'data-id="' + p.id + '" data-kind="bill"', loading: state.busy }) + '</div>' : '');
    }
    if (notes || (p.status === 'received' && addable)) {
      body += '<h2 class="h3 section-title mt-4">' + esc(T.detail.delivery) + '</h2>' + (notes ? '<div class="qthumbs">' + notes + '</div>' : '') +
        (p.status === 'received' && addable ? '<div class="mt-3">' + button(T.detail.addPhoto, 'purchase:file-add', { variant: 'secondary', icon: 'camera', data: 'data-id="' + p.id + '" data-kind="delivery"', loading: state.busy }) + '</div>' : '');
    }
    if (!p.can_change) body += '<div class="card info mt-4"><div class="small">' + esc(T.detail.locked) + '</div></div>';

    var footer = '';
    if (p.status === 'ordered') footer += button(T.detail.arrived, 'purchase:receive', { icon: 'truck', data: 'data-id="' + p.id + '"' });
    if (p.can_change) {
      footer += '<div class="action-row' + (footer ? ' mt-2' : '') + '">' +
        button(T.detail.delete, 'purchase:delete', { variant: 'plain', style: 'flex:2', data: 'data-id="' + p.id + '"' }) +
        button(T.detail.change, 'purchase:edit', { variant: 'secondary', style: 'flex:3', icon: 'edit', data: 'data-id="' + p.id + '"' }) + '</div>';
    }
    return '<main class="screen">' + top + '<div class="screen-body">' + body + '</div>' +
      (footer ? '<div class="footer">' + footer + '</div>' : '') + '</main>';
  }

  function renderReceive(route) {
    var p = state.detail[route.id];
    if (!state.receive || state.receive.id !== route.id) state.receive = { id: route.id, photos: [], error: null, saving: false };
    var r = state.receive;
    var body = '<div class="qcard"><div class="qicon">' + icon('truck', 'huge') + '</div>' +
      '<div class="qtext">' + esc(T.receive.title) + '</div>' +
      (p ? '<div class="secondary mt-2">' + esc(p.supplier.name + ' · ' + T.items(p.lines.length) + ' · ' + money(p.total)) + '</div>' : '') +
      '<div class="body-lg mt-3">' + esc(T.receive.hint) + '</div></div>' +
      photoTools('receive', r.photos);
    return '<main class="screen qscreen">' + S.backBar('purchase:back', T.step.back) +
      '<div class="screen-body">' + body + (r.error ? '<div class="card alert">' + esc(r.error) + '</div>' : '') + '</div>' +
      '<div class="footer">' + button(r.saving ? T.step.saving : T.receive.save, 'purchase:receive-save', { loading: r.saving, disabled: !r.photos.length }) + '</div></main>';
  }

  function render(route) {
    if (route.view === 'new') return renderForm();
    if (route.view === 'detail') return renderDetail(route);
    if (route.view === 'receive') return renderReceive(route);
    if (state.list === null && !state.loadError && signedIn() && !load.pending) {
      load.pending = true;
      load().then(function () { load.pending = false; });
    }
    return renderHome();
  }

  // -------------------------------------------------------------------------
  // Taps and typing
  // -------------------------------------------------------------------------

  function action(name, el) {
    var id = Number(el.getAttribute('data-id'));
    var d;
    switch (name) {
      case 'new': startDraft(el.getAttribute('data-kind')); break;
      case 'draft-go': state.error = null; S.go({ app: 'purchase', view: 'new' }); loadSuppliers(); break;
      case 'draft-drop': dropDraft(); S.render(); break;
      case 'reload': state.loadError = null; state.list = null; S.render(); break;
      case 'more': loadMore(); break;
      case 'open': state.detailError = null; S.go({ app: 'purchase', view: 'detail', id: id }); break;
      case 'back': S.back(); break;
      case 'step-back': moveStep(-1); break;
      case 'step-next': moveStep(1); break;
      case 'pick-supplier': {
        d = draft();
        var s = (state.suppliers || []).filter(function (x) { return x.id === id; })[0];
        if (!d || !s) break;
        d.supplier = s.name;
        d.supplierId = s.id;
        saveDraft(d);
        state.error = null;
        moveStep(1);
        break;
      }
      case 'line-add':
        d = draft();
        d.lines.push(emptyLine());
        saveDraft(d);
        S.render();
        setTimeout(function () {
          var el2 = document.querySelector('[data-line="' + (d.lines.length - 1) + '"][data-field="name"]');
          if (el2) el2.focus();
        }, 50);
        break;
      case 'line-del':
        d = draft();
        d.lines.splice(Number(el.getAttribute('data-i')), 1);
        saveDraft(d);
        S.render();
        break;
      case 'draft-photo':
        takePhotos(el.hasAttribute('data-camera'), state.photos.length, function (list) {
          state.photos = state.photos.concat(list);
          state.error = null;
          S.render();
        });
        break;
      case 'draft-photo-del': {
        var gone = state.photos.splice(Number(el.getAttribute('data-i')), 1)[0];
        if (gone) URL.revokeObjectURL(gone.url);
        S.render();
        break;
      }
      case 'outsource':
        d = draft();
        if (!d) break;
        d.outsource = !d.outsource;
        saveDraft(d);
        S.render();
        if (d.outsource) setTimeout(function () { var o = document.getElementById('pur-outsource'); if (o) o.focus(); }, 50);
        break;
      case 'save': save(); break;
      case 'receive': state.receive = null; S.go({ app: 'purchase', view: 'receive', id: id }); break;
      case 'receive-photo':
        takePhotos(el.hasAttribute('data-camera'), state.receive.photos.length, function (list) {
          state.receive.photos = state.receive.photos.concat(list);
          state.receive.error = null;
          S.render();
        });
        break;
      case 'receive-photo-del': {
        var g = state.receive.photos.splice(Number(el.getAttribute('data-i')), 1)[0];
        if (g) URL.revokeObjectURL(g.url);
        S.render();
        break;
      }
      case 'receive-save': receive(); break;
      case 'view-photo': openPhoto(id, Number(el.getAttribute('data-file'))); break;
      case 'file-add': {
        var p = state.detail[id];
        var kind = el.getAttribute('data-kind');
        takePhotos(true, p ? p.files.length : 0, function (list) { uploadPhotos(id, kind, list); });
        break;
      }
      case 'file-del': {
        var fid = Number(el.getAttribute('data-file'));
        S.sheet({
          title: T.detail.photoDeleteSheet.title,
          confirm: T.detail.photoDeleteSheet.confirm,
          cancel: T.detail.photoDeleteSheet.cancel,
          danger: true,
          onConfirm: function () {
            api('/purchases/' + id + '/photos/' + fid, { method: 'DELETE' }).then(function (pp) {
              S.mediaForget('pur-' + id + '-' + fid);
              remember(pp);
            }, function (e) { S.toast(e.message || T.genericBody); });
          },
        });
        break;
      }
      case 'edit': if (state.detail[id]) editDraft(state.detail[id]); break;
      case 'delete':
        S.sheet({
          title: T.detail.deleteSheet.title,
          body: T.detail.deleteSheet.body,
          confirm: T.detail.deleteSheet.confirm,
          cancel: T.detail.deleteSheet.cancel,
          danger: true,
          onConfirm: function () {
            api('/purchases/' + id, { method: 'DELETE' }).then(function () {
              forget(id);
              S.toast(T.detail.deleted);
              S.go({ app: 'purchase' }, true);
            }, function (e) { S.toast(e.message || T.genericBody); });
          },
        });
        break;
    }
  }

  function input(e) {
    var t = e.target;
    var d = S.state.route.app === 'purchase' && S.state.route.view === 'new' ? draft() : null;
    if (!d) return;
    if (t.id === 'pur-supplier') {
      d.supplier = t.value;
      d.supplierId = null; // typed: matched by name on the server
    } else if (t.id === 'pur-notes') {
      d.notes = t.value;
    } else if (t.id === 'pur-outsource') {
      d.outsourceNote = t.value;
    } else if (t.hasAttribute('data-line')) {
      var l = d.lines[Number(t.getAttribute('data-line'))];
      if (!l) return;
      l[t.getAttribute('data-field')] = t.value;
    } else {
      return;
    }
    state.error = null;
    saveDraft(d);
    S.render();
  }

  S.register('purchase', {
    render: render,
    action: action,
    input: input,
    start: function () { if (signedIn()) { state.list = null; state.loadError = null; } },
    beforeSignOut: function () {
      dropDraft();
      state.list = null;
      state.detail = {};
      state.suppliers = null;
      state.receive = null;
      return Promise.resolve();
    },
    tileStatus: function () {
      if (draft()) return T.draftTitle;
      var waiting = (state.list || []).filter(function (p) { return p.status === 'ordered'; }).length;
      return waiting ? waiting + ' ' + T.waitingTitle.toLowerCase() : null;
    },
  });
})();
