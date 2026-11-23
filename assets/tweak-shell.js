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
    if (!i) return 'Click a block to select it, double-click text to edit it';
    // evive #533: text editing
    if (i.editing) return (i.block || 'block').replace(/^core\//, '') + ': editing text (' +
      (/^core\/(heading|button|list-item|image|details)$/.test(i.block) ? 'Enter' : 'Esc or click outside') + ' ends)';
    var t = i.tweak || {}, parts = [];
    if (i.textChanged) parts.push('text edited');
    if (t.hidden) parts.push('hidden');
    if (t.x || t.y) parts.push('moved ' + (t.x || 0) + ', ' + (t.y || 0));
    if (t.width) parts.push(t.width + '%');
    if (t.top) parts.push(t.top + (t.top === 1 ? ' step' : ' steps') + ' more space at the top'); // evive #532
    if (t.bottom) parts.push(t.bottom + (t.bottom === 1 ? ' step' : ' steps') + ' more space at the bottom');
    return (i.block || 'block').replace(/^core\//, '') + ' (' + i.path + ') on ' + NAMES[i.device] + ': ' +
      (parts.length ? parts.join(', ') : 'not tweaked');
  }

  window.eviveTweakShell = {
    ready: function (frameApi) { api = frameApi; api.setDevice(device); say(''); },
    changed: function (info, count) {
      sel.textContent = describe(info);
      document.querySelectorAll('#evt-tools button').forEach(function (b) { b.disabled = !info; });
      if (info && !info.hasParent) document.querySelector('[data-act="parent"]').disabled = true;
      // evive #532: a section gets Taller/Shorter instead of -/+.
      var section = !!(info && info.section);
      ['smaller', 'bigger'].forEach(function (a) { document.querySelector('[data-act="' + a + '"]').hidden = section; });
      ['shorter', 'taller'].forEach(function (a) { document.querySelector('[data-act="' + a + '"]').hidden = !section; });
      if (section) document.querySelector('[data-act="shorter"]').disabled = !((info.tweak || {}).bottom > 0);
      if (info && (!info.hasText || info.editing)) document.querySelector('[data-act="edittext"]').disabled = true; // evive #533
      var faq = document.querySelector('[data-act="toggleopen"]'); // evive #543
      faq.hidden = !(info && info.details);
      faq.textContent = info && info.open ? 'Close FAQ' : 'Open FAQ';
      faq.setAttribute('aria-pressed', info && info.open ? 'true' : 'false');
      hidePhone.setAttribute('aria-pressed', info && info.phoneHidden ? 'true' : 'false');
      dirty = count;
      save.textContent = count ? 'Save (' + count + ')' : 'Save';
      save.disabled = !count || busy || !cfg.endpoint || done;
      if (count && !cfg.endpoint) say('No evive connection for this link: tweaks cannot be sent.', true);
      pendingButtons();
    }
  };

  // evive #539: the pending copy of a live page. Publish / Discard changes are disabled while a Save is pending
  // (unsaved tweaks or a save running); they post {post_id, site, action} to the same tweak endpoint.
  var publishBtn = document.getElementById('evt-publish');
  var discardBtn = document.getElementById('evt-discard');
  var done = false;
  function pendingButtons() {
    [publishBtn, discardBtn].forEach(function (b) {
      if (!b) return;
      b.disabled = done || busy || dirty > 0 || !cfg.endpoint;
      b.title = dirty > 0 ? 'Save your tweaks first' : '';
    });
  }
  function send(body) {
    return fetch(cfg.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + cfg.token },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.text().then(function (t) {
        var j = null; try { j = JSON.parse(t); } catch (e) { /* not JSON */ }
        if (!r.ok) throw new Error((j && (j.error || j.message)) || ('evive answered ' + r.status));
        return j;
      });
    });
  }
  function pendingAction(action) {
    if (!cfg.pending || !cfg.endpoint || busy || dirty || done) return;
    var url = cfg.pending.live_url || 'the live page';
    var q = action === 'publish'
      ? 'This goes live on ' + url + ' for every visitor. Publish the changes?'
      : 'Discard the pending changes? The live page ' + url + ' stays as it is.';
    if (!window.confirm(q)) return;
    busy = true; save.disabled = true; pendingButtons();
    say(action === 'publish' ? 'Publishing…' : 'Discarding…');
    send({ post_id: cfg.post_id, site: cfg.site, action: action }).then(function (j) {
      busy = false; done = true;
      save.disabled = true;
      document.querySelectorAll('#evt-tools button').forEach(function (b) { b.disabled = true; });
      say(action === 'publish' ? 'Published: the changes are live on ' + url + '.' : 'Discarded: the live page is unchanged.');
      pendingButtons();
      try {
        if (window.parent !== window) window.parent.postMessage({ type: 'evive:pending-' + (action === 'publish' ? 'published' : 'discarded'), post_id: cfg.post_id, live_id: cfg.pending.live_id, result: j }, new URL(cfg.endpoint).origin);
      } catch (e) { /* no evive window */ }
    }).catch(function (err) {
      busy = false;
      save.disabled = !dirty;
      pendingButtons();
      say((action === 'publish' ? 'Not published: ' : 'Not discarded: ') + err.message, true);
    });
  }
  if (publishBtn) publishBtn.addEventListener('click', function () { pendingAction('publish'); });
  if (discardBtn) discardBtn.addEventListener('click', function () { pendingAction('discard'); });

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
    if (a === 'taller') api.space(e.shiftKey ? 5 : 1); // evive #532
    if (a === 'shorter') api.space(e.shiftKey ? -5 : -1);
    if (a === 'hidephone') api.togglePhoneHidden();
    if (a === 'reset') api.reset();
    if (a === 'edittext' && api.editText) api.editText(); // evive #533
    if (a === 'toggleopen' && api.toggleOpen) api.toggleOpen(); // evive #543
  });
  // Keys also work while the focus is on the toolbar.
  document.addEventListener('keydown', function (e) { if (api && api.key(e)) e.preventDefault(); });

  save.addEventListener('click', function () {
    if (!api || !cfg.endpoint || busy || done) return;
    if (api.stopEdit) api.stopEdit(); // evive #533: a text still being edited is part of this save
    var changes = api.changes();
    if (!changes.length) return;
    var body = { post_id: cfg.post_id, site: cfg.site, changes: changes };
    busy = true; save.disabled = true; pendingButtons(); say('Saving…');
    send(body).then(function () {
      busy = false;
      api.saved();
      say('Saved: evive applies it as a new revision.');
      try {
        if (window.parent !== window) window.parent.postMessage({ type: 'evive:tweak-saved', post_id: cfg.post_id, changes: changes }, new URL(cfg.endpoint).origin);
      } catch (e) { /* no evive window */ }
    }).catch(function (err) {
      busy = false;
      save.disabled = !dirty;
      pendingButtons();
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
  pendingButtons(); // evive #539
  say('Loading the page…');
  setDevice('desktop');
})();
