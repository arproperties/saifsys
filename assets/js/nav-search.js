/**
 * Search for a module's left-side menu.
 *
 * includes/nav_search.php prints the box inside a sidebar, just above the first
 * menu link. Every link after the box is searchable; whatever comes before it
 * (logo, company picker) is left alone.
 *
 * The sidebars are not built alike — Bootstrap collapse groups in Real Estate
 * and Construction, <details> groups in the ARS shell, flat lists under plain
 * headings everywhere else — so nothing here leans on one module's class names.
 * It works from the links themselves and from the text heading each group,
 * which is also what lets "accounting" find everything filed under Accounting.
 *
 *   /           focus the box from anywhere on the page
 *   Enter       open the best match
 *   Arrow keys  move through the matches
 *   Esc         clear, then leave the box
 */
(function () {
  'use strict';

  var HIDE = 'ns-hide';
  var boxes = [];

  function norm(s) {
    return String(s || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
  }

  function isVisible(el) {
    if (!(el.offsetWidth || el.offsetHeight || el.getClientRects().length)) return false;
    // An off-canvas mobile menu is laid out but pushed off the left edge.
    return el.getBoundingClientRect().right > 0;
  }

  function isTyping(el) {
    return !!el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));
  }

  function init(box) {
    if (box.getAttribute('data-nav-search') === 'ready') return;
    box.setAttribute('data-nav-search', 'ready');

    var root = box.closest('aside, .sidebar') || box.parentElement;
    var input = box.querySelector('.ns-input');
    var status = box.querySelector('.ns-empty');
    if (!root || !input) return;

    function after(el) {
      return el !== box && !box.contains(el) &&
        !!(box.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING);
    }

    function holdsLinks(el) {
      return el.tagName !== 'A' && !!el.querySelector('a[href]');
    }

    // A group heading is anything after the box that carries text but no links:
    // .nav-sect, a collapse toggle, a <summary>, a small uppercase label. A
    // disabled "coming soon" item also has text and no link, so it is ruled out.
    function isHeading(el) {
      return after(el) &&
        !el.matches('a, [aria-disabled="true"], script, style, form') &&
        !el.querySelector('a[href], [aria-disabled="true"]') &&
        norm(el.textContent) !== '';
    }

    // The headings a link is filed under, nearest first: at each level up the
    // tree, the closest earlier sibling that is a heading. Sibling groups of
    // links are stepped over, so "Period Management" in Real Estate lands under
    // "Setup & Controls" and not under the Bank Reconciliations group above it.
    function headingsFor(link) {
      var found = [];
      for (var node = link; node && node !== root; node = node.parentElement) {
        for (var sib = node.previousElementSibling; sib; sib = sib.previousElementSibling) {
          if (!holdsLinks(sib) && isHeading(sib)) {
            found.push(sib);
            break;
          }
        }
      }
      return found;
    }

    var items = [];
    root.querySelectorAll('a[href]').forEach(function (a) {
      if (!after(a)) return;
      var heads = headingsFor(a);
      items.push({
        el: a,
        label: norm(a.textContent),
        context: norm(heads.map(function (h) { return h.textContent; }).join(' ')),
        heads: heads,
        inLabel: false
      });
    });
    if (!items.length) {
      box.remove();
      return;
    }

    // What gets hidden while searching: the links, the headings, and every
    // wrapper that holds links. Nothing inside a link or a heading is touched,
    // so their icons and labels come back exactly as they were.
    var scope = [];
    root.querySelectorAll('*').forEach(function (el) {
      if (!after(el)) return;
      var p = el.parentElement;
      if (p === root || p.contains(box) || holdsLinks(p)) scope.push(el);
    });

    var hits = [];
    var searching = false;
    var undo = [];

    // A match inside a closed group is no use, so its group is opened for as
    // long as the search lasts and put back afterwards.
    function openGroup(el) {
      if (el.tagName === 'DETAILS') {
        if (!el.open) {
          el.open = true;
          undo.push(function () { el.open = false; });
        }
        return;
      }
      if (!el.classList.contains('collapse') || el.classList.contains('show')) return;
      el.classList.add('show');
      var toggles = el.id ? root.querySelectorAll('[data-bs-target="#' + CSS.escape(el.id) + '"]') : [];
      var was = [];
      toggles.forEach(function (t) {
        was.push(t.getAttribute('aria-expanded'));
        t.setAttribute('aria-expanded', 'true');
      });
      undo.push(function () {
        el.classList.remove('show');
        toggles.forEach(function (t, i) {
          if (was[i] === null) t.removeAttribute('aria-expanded');
          else t.setAttribute('aria-expanded', was[i]);
        });
      });
    }

    function filter() {
      var words = norm(input.value).split(' ').filter(Boolean);
      undo.forEach(function (fn) { fn(); });
      undo = [];
      hits = [];

      if (!words.length) {
        searching = false;
        scope.forEach(function (el) { el.classList.remove(HIDE); });
        status.textContent = '';
        return;
      }
      searching = true;

      var keep = new Set();
      function keepUp(el) {
        for (var n = el; n && n !== root && !keep.has(n); n = n.parentElement) keep.add(n);
      }

      items.forEach(function (it) {
        var hay = it.label + ' ' + it.context;
        if (!words.every(function (w) { return hay.indexOf(w) !== -1; })) return;
        it.inLabel = words.every(function (w) { return it.label.indexOf(w) !== -1; });
        hits.push(it);
        keepUp(it.el);
        it.heads.forEach(keepUp);
      });

      scope.forEach(function (el) { el.classList.toggle(HIDE, !keep.has(el)); });
      keep.forEach(openGroup);
      status.textContent = hits.length ? '' : 'Nothing in the menu matches.';
    }

    // Some links are laid out but never shown in a wide sidebar (the ARS
    // shell's collapsed-rail shortcuts), so matches are checked on screen.
    function shownLinks() {
      return hits.filter(function (it) { return isVisible(it.el); });
    }

    input.addEventListener('input', filter);

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        var shown = shownLinks();
        // A match on the link's own name beats one that only matched its group.
        var best = shown.filter(function (it) { return it.inLabel; })[0] || shown[0];
        if (best) {
          e.preventDefault();
          best.el.click();
        }
      } else if (e.key === 'ArrowDown') {
        var first = shownLinks()[0];
        if (first) {
          e.preventDefault();
          first.el.focus();
        }
      } else if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        if (input.value) {
          input.value = '';
          filter();
        } else {
          input.blur();
        }
      }
    });

    root.addEventListener('keydown', function (e) {
      if (!searching || !/^(ArrowDown|ArrowUp|Escape)$/.test(e.key)) return;
      var links = shownLinks().map(function (it) { return it.el; });
      var i = links.indexOf(e.target);
      if (i === -1) return;
      e.preventDefault();
      if (e.key === 'ArrowDown') {
        (links[i + 1] || links[i]).focus();
      } else if (e.key === 'ArrowUp') {
        (i > 0 ? links[i - 1] : input).focus();
      } else {
        e.stopPropagation();
        input.focus();
      }
    });

    // A sidebar folded down to its icon rail has no room to type in, and a
    // search left running would keep the rail's links hidden.
    function fit() {
      var narrow = root.clientWidth < 150;
      box.classList.toggle('ns-narrow', narrow);
      if (narrow && input.value) {
        input.value = '';
        filter();
      }
    }
    if (window.ResizeObserver) new ResizeObserver(fit).observe(root);
    fit();

    // The browser can put a typed value back when the user returns with Back.
    if (input.value) filter();

    boxes.push(input);
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented || isTyping(e.target)) return;
    for (var i = 0; i < boxes.length; i++) {
      if (isVisible(boxes[i])) {
        e.preventDefault();
        boxes[i].focus();
        boxes[i].select();
        return;
      }
    }
  });

  function start() {
    document.querySelectorAll('[data-nav-search]').forEach(init);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
