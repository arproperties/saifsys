/**
 * Click-to-sort table headers.
 *
 * includes/table_sort.php prints the stylesheet and this script; a table opts in
 * with data-sortable, and every header cell becomes a sort button unless it is
 * marked data-sort="none" (Actions columns and the like).
 *
 * Sorting happens in the browser, on the rows already on the page — the lists
 * that use it are capped well below a thousand rows, so there is no round trip
 * and no change to the queries or the paging.
 *
 * A cell's sort key is its data-sort-value when it has one, otherwise its text.
 * Give the attribute to anything whose text does not sort the way it reads:
 * money, dates written for people, "Overdue 3d", a status badge with an order.
 *
 * Column type comes from data-sort on the header (text, number, date) or, when
 * that is missing, from the values themselves. Blanks and unparseable numbers
 * always sink to the bottom, whichever direction is active.
 */
(function () {
  'use strict';

  var ARROWS = '<span class="tsort-ind" aria-hidden="true">'
    + '<svg viewBox="0 0 10 14" width="9" height="12" focusable="false">'
    + '<path class="tsort-up" d="M5 1.4 8.4 5.9H1.6z"></path>'
    + '<path class="tsort-dn" d="M5 12.6 1.6 8.1h6.8z"></path>'
    + '</svg></span>';

  function cellText(cell) {
    if (!cell) return '';
    var v = cell.getAttribute('data-sort-value');
    return (v !== null ? v : cell.textContent).replace(/\s+/g, ' ').trim();
  }

  // Money and plain numbers, with or without a currency word, separators or a
  // trailing per-cent sign. Deliberately strict: "ARS-26-00198" is not a number.
  var NUMBER = /^[-+(]?\s*(?:aed|usd|eur|gbp|rs\.?|[$€£₹])?\s*[\d,]*\.?\d+\s*%?\s*\)?$/i;
  var DATE = /^\d{4}-\d{2}-\d{2}(?:[ t]|$)/i;

  function toNumber(s) {
    var neg = /^\(.*\)$/.test(s) || s.charAt(0) === '-';
    var n = parseFloat(s.replace(/[^\d.]/g, ''));
    if (isNaN(n)) return NaN;
    return neg ? -n : n;
  }

  function detectType(values) {
    var seen = 0, nums = 0, dates = 0;
    for (var i = 0; i < values.length; i++) {
      var v = values[i];
      if (v === '' || v === '-' || v === '—') continue;
      seen++;
      if (DATE.test(v)) dates++;
      else if (NUMBER.test(v)) nums++;
    }
    if (!seen) return 'text';
    if (dates === seen) return 'date';
    if (nums === seen) return 'number';
    return 'text';
  }

  function keyOf(value, type) {
    if (value === '' || value === '-' || value === '—') return null;
    if (type === 'number') {
      var n = toNumber(value);
      return isNaN(n) ? null : n;
    }
    if (type === 'date') {
      var t = Date.parse(value.replace(' ', 'T'));
      return isNaN(t) ? null : t;
    }
    return value;
  }

  function sortableRows(tbody, columns) {
    var rows = [];
    for (var i = 0; i < tbody.rows.length; i++) {
      var row = tbody.rows[i];
      // Leaves "no results" and other full-width rows where they are.
      if (row.hasAttribute('data-no-sort') || row.cells.length < columns) continue;
      rows.push(row);
    }
    return rows;
  }

  function sortBy(table, th) {
    var head = th.parentNode;
    var index = Array.prototype.indexOf.call(head.cells, th);
    var tbody = table.tBodies[0];
    if (!tbody) return;

    var rows = sortableRows(tbody, head.cells.length);
    if (rows.length < 2) return;

    var values = rows.map(function (row) { return cellText(row.cells[index]); });
    var type = th.getAttribute('data-sort');
    if (!type || type === 'auto') type = detectType(values);

    var dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
    var collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

    var items = rows.map(function (row, i) {
      return { row: row, key: keyOf(values[i], type), at: i };
    });

    items.sort(function (a, b) {
      // Empty cells stay at the bottom in both directions.
      if (a.key === null || b.key === null) {
        if (a.key === b.key) return a.at - b.at;
        return a.key === null ? 1 : -1;
      }
      var cmp = type === 'text' ? collator.compare(a.key, b.key) : (a.key < b.key ? -1 : a.key > b.key ? 1 : 0);
      return cmp ? cmp * dir : a.at - b.at;
    });

    var frag = document.createDocumentFragment();
    items.forEach(function (item) { frag.appendChild(item.row); });
    tbody.appendChild(frag);

    for (var i = 0; i < head.cells.length; i++) head.cells[i].removeAttribute('aria-sort');
    th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
  }

  function init(table) {
    if (table.getAttribute('data-sortable') === 'ready') return;
    table.setAttribute('data-sortable', 'ready');

    var head = table.tHead && table.tHead.rows.length
      ? table.tHead.rows[table.tHead.rows.length - 1]
      : null;
    if (!head) return;

    Array.prototype.forEach.call(head.cells, function (th) {
      if (th.getAttribute('data-sort') === 'none' || th.colSpan > 1) return;
      th.classList.add('tsort-th');
      th.setAttribute('tabindex', '0');
      th.setAttribute('role', 'button');
      th.insertAdjacentHTML('beforeend', ARROWS);
      th.addEventListener('click', function () { sortBy(table, th); });
      th.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
          e.preventDefault();
          sortBy(table, th);
        }
      });
    });
  }

  function scan() {
    Array.prototype.forEach.call(document.querySelectorAll('table[data-sortable]'), init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }
})();
