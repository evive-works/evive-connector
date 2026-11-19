/* evive #522: tweak mode toolbar around the preview frame. Desktop/Tablet/Phone resize the frame (the theme's own
 * media queries apply); the buttons act on the block selected in the frame (tweak-frame.js); Save sends the changed
 * blocks to evive (tweak_endpoint, Bearer tweak token) and tells the evive window (postMessage). evive applies them
 * through the evive MCP as a new revision; this page never writes to WordPress itself. */
(function () {
  'use strict';
  var cfg = window.eviveTweakConfig || {};
  var widths = cfg.widths || { desktop: 1280, tablet: 820, phone: 390 };
  var frame = document.getElementById('evt-frame');
  var stage = document.getElementById('evt-stage');
  var sel = document.getElementById('evt-sel');
  var save = document.getElementById('evt-save');
  var status = document.getElementById('evt-status');
  var hidePhone = document.querySelector('[data-act="hidephone"]');
  var api = null, device = 'desktop', dirty = 0, busy = false;
  var NAMES = { desktop: 'desktop', tablet: 'tablet', phone: 'phone' };

  function say(t, err) { status.textContent = t || ''; status.className = err ? 'evt-err' : ''; }

  function layout() {
    var w = widths[device] || 1280;
    var avail = stage.clientWidth - 16;
    var scale = Math.min(1, avail / w);
    frame.style.width = w + 'px';
    frame.style.height = Math.floor((stage.clientHeight - 8) / scale) + 'px';
    frame.style.transform = scale < 1 ? 'scale(' + scale + ')' : '';
    frame.style.marginLeft = scale < 1 ? '8px' : 'auto';
    frame.style.marginRight = scale < 1 ? '0' : 'auto';
  }

  function setDevice(d) {
    device = d;
    document.querySelectorAll('[data-device]').forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-device') === d ? 'true' : 'false');
    });
    layout();
    if (api) api.setDevice(d);
  }

  function describe(i) {
    if (!i) return 'Click a block to select it';
    var t = i.tweak || {}, parts = [];
    if (t.hidden) parts.push('hidden');
    if (t.x || t.y) parts.push('moved ' + (t.x || 0) + ', ' + (t.y || 0));
    if (t.width) parts.push(t.width + '%');
    return (i.block || 'block').replace(/^core\//, '') + ' (' + i.path + ') on ' + NAMES[i.device] + ': ' +
      (parts.length ? parts.join(', ') : 'not tweaked');
  }

  window.eviveTweakShell = {
    ready: function (frameApi) { api = frameApi; api.setDevice(device); say(''); },
    changed: function (info, count) {
      sel.textContent = describe(info);
      document.querySelectorAll('#evt-tools button').forEach(function (b) { b.disabled = !info; });
      if (info && !info.hasParent) document.querySelector('[data-act="parent"]').disabled = true;
      hidePhone.setAttribute('aria-pressed', info && info.phoneHidden ? 'true' : 'false');
      dirty = count;
      save.textContent = count ? 'Save (' + count + ')' : 'Save';
      save.disabled = !count || busy || !cfg.endpoint;
      if (count && !cfg.endpoint) say('No evive connection for this link: tweaks cannot be sent.', true);
    }
  };

  document.getElementById('evt-devices').addEventListener('click', function (e) {
    var d = e.target.getAttribute && e.target.getAttribute('data-device');
    if (d) setDevice(d);
  });
  document.getElementById('evt-tools').addEventListener('click', function (e) {
    var a = e.target.getAttribute && e.target.getAttribute('data-act');
    if (!a || !api) return;
    if (a === 'parent') api.selectParent();
    if (a === 'smaller') api.resize(-5);
    if (a === 'bigger') api.resize(5);
    if (a === 'hidephone') api.togglePhoneHidden();
    if (a === 'reset') api.reset();
  });
  // Keys also work while the focus is on the toolbar.
  document.addEventListener('keydown', function (e) { if (api && api.key(e)) e.preventDefault(); });

  save.addEventListener('click', function () {
    if (!api || !cfg.endpoint || busy) return;
    var changes = api.changes();
    if (!changes.length) return;
    var body = { post_id: cfg.post_id, site: cfg.site, changes: changes };
    busy = true; save.disabled = true; say('Saving…');
    fetch(cfg.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + cfg.token },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.text().then(function (t) {
        var j = null; try { j = JSON.parse(t); } catch (e) { /* not JSON */ }
        if (!r.ok) throw new Error((j && (j.error || j.message)) || ('evive answered ' + r.status));
        return j;
      });
    }).then(function () {
      busy = false;
      api.saved();
      say('Saved: evive applies it as a new revision.');
      try {
        if (window.parent !== window) window.parent.postMessage({ type: 'evive:tweak-saved', post_id: cfg.post_id, changes: changes }, new URL(cfg.endpoint).origin);
      } catch (e) { /* no evive window */ }
    }).catch(function (err) {
      busy = false;
      save.disabled = !dirty;
      say('Not saved: ' + err.message, true);
    });
  });

  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  window.addEventListener('resize', layout);
  if (cfg.expires) {
    var t = new Date(cfg.expires * 1000);
    save.title = 'This tweak link works until ' + t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }
  document.querySelectorAll('#evt-tools button').forEach(function (b) { b.disabled = true; });
  say('Loading the page…');
  setDevice('desktop');
})();
