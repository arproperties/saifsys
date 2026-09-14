/*
 * HR Fleet maps — OpenStreetMap through Leaflet. No API key.
 *
 *   FleetMap.live({...})    fleet_live.php: every vehicle on a trip, refreshed
 *   FleetMap.routes({...})  fleet_history.php, vehicle_view.php: tick a trip to
 *                           draw its route, ▶ to replay the van moving along it
 */
(function () {
  'use strict';

  var UAE = [25.2, 55.3];

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function baseMap(el) {
    var map = L.map(el).setView(UAE, 10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    return map;
  }

  function speed(kmh) {
    return kmh == null ? '—' : kmh + ' km/h';
  }

  /** Same pictures as the Driver app (Material Design Icons). */
  var VEHICLE_GLYPH = {
    car: 'car',
    van: 'van-utility',
    pickup: 'car-pickup',
    truck: 'truck',
    bus: 'bus',
    bike: 'motorbike'
  };

  function vehicleIcon(type, extraClass) {
    return '<i class="mdi mdi-' + (VEHICLE_GLYPH[type] || 'car') + ' ' + (extraClass || '') + '" aria-hidden="true"></i>';
  }

  function pinIcon(label, extraClass, vehicleType) {
    return L.divIcon({
      className: '',
      iconSize: null,
      html: '<div class="fleet-pin ' + (extraClass || '') + '">' + vehicleIcon(vehicleType) + esc(label) + '</div>'
    });
  }

  /** A route stop: green once reached, amber until then. */
  function stopMarker(s, reached) {
    var text = esc(s.name) + (s.time ? ' · ' + esc(s.time) : '') +
      (reached ? ' · reached' + (typeof reached === 'string' ? ' ' + esc(reached) : '') : ' · not reached');
    return L.circleMarker([s.lat, s.lng], {
      radius: 8, color: '#fff', weight: 2, fillColor: reached ? '#059669' : '#f59e0b', fillOpacity: 1
    }).bindTooltip(text);
  }

  // ---------------------------------------------------------------------------
  // Live
  // ---------------------------------------------------------------------------

  function live(opts) {
    var map = baseMap(opts.mapEl);
    var markers = {};
    var stopsLayer = L.layerGroup().addTo(map);
    var fitted = false;

    function popup(r) {
      return '<strong>' + esc(r.plate_no) + '</strong>' + (r.vehicle_name ? ' · ' + esc(r.vehicle_name) : '') +
        (r.route ? '<br>' + esc(r.route) : '') +
        '<br>Driver: ' + esc(r.driver_name) +
        '<br>Speed: ' + speed(r.speed_kmh) +
        '<br>Started ' + esc(r.started) + ' · ' + esc(r.duration) + ' · ' + esc(r.distance) +
        '<br>Last signal: ' + esc(r.last_seen) +
        '<br><a href="vehicle_view?id=' + r.vehicle_id + '">Vehicle history</a>';
    }

    function render(rows) {
      var seen = {};
      stopsLayer.clearLayers();
      rows.forEach(function (r) {
        (r.stops || []).forEach(function (s) { stopMarker(s, s.done).addTo(stopsLayer); });
        if (r.lat == null) return;
        seen[r.trip_id] = true;
        var m = markers[r.trip_id];
        var icon = pinIcon(r.plate_no, r.stale ? 'is-stale' : '', r.vehicle_type);
        if (!m) {
          m = markers[r.trip_id] = L.marker([r.lat, r.lng], { icon: icon }).addTo(map).bindPopup(popup(r));
        } else {
          m.setLatLng([r.lat, r.lng]).setIcon(icon).setPopupContent(popup(r));
        }
      });
      Object.keys(markers).forEach(function (id) {
        if (!seen[id]) {
          map.removeLayer(markers[id]);
          delete markers[id];
        }
      });

      opts.listEl.innerHTML = rows.length ? rows.map(function (r) {
        var stops = r.stops || [];
        var next = stops.filter(function (s) { return !s.done; })[0];
        return '<button type="button" class="list-group-item list-group-item-action" data-trip="' + r.trip_id + '">' +
          '<div class="d-flex justify-content-between align-items-center"><strong>' +
          vehicleIcon(r.vehicle_type, 'fleet-list-icon') + esc(r.plate_no) + '</strong>' +
          '<span class="badge ' + (r.stale ? 'text-bg-secondary' : 'text-bg-success') + '">' + (r.stale ? 'No signal' : 'Live') + '</span></div>' +
          '<div class="small">' + esc(r.driver_name) + ' · ' + speed(r.speed_kmh) + '</div>' +
          (r.route ? '<div class="small text-muted">' + esc(r.route) +
            (stops.length ? ' · ' + (next ? 'next: ' + esc(next.name) : 'all stops done') : '') + '</div>' : '') +
          '<div class="small text-muted">' + esc(r.duration) + ' · ' + esc(r.distance) + ' · ' + esc(r.last_seen) + '</div>' +
          '</button>';
      }).join('') : '<div class="p-3 text-muted small">No vehicle is on a trip right now.</div>';

      if (opts.countEl) opts.countEl.textContent = rows.length;

      var all = Object.keys(markers).map(function (id) { return markers[id]; });
      if (!fitted && all.length) {
        fitted = true;
        map.fitBounds(L.featureGroup(all).getBounds().pad(0.3), { maxZoom: 15 });
      }
    }

    opts.listEl.addEventListener('click', function (e) {
      var item = e.target.closest('[data-trip]');
      var m = item && markers[item.getAttribute('data-trip')];
      if (m) {
        map.setView(m.getLatLng(), 16);
        m.openPopup();
      }
    });

    function load() {
      fetch(opts.url, { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (d) {
          render(d.rows || []);
          if (opts.updatedEl) opts.updatedEl.textContent = 'Updated ' + d.at;
        })
        .catch(function () {
          if (opts.updatedEl) opts.updatedEl.textContent = 'Could not refresh — trying again';
        });
    }

    load();
    // A hidden tab asks the server nothing; it catches up the moment it is shown.
    setInterval(function () {
      if (!document.hidden) load();
    }, opts.everyMs || 30000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) load();
    });
  }

  // ---------------------------------------------------------------------------
  // Replay
  // ---------------------------------------------------------------------------

  /**
   * Moves a van along a trip's GPS points in trip time × speed. The route
   * behind it is drawn as a dark trail so you can see where it has been.
   */
  function player(map, el) {
    var q = function (sel) { return el.querySelector(sel); };
    var btn = q('[data-play]');
    var range = q('[data-range]');
    var speedSel = q('[data-speed]');
    var label = q('[data-label]');
    var title = q('[data-title]');
    var pts = [];
    var t0 = 0;
    var t1 = 0;
    var now = 0;
    var playing = false;
    var lastFrame = null;
    var marker = null;
    var trail = null;

    function at(t) {
      var lo = 0;
      var hi = pts.length - 1;
      if (t <= pts[0].ts) return { lat: pts[0].lat, lng: pts[0].lng, i: 0, speed: pts[0].speed };
      if (t >= pts[hi].ts) return { lat: pts[hi].lat, lng: pts[hi].lng, i: hi, speed: pts[hi].speed };
      while (hi - lo > 1) {
        var mid = (lo + hi) >> 1;
        if (pts[mid].ts <= t) lo = mid; else hi = mid;
      }
      var a = pts[lo];
      var b = pts[hi];
      var f = (t - a.ts) / Math.max(1, b.ts - a.ts);
      return { lat: a.lat + (b.lat - a.lat) * f, lng: a.lng + (b.lng - a.lng) * f, i: lo, speed: a.speed };
    }

    function clock(t) {
      var d = new Date(t * 1000);
      var two = function (n) { return ('0' + n).slice(-2); };
      return two(d.getHours()) + ':' + two(d.getMinutes()) + ':' + two(d.getSeconds());
    }

    function render(follow) {
      var p = at(now);
      marker.setLatLng([p.lat, p.lng]);
      trail.setLatLngs(pts.slice(0, p.i + 1).map(function (x) { return [x.lat, x.lng]; }).concat([[p.lat, p.lng]]));
      range.value = t1 > t0 ? Math.round((now - t0) / (t1 - t0) * 1000) : 1000;
      label.textContent = clock(now) + ' · ' + speed(p.speed);
      if (follow && !map.getBounds().pad(-0.15).contains([p.lat, p.lng])) map.panTo([p.lat, p.lng]);
    }

    function setButton() {
      btn.innerHTML = playing ? '<i class="bi bi-pause-fill"></i>' : '<i class="bi bi-play-fill"></i>';
    }

    function frame(ms) {
      if (!playing) return;
      if (lastFrame !== null) now = Math.min(t1, now + (ms - lastFrame) / 1000 * Number(speedSel.value));
      lastFrame = ms;
      render(true);
      if (now >= t1) {
        playing = false;
        setButton();
        return;
      }
      requestAnimationFrame(frame);
    }

    function play() {
      if (pts.length < 2) return;
      if (now >= t1) now = t0;
      playing = true;
      lastFrame = null;
      setButton();
      requestAnimationFrame(frame);
    }

    function pause() {
      playing = false;
      setButton();
    }

    function clear() {
      pause();
      if (marker) map.removeLayer(marker);
      if (trail) map.removeLayer(trail);
      marker = null;
      trail = null;
      pts = [];
    }

    btn.addEventListener('click', function () {
      if (playing) pause(); else play();
    });
    range.addEventListener('input', function () {
      if (pts.length < 2) return;
      now = t0 + (t1 - t0) * Number(range.value) / 1000;
      render(false);
    });
    q('[data-close]').addEventListener('click', function () {
      clear();
      el.hidden = true;
    });

    return {
      load: function (d) {
        clear();
        el.hidden = false;
        title.textContent = d.trip.plate_no + ' · ' + d.trip.driver_name + ' · ' + d.trip.started;
        pts = (d.points || []).filter(function (p) { return p.ts; });
        if (pts.length < 2) {
          label.textContent = 'Not enough GPS points to play this trip.';
          pts = [];
          return;
        }
        t0 = pts[0].ts;
        t1 = pts[pts.length - 1].ts;
        now = t0;
        trail = L.polyline([], { color: '#0E2038', weight: 6, opacity: 0.9 }).addTo(map);
        marker = L.marker([pts[0].lat, pts[0].lng], {
          icon: pinIcon(d.trip.plate_no, 'is-playing', d.trip.vehicle_type),
          zIndexOffset: 1000
        }).addTo(map);
        map.fitBounds(L.latLngBounds(pts.map(function (p) { return [p.lat, p.lng]; })).pad(0.15), { maxZoom: 16 });
        render(false);
        play();
      }
    };
  }

  // ---------------------------------------------------------------------------
  // Routes
  // ---------------------------------------------------------------------------

  function routes(opts) {
    var map = baseMap(opts.mapEl);
    var layers = {};
    var requests = {};
    var replay = opts.playerEl ? player(map, document.getElementById(opts.playerEl)) : null;

    /** One request per trip. An open trip keeps growing, so it is not kept. */
    function getTrip(id) {
      if (!requests[id]) {
        requests[id] = fetch(opts.url + encodeURIComponent(id), { credentials: 'same-origin' })
          .then(function (res) { return res.json(); })
          .then(function (d) {
            if (!d.trip || d.trip.open) delete requests[id];
            return d;
          });
      }
      return requests[id];
    }

    function fit() {
      var all = Object.keys(layers).map(function (id) { return layers[id]; });
      if (all.length) map.fitBounds(L.featureGroup(all).getBounds().pad(0.15), { maxZoom: 16 });
    }

    function draw(box, d) {
      var id = box.getAttribute('data-trip');
      if (!box.checked || layers[id] || !d.trip) return;
      var pts = (d.points || []).map(function (p) { return [p.lat, p.lng]; });
      var group = L.featureGroup();

      (d.stops || []).forEach(function (s) { stopMarker(s, s.arrived).addTo(group); });

      if (pts.length) {
        var color = box.getAttribute('data-color');
        var first = d.points[0];
        var last = d.points[d.points.length - 1];
        var summary = '<strong>' + esc(d.trip.plate_no) + '</strong> · ' + esc(d.trip.driver_name) +
          (d.trip.route ? '<br>' + esc(d.trip.route) : '') +
          '<br>' + esc(d.trip.started) + ' · ' + esc(d.trip.duration) + ' · ' + esc(d.trip.distance);
        L.polyline(pts, { color: color, weight: 4, opacity: 0.85 }).bindPopup(summary).addTo(group);
        L.circleMarker(pts[0], { radius: 7, color: '#fff', weight: 2, fillColor: '#059669', fillOpacity: 1 })
          .bindTooltip('Start ' + esc(first.t)).addTo(group);
        L.circleMarker(pts[pts.length - 1], {
          radius: 7, color: '#fff', weight: 2, fillColor: d.trip.open ? '#2563eb' : '#dc2626', fillOpacity: 1
        }).bindTooltip((d.trip.open ? 'Now ' : 'End ') + esc(last.t)).addTo(group);
      }

      if (!group.getLayers().length) return;
      layers[id] = group.addTo(map);
      fit();
    }

    function show(box) {
      getTrip(box.getAttribute('data-trip')).then(function (d) { draw(box, d); });
    }

    function hide(box) {
      var id = box.getAttribute('data-trip');
      if (layers[id]) {
        map.removeLayer(layers[id]);
        delete layers[id];
      }
    }

    var boxes = document.querySelectorAll(opts.toggles);
    boxes.forEach(function (box) {
      box.addEventListener('change', function () {
        if (box.checked) show(box); else hide(box);
      });
      if (box.checked) show(box);
    });

    if (replay) {
      document.addEventListener('click', function (e) {
        var button = e.target.closest('.fleet-play');
        if (!button) return;
        var id = button.getAttribute('data-trip');
        boxes.forEach(function (box) {
          if (box.getAttribute('data-trip') === id && !box.checked) {
            box.checked = true;
            show(box);
          }
        });
        getTrip(id).then(function (d) {
          if (d.trip) replay.load(d);
        });
        document.getElementById(opts.mapEl).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });
    }
  }

  window.FleetMap = { live: live, routes: routes };
})();
