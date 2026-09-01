/* <radar-map> — Leaflet + OpenStreetMap, dark-inverted tiles, pulsing fluo pins.
   Attributes / properties: lat, lng, zoom, pins (JSON array or array), active (id),
   variant ("split" | "mini"), fit ("1" to fit bounds of pins).
   Events (bubbling, composed): "pin-select" {id}, "pin-open" {id}, "map-ready". */
(function () {
  if (window.__radarMapLoaded) return;
  window.__radarMapLoaded = true;

  var CSS = [
    '.rm-root{position:absolute;inset:0;background:#0b0b0b}',
    '.rm-root .leaflet-container{width:100%;height:100%;background:#0b0b0b;font-family:Archivo,system-ui,sans-serif;outline:none}',
    '.rm-root .leaflet-tile-pane{filter:invert(1) grayscale(1) brightness(.94) contrast(1.08)}',
    '.rm-root .leaflet-control-attribution{background:#0b0b0b;color:rgba(245,245,240,.5);font-size:9px;border:0;border-top:1px solid rgba(245,245,240,.14);border-left:1px solid rgba(245,245,240,.14);padding:2px 6px;letter-spacing:.04em}',
    '.rm-root .leaflet-control-attribution a{color:rgba(245,245,240,.72)}',
    '.rm-root .leaflet-bar{border:2px solid #f5f5f0;border-radius:0;box-shadow:none}',
    '.rm-root .leaflet-bar a{background:#0b0b0b;color:#f5f5f0;border-bottom:2px solid #f5f5f0;border-radius:0!important;font-weight:800;width:32px;height:32px;line-height:32px}',
    '.rm-root .leaflet-bar a:last-child{border-bottom:0}',
    '.rm-root .leaflet-bar a:hover{background:#ccff00;color:#0b0b0b}',
    '.rm-pin{background:none;border:0}',
    '.rm-pin i{position:absolute;display:block;border-radius:50%;left:50%;top:50%;transform:translate(-50%,-50%)}',
    '.rm-pin .rm-dot{width:14px;height:14px;background:#ccff00;box-shadow:0 0 0 2px #0b0b0b;transition:width .18s,height .18s}',
    '.rm-pin .rm-ring{width:14px;height:14px;border:2px solid #ccff00;animation:rmPulse 2.2s cubic-bezier(.2,.7,.3,1) infinite;opacity:0}',
    '.rm-pin.rm-on .rm-dot{width:24px;height:24px;background:#f5f5f0}',
    '.rm-pin.rm-on .rm-ring{animation-duration:1.1s;border-color:#f5f5f0}',
    '.rm-pin .rm-num{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);font-size:9px;font-weight:800;color:#0b0b0b;z-index:2;letter-spacing:0}',
    '@keyframes rmPulse{0%{opacity:.9;width:14px;height:14px}70%{opacity:0;width:56px;height:56px}100%{opacity:0;width:56px;height:56px}}',
    '.rm-root .leaflet-popup-content-wrapper{background:#0b0b0b;color:#f5f5f0;border:2px solid #ccff00;border-radius:0;box-shadow:10px 10px 0 rgba(0,0,0,.55);padding:0}',
    '.rm-root .leaflet-popup-content{margin:0;width:236px!important;line-height:1.35}',
    '.rm-root .leaflet-popup-tip{background:#ccff00;box-shadow:none}',
    '.rm-root .leaflet-popup-close-button{color:#f5f5f0!important;font-size:18px;padding:6px 8px 0 0}',
    '.rm-pop-k{font-size:9px;letter-spacing:.14em;text-transform:uppercase;color:#ccff00;padding:10px 12px 0}',
    '.rm-pop-t{font-size:15px;font-weight:800;letter-spacing:-.01em;padding:4px 12px 0}',
    '.rm-pop-m{font-size:11px;color:rgba(245,245,240,.62);padding:6px 12px 10px;letter-spacing:.02em}',
    '.rm-pop-b{display:flex;align-items:center;justify-content:space-between;gap:8px;border-top:2px solid rgba(245,245,240,.16);padding:8px 12px;font-size:11px;font-weight:800}',
    '.rm-pop-b button{font-family:inherit;font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;background:#ccff00;color:#0b0b0b;border:0;padding:6px 10px;cursor:pointer}',
    '.rm-pop-b button:hover{background:#f5f5f0}',
    '.rm-hint{position:absolute;left:0;bottom:0;z-index:500;background:#0b0b0b;border-top:2px solid #ccff00;border-right:2px solid #ccff00;color:rgba(245,245,240,.75);font:800 9px/1 Archivo,sans-serif;letter-spacing:.14em;text-transform:uppercase;padding:7px 10px;pointer-events:none}'
  ].join('');

  if (!document.getElementById('rm-style')) {
    var s = document.createElement('style');
    s.id = 'rm-style';
    s.textContent = CSS;
    document.head.appendChild(s);
  }

  function waitForL(cb) {
    if (window.L && window.L.map) return cb();
    var t = setInterval(function () {
      if (window.L && window.L.map) { clearInterval(t); cb(); }
    }, 60);
    setTimeout(function () { clearInterval(t); }, 15000);
  }

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  class RadarMap extends HTMLElement {
    static get observedAttributes() { return ['lat', 'lng', 'zoom', 'pins', 'active', 'variant', 'fit']; }

    constructor() {
      super();
      this._pins = [];
      this._markers = {};
      this._active = null;
    }

    set pins(v) {
      try { this._pins = typeof v === 'string' ? JSON.parse(v) : (v || []); } catch (e) { this._pins = []; }
      this._draw();
    }
    get pins() { return this._pins; }

    set active(v) { this._active = v == null || v === '' ? null : String(v); this._focus(); }
    get active() { return this._active; }

    attributeChangedCallback(n, o, v) {
      if (o === v) return;
      if (n === 'pins') { this.pins = v; return; }
      if (n === 'active') { this.active = v; return; }
      if (this._map && (n === 'lat' || n === 'lng' || n === 'zoom')) this._recenter();
    }

    connectedCallback() {
      if (this._booted) return;
      this._booted = true;
      if (!this.style.position) this.style.position = 'relative';
      this.style.display = 'block';
      var root = document.createElement('div');
      root.className = 'rm-root';
      this.appendChild(root);
      this._root = root;
      var hint = document.createElement('div');
      hint.className = 'rm-hint';
      hint.textContent = this.getAttribute('variant') === 'mini' ? 'clicca un pin' : 'trascina · zoom · clicca un pin';
      this.appendChild(hint);
      waitForL(this._init.bind(this));
    }

    disconnectedCallback() {
      if (this._map) { this._map.remove(); this._map = null; }
      this._booted = false;
      this._markers = {};
    }

    _num(name, dflt) {
      var v = parseFloat(this.getAttribute(name));
      return isNaN(v) ? dflt : v;
    }

    _init() {
      if (!this._root || this._map) return;
      var mini = this.getAttribute('variant') === 'mini';
      var map = L.map(this._root, {
        center: [this._num('lat', 45.4642), this._num('lng', 9.19)],
        zoom: this._num('zoom', 12),
        zoomControl: !mini,
        scrollWheelZoom: !mini,
        attributionControl: true,
        zoomSnap: 0.25
      });
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap contributors'
      }).addTo(map);
      if (!mini) map.zoomControl.setPosition('bottomright');
      this._map = map;
      this._layer = L.layerGroup().addTo(map);
      var self = this;
      this._root.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-rm-open]') : null;
        if (b) {
          e.stopPropagation();
          self.dispatchEvent(new CustomEvent('pin-open', {
            bubbles: true, composed: true, detail: { id: b.getAttribute('data-rm-open') }
          }));
        }
      });
      setTimeout(function () { map.invalidateSize(); }, 60);
      window.addEventListener('resize', function () { if (self._map) self._map.invalidateSize(); });
      this._draw();
      this.dispatchEvent(new CustomEvent('map-ready', { bubbles: true, composed: true }));
    }

    _recenter() {
      if (!this._map) return;
      this._map.setView([this._num('lat', 45.4642), this._num('lng', 9.19)], this._num('zoom', 12), { animate: true });
    }

    _draw() {
      if (!this._map || !this._layer) return;
      var self = this;
      this._layer.clearLayers();
      this._markers = {};
      var pts = [];
      this._pins.forEach(function (p, i) {
        if (typeof p.lat !== 'number' || typeof p.lng !== 'number') return;
        var on = self._active === String(p.id);
        var icon = L.divIcon({
          className: 'rm-pin' + (on ? ' rm-on' : ''),
          iconSize: [26, 26],
          iconAnchor: [13, 13],
          html: '<i class="rm-ring"></i><i class="rm-dot"></i>' + (on ? '<span class="rm-num">' + (i + 1) + '</span>' : '')
        });
        var m = L.marker([p.lat, p.lng], { icon: icon, riseOnHover: true, keyboard: false }).addTo(self._layer);
        m.bindPopup(
          '<div class="rm-pop-k">' + esc(p.cat || '') + '</div>' +
          '<div class="rm-pop-t">' + esc(p.title || '') + '</div>' +
          '<div class="rm-pop-m">' + esc(p.venue || '') + (p.when ? ' · ' + esc(p.when) : '') + '</div>' +
          '<div class="rm-pop-b"><span>' + esc(p.price || '') + '</span>' +
          '<button data-rm-open="' + esc(p.id) + '">vedi evento</button></div>',
          { closeButton: true, offset: [0, -6], autoPanPadding: [24, 24] }
        );
        m.on('mouseover', function () { m.openPopup(); });
        m.on('click', function () {
          m.openPopup();
          self.dispatchEvent(new CustomEvent('pin-select', {
            bubbles: true, composed: true, detail: { id: p.id }
          }));
        });
        self._markers[String(p.id)] = m;
        pts.push([p.lat, p.lng]);
      });
      if (this.getAttribute('fit') === '1' && pts.length > 1) {
        this._map.fitBounds(pts, { padding: [56, 56], maxZoom: 14, animate: true });
      }
      this._focus();
    }

    _focus() {
      if (!this._map) return;
      var m = this._active ? this._markers[this._active] : null;
      Object.keys(this._markers).forEach(function (k) {
        var el = this._markers[k].getElement();
        if (!el) return;
        el.classList.toggle('rm-on', k === this._active);
      }, this);
      if (m) {
        this._map.flyTo(m.getLatLng(), Math.max(this._map.getZoom(), 14), { duration: 0.7 });
        m.openPopup();
      }
    }
  }

  if (!customElements.get('radar-map')) customElements.define('radar-map', RadarMap);
})();
