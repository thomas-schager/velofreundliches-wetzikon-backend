/*
 * Route editor -- loads/edits/saves the Velonetz line network against the real backend
 * (Controller\Api\AdminRoutesController). Map engine (tile layers, band/direction-arrow
 * rendering, legend) is ported from velo-melder.html's editor in velofreundliches-wetzikon-contao
 * -- same math, same Leaflet.draw usage -- adapted to fetch ROUTE_TYPES from GET /route-types
 * instead of a hand-duplicated JS array, and to persist via the diff/save API instead of
 * localStorage + file download.
 *
 * No auto-save: nothing in this file calls PUT /admin/routes except the explicit "Speichern
 * bestätigen" click in the confirm modal, which itself only appears after "Speichern" ->
 * POST /admin/routes/diff. Every edit (draw/move/retype/delete) only touches the in-memory
 * Leaflet layers until that point.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var mapEl = document.getElementById('routesMap');
    if (!mapEl) return; // not on the routes page

    var DRAW_CSS_URL = 'https://cdn.jsdelivr.net/npm/leaflet-draw@1.0.4/dist/leaflet.draw.min.css';
    var DRAW_JS_URL = 'https://cdn.jsdelivr.net/npm/leaflet-draw@1.0.4/dist/leaflet.draw.min.js';
    var DECORATOR_JS_URL = 'https://cdn.jsdelivr.net/npm/leaflet-polylinedecorator@1.6.0/dist/leaflet.polylineDecorator.min.js';

    // Presentational-only tweaks not stored in the route_types table (see database/schema.sql --
    // weight/color/band/bandStyle/bandScale/noDirection are; dash patterns are cosmetic only).
    var EXTRA_STYLE = { veloroute: { dashArray: '8 5' } };

    var ROUTE_TYPES = [];
    var typesByKey = {};
    var hiddenTypes = new Set();
    var isDirty = false;

    // ---------------------------------------------------------------------------------------
    // Map + tile layers (design-system/map.html)
    // CARTO_API_KEY: since 2026-08, basemaps.cartocdn.com watermarks every tile "API KEY
    // REQUIRED" without one (CARTO is also phasing raster basemaps out in favour of vector
    // tiles). Free key, no CARTO account needed: carto.com/basemaps/apikey -- this one is
    // registered to the production domain; get your own if it stops working elsewhere.
    // ---------------------------------------------------------------------------------------
    var CARTO_API_KEY = 'cb1_2wxo_1_5095e615a6275239af744999';
    var map = L.map('routesMap', { center: [47.3235, 8.7985], zoom: 15 });
    var LAYERS = {
      street: L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png?key=' + CARTO_API_KEY, { attribution: '&copy; OpenStreetMap contributors &copy; CARTO', subdomains: 'abcd', maxZoom: 19 }),
      satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: '&copy; Esri', maxZoom: 19 })
    };
    var currentLayer = 'street';
    LAYERS.street.addTo(map);

    function setLayer(name) {
      if (name === currentLayer) return;
      map.removeLayer(LAYERS[currentLayer]);
      LAYERS[name].addTo(map);
      currentLayer = name;
      document.getElementById('btnLayerStreet').classList.toggle('active', name === 'street');
      document.getElementById('btnLayerSatellite').classList.toggle('active', name === 'satellite');
    }
    document.getElementById('btnLayerStreet').addEventListener('click', function () { setLayer('street'); });
    document.getElementById('btnLayerSatellite').addEventListener('click', function () { setLayer('satellite'); });
    var toggleEl = mapEl.parentElement.querySelector('.map-toggle');
    L.DomEvent.disableClickPropagation(toggleEl);
    L.DomEvent.disableScrollPropagation(toggleEl);

    var bandGroup = L.layerGroup().addTo(map);
    var highlightGroup = L.layerGroup().addTo(map);
    var drawnItems = new L.FeatureGroup().addTo(map);
    var decoratorGroup = L.layerGroup().addTo(map);
    var waypointGroup = L.layerGroup().addTo(map);
    map.on('zoomend', function () { rebuildDecorators(); });

    // ---------------------------------------------------------------------------------------
    // Route-type-driven styling (ported from velo-melder.html's COMPILE:ROUTE-RENDER)
    // ---------------------------------------------------------------------------------------
    function getTypeCfg(key) {
      return typesByKey[key] || ROUTE_TYPES[0];
    }

    function layerStyle(key) {
      var c = getTypeCfg(key);
      var extra = EXTRA_STYLE[key] || {};
      return { color: c.color, weight: c.weight, opacity: 1, lineCap: 'round', lineJoin: 'round', dashArray: extra.dashArray || null, pane: 'rt-' + key };
    }

    function flattenLatLngs(lls) {
      if (!lls || !lls.length) return [];
      return (lls[0] instanceof L.LatLng) ? lls : (lls[0] || []);
    }

    function bandWeight() {
      var mpp = 40075016.686 * Math.cos(47.32 * Math.PI / 180) / Math.pow(2, map.getZoom() + 8);
      return Math.max(6, Math.round(10 / mpp));
    }

    function rebuildDecorators() {
      decoratorGroup.clearLayers();
      bandGroup.clearLayers();
      if (typeof L.polylineDecorator === 'undefined') return;
      drawnItems.eachLayer(function (layer) {
        if (!layer.getLatLngs) return;
        var typeKey = layer.routeType;
        var cfg = getTypeCfg(typeKey);
        var lls = flattenLatLngs(layer.getLatLngs());
        if (lls.length < 2 || hiddenTypes.has(typeKey)) return;

        if (cfg.band) {
          var bw = bandWeight();
          if (cfg.bandStyle === 'outlined') {
            L.polyline(lls, { color: cfg.color, weight: bw, opacity: 1, lineCap: 'round', lineJoin: 'round', interactive: false, pane: 'rt-' + typeKey }).addTo(bandGroup);
            L.polyline(lls, { color: '#ffffff', weight: Math.max(4, bw - 6), opacity: 1, lineCap: 'round', lineJoin: 'round', interactive: false, pane: 'rt-' + typeKey }).addTo(bandGroup);
          } else if (cfg.bandStyle === 'narrow') {
            L.polyline(lls, { color: cfg.color, weight: Math.max(3, Math.round(bw / 3)), opacity: 1, lineCap: 'round', lineJoin: 'round', interactive: false, pane: 'rt-' + typeKey }).addTo(bandGroup);
          } else {
            var sw = cfg.bandScale ? Math.max(3, Math.round(bw * cfg.bandScale)) : bw;
            L.polyline(lls, { color: cfg.color, weight: sw, opacity: 1, lineCap: 'round', lineJoin: 'round', interactive: false, pane: 'rt-' + typeKey }).addTo(bandGroup);
          }
          return;
        }
        if (cfg.noDirection) return;

        var dir = layer.routeDirection || 'one-way';
        function arrow() {
          return L.Symbol.arrowHead({ pixelSize: 10, headAngle: 55, polygon: false, pathOptions: { color: cfg.color, weight: 2, opacity: 0.9, fill: false } });
        }
        if (dir === 'one-way') {
          L.polylineDecorator(layer, { patterns: [{ offset: '10%', repeat: '25%', symbol: arrow() }] }).addTo(decoratorGroup);
        } else {
          L.polylineDecorator(layer, { patterns: [{ offset: '5%', repeat: '30%', symbol: arrow() }] }).addTo(decoratorGroup);
          L.polylineDecorator(L.polyline(lls.slice().reverse()), { patterns: [{ offset: '20%', repeat: '30%', symbol: arrow() }] }).addTo(decoratorGroup);
        }
      });
    }

    function applyTypeVisibility() {
      drawnItems.eachLayer(function (layer) {
        var cfg = getTypeCfg(layer.routeType);
        if (!cfg.band) {
          layer.setStyle(hiddenTypes.has(layer.routeType) ? { opacity: 0, weight: 0 } : layerStyle(layer.routeType));
        }
      });
      rebuildDecorators();
    }

    function buildLegend() {
      var rows = document.getElementById('routeLegendRows');
      var legend = document.getElementById('routeLegend');
      rows.innerHTML = '';
      var hasAny = false;
      drawnItems.eachLayer(function () { hasAny = true; });
      if (!hasAny) { legend.classList.remove('visible'); return; }

      ROUTE_TYPES.forEach(function (cfg) {
        var found = false;
        drawnItems.eachLayer(function (l) { if (l.routeType === cfg.key) found = true; });
        if (!found) return;

        var row = document.createElement('div');
        var isHidden = hiddenTypes.has(cfg.key);
        row.className = 'adm-route-legend-row' + (isHidden ? ' adm-route-legend-row--hidden' : '');
        var line = document.createElement('div');
        var extra = EXTRA_STYLE[cfg.key] || {};
        if (cfg.bandStyle === 'outlined') {
          line.className = 'adm-route-legend-line--band-outlined';
        } else if (cfg.bandStyle === 'narrow') {
          line.className = 'adm-route-legend-line--band-narrow'; line.style.background = cfg.color;
        } else if (cfg.band) {
          line.className = cfg.bandScale ? 'adm-route-legend-line--band-scaled' : 'adm-route-legend-line adm-route-legend-line--band'; line.style.background = cfg.color;
        } else {
          line.className = 'adm-route-legend-line' + (extra.dashArray ? ' adm-route-legend-line--dashed' : '');
          line.style.background = extra.dashArray ? 'none' : cfg.color;
          line.style.color = cfg.color;
        }
        var lbl = document.createElement('span'); lbl.textContent = cfg.label;
        row.appendChild(line); row.appendChild(lbl);
        row.addEventListener('click', function (e) {
          L.DomEvent.stopPropagation(e);
          if (hiddenTypes.has(cfg.key)) { hiddenTypes.delete(cfg.key); row.classList.remove('adm-route-legend-row--hidden'); }
          else { hiddenTypes.add(cfg.key); row.classList.add('adm-route-legend-row--hidden'); }
          applyTypeVisibility();
        });
        rows.appendChild(row);
      });
      legend.classList.add('visible');
    }

    // ---------------------------------------------------------------------------------------
    // Status bar / dirty tracking -- "no auto-save": isDirty only gates the status text and the
    // beforeunload warning, it never triggers a write by itself.
    // ---------------------------------------------------------------------------------------
    function markDirty() {
      isDirty = true;
      document.getElementById('routeStatusText').textContent = 'Ungespeicherte Änderungen';
    }
    function markClean(text) {
      isDirty = false;
      document.getElementById('routeStatusText').textContent = text || 'Keine ungespeicherten Änderungen';
    }
    window.addEventListener('beforeunload', function (e) {
      if (!isDirty) return;
      e.preventDefault();
      e.returnValue = '';
    });

    function showToast(msg) {
      var t = document.getElementById('routeToast');
      t.textContent = msg;
      t.classList.add('show');
      setTimeout(function () { t.classList.remove('show'); }, 2500);
    }

    // ---------------------------------------------------------------------------------------
    // Loading / rendering the network from the API
    // ---------------------------------------------------------------------------------------
    function renderFeatureCollection(fc) {
      drawnItems.clearLayers();
      (fc.features || []).forEach(function (f) {
        if (!typesByKey[f.properties.type]) return; // unknown type -- skip rather than crash
        var latlngs = f.geometry.coordinates.map(function (c) { return [c[1], c[0]]; });
        var layer = L.polyline(latlngs, layerStyle(f.properties.type));
        layer.routeType = f.properties.type;
        layer.routeDirection = f.properties.direction || 'one-way';
        layer.routeId = f.properties.id || null;
        drawnItems.addLayer(layer);
      });
      rebuildDecorators();
      buildLegend();
    }

    function buildProposedFeatureCollection() {
      var features = [];
      drawnItems.eachLayer(function (layer) {
        var lls = flattenLatLngs(layer.getLatLngs());
        if (lls.length < 2) return;
        var cfg = getTypeCfg(layer.routeType);
        var properties = { type: layer.routeType };
        if (!cfg.band && !cfg.noDirection) properties.direction = layer.routeDirection || 'one-way';
        if (layer.routeId) properties.id = layer.routeId;
        features.push({
          type: 'Feature',
          geometry: { type: 'LineString', coordinates: lls.map(function (ll) { return [ll.lng, ll.lat]; }) },
          properties: properties
        });
      });
      return { type: 'FeatureCollection', features: features };
    }

    function loadFromServer() {
      markClean('Lädt Routen …');
      return fetch('/admin/routes').then(function (r) { return r.json(); }).then(function (fc) {
        renderFeatureCollection(fc);
        markClean();
      });
    }

    // ---------------------------------------------------------------------------------------
    // Editor panel: type pills + direction radios. Double duty -- with nothing selected, they
    // pick the type/direction for the *next* drawn route; with a route selected, changing them
    // retypes/redirects that existing route immediately (marks dirty, does not save).
    // ---------------------------------------------------------------------------------------
    var selectedType = null;
    var selectedDirection = 'one-way';
    var selectedLayer = null;

    function buildTypePills() {
      var wrap = document.getElementById('typePills');
      wrap.innerHTML = '';
      ROUTE_TYPES.forEach(function (cfg, i) {
        var pill = document.createElement('button');
        pill.type = 'button';
        pill.className = 'adm-type-pill' + (i === 0 ? ' is-active' : '');
        pill.dataset.type = cfg.key;

        var dot = document.createElement('span');
        dot.className = 'adm-type-pill-dot' + (cfg.bandStyle === 'outlined' ? ' adm-type-pill-dot--band-outlined' : cfg.bandStyle === 'narrow' ? ' adm-type-pill-dot--band-narrow' : cfg.band ? ' adm-type-pill-dot--band' : '');
        if (cfg.bandStyle !== 'outlined') dot.style.background = cfg.color;

        var label = document.createElement('span');
        label.textContent = cfg.label;

        pill.appendChild(dot); pill.appendChild(label);
        pill.addEventListener('click', function () { selectType(cfg.key); });
        wrap.appendChild(pill);
      });
      selectedType = ROUTE_TYPES[0] ? ROUTE_TYPES[0].key : null;
      updateDirectionVisibility();
    }

    /** Visual-only: highlights a pill without mutating anything -- used when a route is
     *  selected, so merely clicking a route to look at it never marks the page dirty. */
    function setActivePill(key) {
      document.querySelectorAll('.adm-type-pill').forEach(function (p) {
        p.classList.remove('is-active'); p.style.removeProperty('--active-color');
      });
      var pillEl = document.querySelector('.adm-type-pill[data-type="' + key + '"]');
      if (pillEl) { pillEl.classList.add('is-active'); pillEl.style.setProperty('--active-color', getTypeCfg(key).color); }
      selectedType = key;
      updateDirectionVisibility();
    }

    /** Pill click handler: picks the type for the next drawn route, or -- if a route is
     *  currently selected -- retypes that route (a real edit, marks dirty). */
    function selectType(key) {
      setActivePill(key);
      if (selectedLayer) {
        selectedLayer.routeType = key;
        selectedLayer.setStyle(layerStyle(key));
        rebuildDecorators();
        buildLegend();
        markDirty();
      }
    }

    function updateDirectionVisibility() {
      var cfg = getTypeCfg(selectedType);
      document.getElementById('directionSection').style.display = (!cfg || cfg.band || cfg.noDirection) ? 'none' : 'block';
    }

    document.querySelectorAll('input[name="admDir"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        selectedDirection = this.value;
        if (selectedLayer) {
          selectedLayer.routeDirection = this.value;
          rebuildDecorators();
          markDirty();
        }
      });
    });

    function setEditorHint(msg) {
      var el = document.getElementById('editorHint');
      el.textContent = msg;
      el.classList.toggle('visible', !!msg);
    }

    // ---------------------------------------------------------------------------------------
    // Selection + waypoint markers
    // ---------------------------------------------------------------------------------------
    function selectRoute(layer) {
      highlightGroup.clearLayers();
      waypointGroup.clearLayers();
      var lls = flattenLatLngs(layer.getLatLngs());
      if (lls.length < 2) return;
      var cfg = getTypeCfg(layer.routeType);
      var w = cfg.band ? bandWeight() + 8 : cfg.weight + 8;
      L.polyline(lls, { color: '#dc3406', weight: w, opacity: 0.45, lineCap: 'round', lineJoin: 'round', interactive: false }).addTo(highlightGroup);
      lls.forEach(function (ll, i) {
        L.marker(ll, { icon: L.divIcon({ className: 'adm-wp-marker', html: '<span class="adm-wp-label">' + i + '</span>', iconSize: [0, 0], iconAnchor: [0, 0] }), interactive: false }).addTo(waypointGroup);
      });

      // Reflect the selected route's own type/direction in the panel -- visual only, must not
      // mark the page dirty just because something got clicked/looked at.
      setActivePill(layer.routeType);
      selectedDirection = layer.routeDirection || 'one-way';
      var dirRadio = document.querySelector('input[name="admDir"][value="' + selectedDirection + '"]');
      if (dirRadio) dirRadio.checked = true;

      selectedLayer = layer; // set last -- see setActivePill's docblock
    }

    function clearSelection() {
      highlightGroup.clearLayers();
      waypointGroup.clearLayers();
      selectedLayer = null;
    }

    // ---------------------------------------------------------------------------------------
    // Draw / edit-vertices / delete tools (leaflet-draw), loaded lazily like velo-melder.html
    // ---------------------------------------------------------------------------------------
    function loadScript(url, cb) {
      var s = document.createElement('script');
      s.src = url; s.onload = cb;
      document.head.appendChild(s);
    }
    function loadCss(url) {
      var l = document.createElement('link');
      l.rel = 'stylesheet'; l.href = url;
      document.head.appendChild(l);
    }

    var currentDrawer = null, editHandler = null, deleteHandler = null;

    function stopAll() {
      clearSelection();
      if (currentDrawer) { currentDrawer.disable(); currentDrawer = null; }
      if (editHandler) { editHandler.save(); editHandler.disable(); editHandler = null; rebuildDecorators(); }
      if (deleteHandler) { deleteHandler.save(); deleteHandler.disable(); deleteHandler = null; rebuildDecorators(); buildLegend(); }
      ['btnDrawRoute', 'btnEditVertices', 'btnDeleteRoutes'].forEach(function (id) {
        document.getElementById(id).classList.remove('is-active');
      });
      mapEl.classList.remove('is-drawing', 'is-editing');
      setEditorHint('');
    }

    function initTools() {
      document.getElementById('btnDrawRoute').addEventListener('click', function () {
        if (currentDrawer) { stopAll(); return; }
        stopAll();
        currentDrawer = new L.Draw.Polyline(map, { shapeOptions: layerStyle(selectedType) });
        currentDrawer.enable();
        this.classList.add('is-active');
        mapEl.classList.add('is-drawing');
        setEditorHint('Punkte klicken · Doppelklick abschliessen · ESC abbrechen');
      });

      var justSelected = false;
      drawnItems.on('click', function (e) {
        if (e.layer && !currentDrawer && !editHandler && !deleteHandler) {
          selectRoute(e.layer);
          justSelected = true;
        }
      });
      map.on('click', function () {
        if (justSelected) { justSelected = false; return; }
        clearSelection();
      });

      map.on('draw:created', function (e) {
        var layer = e.layer;
        layer.routeType = selectedType;
        layer.routeDirection = selectedDirection;
        layer.routeId = null; // new -- no db id yet
        layer.setStyle(layerStyle(selectedType));
        drawnItems.addLayer(layer);
        markDirty();
        rebuildDecorators();
        buildLegend();
        currentDrawer = null;
        document.getElementById('btnDrawRoute').classList.remove('is-active');
        mapEl.classList.remove('is-drawing');
        setEditorHint('');
      });
      map.on('draw:drawstop', function () {
        currentDrawer = null;
        document.getElementById('btnDrawRoute').classList.remove('is-active');
        mapEl.classList.remove('is-drawing');
        setEditorHint('');
      });

      document.getElementById('btnEditVertices').addEventListener('click', function () {
        if (editHandler) { stopAll(); return; }
        stopAll();
        editHandler = new L.EditToolbar.Edit(map, { featureGroup: drawnItems });
        editHandler.enable();
        this.classList.add('is-active');
        mapEl.classList.add('is-editing');
        setEditorHint('Punkte verschieben · Nochmals klicken zum Übernehmen');
      });

      document.getElementById('btnDeleteRoutes').addEventListener('click', function () {
        if (deleteHandler) { stopAll(); return; }
        stopAll();
        deleteHandler = new L.EditToolbar.Delete(map, { featureGroup: drawnItems });
        deleteHandler.enable();
        this.classList.add('is-active');
        setEditorHint('Route anklicken · Nochmals klicken zum Bestätigen');
      });

      map.on('draw:edited', function () { markDirty(); rebuildDecorators(); });
      map.on('draw:deleted', function () { markDirty(); rebuildDecorators(); buildLegend(); });
    }

    document.getElementById('btnDiscard').addEventListener('click', function () {
      if (isDirty && !confirm('Alle ungespeicherten Änderungen verwerfen und den gespeicherten Stand neu laden?')) return;
      stopAll();
      loadFromServer();
      showToast('Änderungen verworfen.');
    });

    // ---------------------------------------------------------------------------------------
    // Save flow: Speichern -> POST /admin/routes/diff (preview only) -> confirm modal with
    // bullet-point summary -> "Speichern bestätigen" -> PUT /admin/routes (the only write).
    // ---------------------------------------------------------------------------------------
    var ACTION_ICONS = {
      added: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
      removed: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="5" y1="12" x2="19" y2="12"/></svg>',
      modified: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>'
    };

    function renderChangeSummary(summary) {
      document.getElementById('confirmModalCounts').textContent =
        summary.addedCount + ' hinzugefügt · ' + summary.removedCount + ' entfernt · ' + summary.modifiedCount + ' geändert';
      var list = document.getElementById('confirmModalList');
      list.innerHTML = '';
      summary.lines.forEach(function (line, i) {
        var entry = summary.entries[i];
        var li = document.createElement('li');
        li.dataset.action = entry ? entry.action : '';
        li.innerHTML = '<span class="adm-changelist__icon">' + (ACTION_ICONS[entry ? entry.action : ''] || '') + '</span><span>' + line.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</span>';
        list.appendChild(li);
      });
    }

    function openModal(id) { document.getElementById(id).classList.add('open'); }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }

    document.getElementById('btnSaveRoutes').addEventListener('click', function () {
      var fc = buildProposedFeatureCollection();
      fetch('/admin/routes/diff', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(fc) })
        .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
        .then(function (result) {
          if (!result.ok) { alert('Fehler: ' + (result.data.message || result.data.error || 'unbekannt')); return; }
          var summary = result.data;
          if (summary.addedCount + summary.removedCount + summary.modifiedCount === 0) {
            alert('Keine Änderungen zum Speichern.');
            return;
          }
          renderChangeSummary(summary);
          openModal('confirmModalOverlay');

          document.getElementById('btnConfirmSave').onclick = function () {
            fetch('/admin/routes', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(fc) })
              .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
              .then(function (saveResult) {
                closeModal('confirmModalOverlay');
                if (!saveResult.ok) { alert('Fehler beim Speichern: ' + (saveResult.data.message || saveResult.data.error || 'unbekannt')); return; }
                renderFeatureCollection(saveResult.data);
                markClean();
                loadBackups();
                showToast('Änderungen gespeichert.');
              })
              .catch(function () { closeModal('confirmModalOverlay'); alert('Verbindung zum Server fehlgeschlagen.'); });
          };
        })
        .catch(function () { alert('Verbindung zum Server fehlgeschlagen.'); });
    });
    document.getElementById('btnCancelSave').addEventListener('click', function () { closeModal('confirmModalOverlay'); });

    // ---------------------------------------------------------------------------------------
    // Backups (version history) column -- persistent, not a modal; reloaded after every save
    // and after every restore so it always reflects the current backup trail.
    //
    // Each version is a collapsed row by default (time, admin, +/-/~ counts); expanding it
    // reveals the same per-change bullet list as the confirm-save modal (.adm-changelist).
    // The backend only persists a flattened bullet-text summary per backup (RouteBackup.summary
    // has no structured per-line action field), so the added/removed/modified icon for each
    // detail line is recovered here by matching the fixed sentence templates
    // RouteEditingService::buildSummary() always uses -- safe because that text is entirely
    // backend-generated, never user input.
    // ---------------------------------------------------------------------------------------
    function detectLineAction(line) {
      if (line.indexOf('Neue Strecke hinzugefügt') === 0) return 'added';
      if (line.indexOf('Strecke entfernt') === 0) return 'removed';
      if (line.indexOf('Strecke geändert') === 0) return 'modified';
      return '';
    }

    /** @return {label: ?string, bullets: string[]} */
    function parseBackupSummary(summary) {
      var lines = summary.split('\n');
      var label = null;
      var bullets = [];
      lines.forEach(function (line) {
        if (line.indexOf('- ') === 0) {
          bullets.push(line.slice(2));
        } else if (line.trim() !== '') {
          label = line; // the "Wiederherstellung von Sicherung #N (...)" prefix, if present
        }
      });
      return { label: label, bullets: bullets };
    }

    function renderBackups(items) {
      var list = document.getElementById('backupsList');
      list.innerHTML = '';
      if (!items.length) {
        list.innerHTML = '<p style="color:var(--color-muted);font-size:12px;">Noch keine Sicherungen -- die erste wird beim nächsten Speichern angelegt.</p>';
        return;
      }
      items.forEach(function (b) {
        var date = new Date(b.createdAt);
        var parsed = parseBackupSummary(b.summary);

        var row = document.createElement('div');
        row.className = 'adm-backup-row';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'adm-backup-row__trigger';
        trigger.setAttribute('aria-expanded', 'false');
        trigger.innerHTML =
          '<div class="adm-backup-row__main">' +
          '<div class="adm-backup-row__time">' + date.toLocaleDateString('de-CH') + ', ' + date.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit' }) + '</div>' +
          '<div class="adm-backup-row__by"></div>' +
          (parsed.label ? '<span class="adm-backup-row__label"></span>' : '') +
          '<div class="adm-backup-row__counts">' +
          '<span class="adm-backup-count adm-backup-count--added">+' + b.addedCount + '</span>' +
          '<span class="adm-backup-count adm-backup-count--removed">-' + b.removedCount + '</span>' +
          '<span class="adm-backup-count adm-backup-count--modified">~' + b.modifiedCount + '</span>' +
          '</div></div>' +
          '<i data-lucide="chevron-down" width="15" height="15" class="adm-backup-row__chevron"></i>';
        trigger.querySelector('.adm-backup-row__by').textContent = b.createdByEmail || 'Unbekannt';
        if (parsed.label) trigger.querySelector('.adm-backup-row__label').textContent = parsed.label;
        trigger.addEventListener('click', function () {
          var expanded = row.classList.toggle('is-expanded');
          trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });

        var details = document.createElement('div');
        details.className = 'adm-backup-row__details';
        var changelist = document.createElement('ul');
        changelist.className = 'adm-changelist';
        parsed.bullets.forEach(function (bullet) {
          var action = detectLineAction(bullet);
          var li = document.createElement('li');
          li.dataset.action = action;
          li.innerHTML = '<span class="adm-changelist__icon">' + (ACTION_ICONS[action] || '') + '</span><span></span>';
          li.querySelector('span:last-child').textContent = bullet;
          changelist.appendChild(li);
        });
        details.appendChild(changelist);

        var btn = document.createElement('button');
        btn.className = 'adm-btn adm-btn--secondary adm-btn--sm adm-btn--block';
        btn.textContent = 'Wiederherstellen';
        btn.addEventListener('click', function () {
          if (!confirm('Stand vom ' + date.toLocaleString('de-CH') + ' wiederherstellen? Der aktuelle Stand wird dabei automatisch als neue Sicherung abgelegt.')) return;
          fetch('/admin/routes/backups/' + b.id + '/restore', { method: 'POST' })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
              if (!result.ok) { alert('Fehler: ' + (result.data.message || result.data.error || 'unbekannt')); return; }
              stopAll();
              renderFeatureCollection(result.data);
              markClean();
              loadBackups();
              showToast('Sicherung wiederhergestellt.');
            })
            .catch(function () { alert('Verbindung zum Server fehlgeschlagen.'); });
        });
        details.appendChild(btn);

        row.appendChild(trigger);
        row.appendChild(details);
        list.appendChild(row);
      });
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function loadBackups() {
      fetch('/admin/routes/backups').then(function (r) { return r.json(); }).then(renderBackups);
    }

    // ---------------------------------------------------------------------------------------
    // Boot: route types -> panes/pills -> current network -> draw tools
    // ---------------------------------------------------------------------------------------
    fetch('/route-types').then(function (r) { return r.json(); }).then(function (types) {
      ROUTE_TYPES = types;
      typesByKey = {};
      types.forEach(function (t) { typesByKey[t.key] = t; });

      ROUTE_TYPES.forEach(function (cfg, i) {
        var p = map.createPane('rt-' + cfg.key);
        p.style.zIndex = 390 + i;
        p.style.opacity = cfg.band ? (cfg.bandStyle === 'outlined' ? 0.85 : cfg.bandStyle === 'narrow' ? 0.75 : 0.65) : 1;
      });

      buildTypePills();
      loadBackups();
      return loadFromServer();
    }).then(function () {
      loadCss(DRAW_CSS_URL);
      var ready = 0;
      function onLibReady() { ready++; if (ready === 2) initTools(); }
      loadScript(DECORATOR_JS_URL, onLibReady);
      loadScript(DRAW_JS_URL, onLibReady);
    }).catch(function () {
      markClean('Fehler beim Laden der Routen.');
    });

    if (typeof lucide !== 'undefined') lucide.createIcons();
  });
})();
