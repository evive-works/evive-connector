/* evive #522: tweak mode toolbar around the preview frame. Desktop/Tablet/Phone resize the frame (the theme's own
 * media queries apply); the buttons act on the block selected in the frame (tweak-frame.js); Save sends the changed
 * blocks to evive (tweak_endpoint, Bearer tweak token) and tells the evive window (postMessage). evive applies them
 * through the evive MCP as a new revision; this page never writes to WordPress itself.
 * evive #549: Change layout (a section) and Replace image (an image block) open a panel next to the frame. Layouts and
 * the conversion come from evive (/api/tweak/layouts, /api/tweak/convert), the preview is rendered by the site in the
 * frame; uploads go through evive (/api/tweak/upload), the media library is read from the site (evive/v1/tweak/media).
 * Nothing is saved until Save.
 * evive #556: an Elementor page (cfg.builder = 'elementor') has the overlay tweak-elementor.js in the frame: elements by
 * their Elementor id, width -/+, Taller/Shorter (sections, containers), Hide on phone / tablet, Move up / down, text in
 * place; no layouts or images. Save sends {post_id, site, base_hash, changes} (the evive MCP's change set, #554/#555);
 * "page changed" (409) asks for a reload, because the page was saved elsewhere after this view read it.
 * evive #575: tweak basics on block pages: Layer menu (front / forward / backward / back / normal, this screen size),
 * Style menu (corners, shadow, opacity: all screen sizes), Align left / center / right (a narrower block, this screen
 * size), Crop menu (shape + focal point: click the image; images only, this screen size), Duplicate and Delete (with a
 * confirm; on Save). The menus are buttons with arrow keys, Home / End, Esc. A Save with a duplicate or delete reads
 * the page again (the app answers reload: the block paths after it shifted).
 * evive #578: the same basics on Elementor pages (tweak-elementor.js keeps them in Elementor's own settings, MCP #576):
 * Corners in the Style menu go to desktop (tablet and mobile follow it), shadow and opacity are one value for all devices
 * (opacity only for images), Align for elements Elementor can align, Crop for image widgets (the shape becomes a height,
 * the focal point one of Elementor's nine positions), Delete has no undo after Save. A Save with a duplicate or delete
 * (reload: true) or a partly saved one reads the page again.
 * evive #581: a block moved or brought to front that a block around it cuts off (a cover, a rounded group) says so in
 * the status ("cut off by the photo block") and gets Let it stick out (clip "visible" on that block, this screen size).
 * evive #582: a resize says when the row gets wider than its container and which block around it grows with it ("the
 * photo block grows with it"); Overlay (block pages, this screen size) takes the block out of the layout where it is. */
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
  var lastInfo = null; // evive #549: the selected block, for the panels
  var appBase = cfg.endpoint ? String(cfg.endpoint).replace(/\/apply\/?$/, '') : ''; // https://app/api/tweak
  var NAMES = { desktop: 'desktop', tablet: 'tablet', phone: 'phone' };
  var ELT = cfg.builder === 'elementor'; // evive #556
  var reloadNeeded = false; // evive #556: the page was saved elsewhere; Save becomes Reload
  var afterReady = ''; // evive #575: the status to show when the frame has loaded again

  function say(t, err) { status.textContent = t || ''; status.className = err ? 'evt-err' : ''; }

  function layout() {
    var w = widths[device] || 1280;
    // evive #549: the panel takes the right side of the stage (on a phone: its bottom part).
    var p = document.getElementById('evt-panel'), open = p && !p.hidden, side = open && window.innerWidth > 760;
    var avail = stage.clientWidth - 16 - (side ? p.offsetWidth + 8 : 0);
    var high = stage.clientHeight - 8 - (open && !side ? p.offsetHeight : 0);
    var scale = Math.min(1, avail / w);
    frame.style.width = w + 'px';
    frame.style.height = Math.floor(Math.max(120, high) / scale) + 'px';
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
    if (ELT) return describeElt(i); // evive #556
    if (!i) return 'Click a block to select it, double-click text to edit it';
    // evive #533: text editing
    if (i.editing) return (i.block || 'block').replace(/^core\//, '') + ': editing text (' +
      (/^core\/(heading|button|list-item|image|details)$/.test(i.block) ? 'Enter' : 'Esc or click outside') + ' ends)';
    var t = i.tweak || {}, parts = [];
    if (i.layout && i.top) parts.push((i.layout.state === 'preview' ? 'preview of ' : 'new layout ') + (i.layout.title || '')); // evive #549
    if (i.imageChanged) parts.push('image replaced');
    if (i.textChanged) parts.push('text edited');
    if (t.hidden) parts.push('hidden');
    if (t.x || t.y) parts.push('moved ' + (t.x || 0) + ', ' + (t.y || 0));
    if (t.width) parts.push(t.width + '%');
    if (t.top) parts.push(t.top + (t.top === 1 ? ' step' : ' steps') + ' more space at the top'); // evive #532
    if (t.bottom) parts.push(t.bottom + (t.bottom === 1 ? ' step' : ' steps') + ' more space at the bottom');
    // evive #575
    if (t.z) parts.push(t.z === 5 ? 'in front' : t.z === -5 ? 'at the back' : 'layer ' + (t.z > 0 ? '+' : '') + t.z);
    if (t.radius) parts.push({ none: 'square corners', round: 'round corners' }[t.radius] || 'corners ' + { s: 'small', m: 'medium', l: 'large' }[t.radius]);
    if (t.shadow) parts.push(t.shadow === 'none' ? 'no shadow' : t.shadow + ' shadow');
    if (t.opacity) parts.push(t.opacity + '% opacity');
    if (t.align) parts.push('aligned ' + t.align);
    if (t.aspect) parts.push('cropped ' + t.aspect.replace('-', ':'));
    if (t.focus) parts.push('focal point ' + t.focus.x + '%, ' + t.focus.y + '%');
    if (i.picking) parts.push('click the image to set its focal point (Esc cancels)');
    if (i.structural) parts.push(i.structural === 'delete' ? 'deleted on Save' : 'duplicated on Save');
    if (t.clip) parts.push('lets blocks stick out'); // evive #581
    if (t.overlay) parts.push('overlay (out of the layout)'); // evive #582
    // evive #582: first (after a cut-off note), so they stay readable on a phone
    if (i.grows) parts.unshift(i.grows.name + ' grows with it');
    if (i.wider) parts.unshift('wider than its container');
    // evive #581: first, so it stays readable on a phone (the status line is cut off at the end)
    if (i.clippedBy) parts.unshift('cut off by ' + i.clippedBy.name);
    else if (i.sticksOutOf) parts.unshift('sticks out of ' + i.sticksOutOf.name);
    return (i.block || 'block').replace(/^core\//, '') + ' (' + i.path + ') on ' + NAMES[i.device] + ': ' +
      (parts.length ? parts.join(', ') : 'not tweaked');
  }

  // evive #556: an Elementor element and what was changed on this device.
  function describeElt(i) {
    if (!i) return 'Click an element to select it, double-click text to edit it';
    if (i.editing) return i.label + ': editing text (Esc or click outside ends)';
    return i.label + ' on ' + i.device + ': ' + (i.summary && i.summary.length ? i.summary.join(', ') : 'not tweaked');
  }

  window.eviveTweakShell = {
    ready: function (frameApi) { api = frameApi; api.setDevice(device); say(afterReady); afterReady = ''; }, // evive #575: afterReady
    hint: function (t) { if (!busy && !reloadNeeded) say(t || ''); }, // evive #556: e.g. "the header is not part of this page"
    dropImage: function (path, file) { openImage(path, file); }, // evive #549: a file dropped on an image in the frame
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
      // evive #543: always there with the same label (pressed = open), so the toolbar never re-wraps between the two
      // clicks of a double-click (on a phone the frame would move and the second click hit another block).
      var faq = document.querySelector('[data-act="toggleopen"]');
      faq.disabled = !(info && info.details);
      faq.setAttribute('aria-pressed', info && info.details && info.open ? 'true' : 'false');
      hidePhone.setAttribute('aria-pressed', info && info.phoneHidden ? 'true' : 'false');
      // evive #549: Change layout for a top-level section, Replace image for an image block (always in the bar).
      lastInfo = info;
      document.querySelector('[data-act="layout"]').disabled = !(info && info.top) || !appBase || done;
      document.querySelector('[data-act="image"]').disabled = !(info && info.image) || !appBase || done;
      if (ELT) eltButtons(info); // evive #556
      basicButtons(info); // evive #575; evive #578: Elementor too
      dirty = count;
      save.textContent = reloadNeeded ? 'Reload page' : count ? 'Save (' + count + ')' : 'Save';
      save.disabled = reloadNeeded ? busy : (!count || busy || !cfg.endpoint || done || !!previewing);
      if (count && !cfg.endpoint) say('No evive connection for this link: tweaks cannot be sent.', true);
      pendingButtons();
    }
  };

  // evive #556: the toolbar of an Elementor element: -/+ when it has a width of its own (not a full-width section),
  // Taller/Shorter for sections and containers, Hide on phone / tablet (not columns), Move up / down within the parent.
  function eltButtons(info) {
    function b(a) { return document.querySelector('[data-act="' + a + '"]'); }
    ['smaller', 'bigger'].forEach(function (a) { b(a).hidden = !!info && !info.hasWidth; });
    ['shorter', 'taller'].forEach(function (a) { b(a).hidden = !(info && info.section); });
    if (!info) return;
    b('shorter').disabled = !info.shorter;
    b('hidephone').disabled = b('hidetablet').disabled = !info.canHide;
    b('hidetablet').setAttribute('aria-pressed', info.tabletHidden ? 'true' : 'false');
    b('moveup').disabled = !info.canUp;
    b('movedown').disabled = !info.canDown;
    if (info.editing) ['smaller', 'bigger', 'shorter', 'taller', 'hidephone', 'hidetablet', 'moveup', 'movedown', 'parent', 'reset'].forEach(function (a) { b(a).disabled = true; });
  }

  // ── Tweak basics (evive #575) ──────────────────────────────────────────
  function btn(a, v) { return document.querySelector('[data-act="' + a + '"]' + (v ? '[data-val="' + v + '"]' : '')); }
  function basicButtons(info) {
    if (!btn('duplicate')) return;
    var t = (info && info.tweak) || {}, off = !info || !!info.editing || done;
    ['left', 'center', 'right'].forEach(function (v) {
      var b = btn('align', v);
      b.disabled = off || !info.narrow;
      b.setAttribute('aria-pressed', t.align === v ? 'true' : 'false');
    });
    document.querySelectorAll('[data-act="menu"]').forEach(function (b) { b.disabled = off; });
    document.querySelector('[data-menu="crop"]').disabled = off || !info.crop;
    btn('duplicate').disabled = off || !(info.structural === 'duplicate' || (!info.structural && info.canStructural));
    btn('delete').disabled = off || !(info.structural === 'delete' || (!info.structural && info.canStructural));
    btn('duplicate').setAttribute('aria-pressed', info && info.structural === 'duplicate' ? 'true' : 'false');
    btn('delete').setAttribute('aria-pressed', info && info.structural === 'delete' ? 'true' : 'false');
    btn('duplicate').title = info && !info.canStructural && !info.structural ? (ELT ? 'An element around it is deleted on Save' : 'Duplicate needs the saved block: Save or Reset a new layout or image in this section first') : 'Put a copy of this ' + (ELT ? 'element' : 'block') + ' right after it (on Save; click again to undo)';
    btn('delete').title = info && !info.canStructural && !info.structural ? (ELT ? 'An element around it is deleted on Save' : 'Delete needs the saved block: Save or Reset a new layout or image in this section first') : ELT ? 'Remove this element from the page (on Save; this cannot be undone in tweak mode)' : 'Remove this block from the page (on Save; undo through the revision in evive)';
    if (ELT) { // evive #578: Elementor's own z-index (not kept within a section) and alignment
      document.querySelector('[data-menu="layer"]').title = 'Bring this element to the front or send it to the back (Elementor\'s z-index, this device)';
      ['left', 'center', 'right'].forEach(function (v) { btn('align', v).title = info && !info.narrow ? 'Elementor can align headings, images, text editors and icons, and elements inside a flex container' : 'Align ' + v + ' (this device)'; });
    }
    if (menu) { if (off || menu.btn.disabled) closeMenu(false); else fillMenu(); }
    var so = btn('stickout'); // evive #581: only when a block around it cuts it off
    if (so) {
      so.hidden = !(info && info.clippedBy);
      so.disabled = off || !(info && info.clippedBy);
      so.title = info && info.clippedBy ? 'Let this block stick out of ' + info.clippedBy.name + ' on ' + NAMES[device] + ' (Reset on that block undoes it)' : '';
    }
    var ov = btn('overlay'); // evive #582: block pages only (hidden on Elementor pages)
    if (ov && !ELT) {
      ov.disabled = off || !(info && (info.canOverlay || info.overlay));
      ov.setAttribute('aria-pressed', info && info.overlay ? 'true' : 'false');
    }
  }

  // The menus: {btn, pop, name}. Items: [label, action, checked (a boolean: a choice; null: an action), disabled] or a
  // group title (string).
  var menu = null;
  var DEV = function () { return NAMES[device] + (ELT && device === 'desktop' ? ' (tablet and phone follow)' : ''); }; // evive #578: as in Elementor
  function menuItems(name, i) {
    var t = (i && i.tweak) || {}, z = t.z || 0;
    if (name === 'layer') return [
      'Layer on ' + DEV() + (z ? ' (' + (z > 0 ? '+' : '') + z + ')' : ''),
      ['Bring to front', function () { api.layer('front'); }, z === 5],
      ['Bring forward', function () { api.layer('up'); }, null, z >= 5],
      ['Send backward', function () { api.layer('down'); }, null, z <= -5],
      ['Send to back', function () { api.layer('back'); }, z === -5],
      ['Normal layer', function () { api.layer('normal'); }, !z]
    ];
    if (name === 'style' && ELT) { // evive #578: Elementor's defaults; opacity only for images
      var es = function (k, v) { return function () { api.setBasic(k, v, true); }; }, noOp = !(i && i.canOpacity);
      return [
        'Corners (all devices)' + (t.radius === 'custom' ? ': own value' : ''),
        ['Default', es('radius', null), !t.radius], ['Square', es('radius', 'none'), t.radius === 'none'], ['Small', es('radius', 's'), t.radius === 's'],
        ['Medium', es('radius', 'm'), t.radius === 'm'], ['Large', es('radius', 'l'), t.radius === 'l'], ['Round', es('radius', 'round'), t.radius === 'round'],
        'Shadow (all devices)' + (t.shadow === 'custom' ? ': own value' : ''),
        ['None', es('shadow', null), !t.shadow], ['Soft', es('shadow', 'soft'), t.shadow === 'soft'], ['Medium', es('shadow', 'medium'), t.shadow === 'medium'],
        noOp ? 'Opacity (images only in Elementor)' : 'Opacity (all devices)' + (t.opacity === 'custom' ? ': own value' : ''),
        ['100%', es('opacity', null), !t.opacity, noOp], ['80%', es('opacity', 80), t.opacity === 80, noOp], ['60%', es('opacity', 60), t.opacity === 60, noOp], ['40%', es('opacity', 40), t.opacity === 40, noOp]
      ];
    }
    if (name === 'style') {
      var set = function (k, v) { return function () { api.setBasic(k, v, true); }; };
      return [
        'Corners (all screen sizes)',
        ['Theme', set('radius', null), !t.radius], ['Square', set('radius', 'none'), t.radius === 'none'], ['Small', set('radius', 's'), t.radius === 's'],
        ['Medium', set('radius', 'm'), t.radius === 'm'], ['Large', set('radius', 'l'), t.radius === 'l'], ['Round', set('radius', 'round'), t.radius === 'round'],
        'Shadow',
        ['Theme', set('shadow', null), !t.shadow], ['None', set('shadow', 'none'), t.shadow === 'none'], ['Soft', set('shadow', 'soft'), t.shadow === 'soft'], ['Medium', set('shadow', 'medium'), t.shadow === 'medium'],
        'Opacity',
        ['100%', set('opacity', null), !t.opacity], ['80%', set('opacity', 80), t.opacity === 80], ['60%', set('opacity', 60), t.opacity === 60], ['40%', set('opacity', 40), t.opacity === 40]
      ];
    }
    var crop = function (v) { return function () { api.setBasic('aspect', v); }; };
    return [
      'Crop on ' + DEV(),
      ['Original', crop(null), !t.aspect], ['16:9', crop('16-9'), t.aspect === '16-9'], ['4:3', crop('4-3'), t.aspect === '4-3'],
      ['1:1', crop('1-1'), t.aspect === '1-1'], ['3:4', crop('3-4'), t.aspect === '3-4'],
      ELT && t.cropped && t.aspect === 'custom' ? 'Cropped to ' + t.cropped.height + (t.cropped.unit || 'px') + ' high' : null, // evive #578
      'Focal point' + (t.focus ? ' (' + (ELT ? FOCUS_WORDS(t.focus) : t.focus.x + '%, ' + t.focus.y + '%') + ')' : ''),
      [i && i.picking ? 'Cancel picking' : 'Set focal point: click the image', function () { api.pickFocus(); closeMenu(false); }, null, ELT && !t.cropped && !(i && i.picking)],
      ['Clear focal point', function () { api.setBasic('focus', null); }, null, !t.focus]
    ];
  }
  function FOCUS_WORDS(f) { return ['top', 'center', 'bottom'][f.y / 50] + ' ' + ['left', 'center', 'right'][f.x / 50]; } // evive #578
  function fillMenu() {
    var i = api && api.info ? api.info() : lastInfo, items = menuItems(menu.name, i);
    var focused = menu.pop.contains(document.activeElement) ? Array.prototype.indexOf.call(menu.pop.querySelectorAll('[role^="menuitem"]'), document.activeElement) : -1;
    menu.pop.innerHTML = '';
    menu.actions = [];
    items.forEach(function (it) {
      if (it === null) return; // evive #578: an item that does not apply
      if (typeof it === 'string') {
        var h = document.createElement('div'); h.className = 'evt-mh'; h.setAttribute('role', 'presentation'); h.textContent = it;
        menu.pop.appendChild(h); return;
      }
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', typeof it[2] === 'boolean' ? 'menuitemradio' : 'menuitem');
      if (typeof it[2] === 'boolean') b.setAttribute('aria-checked', it[2] ? 'true' : 'false');
      b.textContent = it[0];
      b.tabIndex = -1;
      if (it[3]) b.disabled = true;
      b.setAttribute('data-mi', menu.actions.push(it[1]) - 1);
      menu.pop.appendChild(b);
    });
    var list = menu.pop.querySelectorAll('[role^="menuitem"]');
    if (focused >= 0 && list[focused]) { if (list[focused].disabled) focused = -1; else list[focused].focus(); }
    return list;
  }
  function openMenu(b) {
    if (menu) closeMenu(false);
    if (!api || b.disabled) return;
    var pop = document.createElement('div');
    pop.className = 'evt-pop'; pop.setAttribute('role', 'menu'); pop.setAttribute('aria-label', b.textContent);
    pop.id = 'evt-m-' + b.getAttribute('data-menu');
    b.parentNode.appendChild(pop);
    b.setAttribute('aria-expanded', 'true'); b.setAttribute('aria-controls', pop.id);
    menu = { btn: b, pop: pop, name: b.getAttribute('data-menu') };
    var list = fillMenu();
    // Keep the menu on the screen (the bar wraps on a phone).
    var r = pop.getBoundingClientRect();
    if (r.right > window.innerWidth - 8) { pop.style.left = 'auto'; pop.style.right = '0'; }
    var first = Array.prototype.filter.call(list, function (x) { return !x.disabled && x.getAttribute('aria-checked') === 'true'; })[0] ||
      Array.prototype.filter.call(list, function (x) { return !x.disabled; })[0];
    if (first) first.focus();
  }
  function closeMenu(focusBtn) {
    if (!menu) return;
    var m = menu; menu = null;
    m.btn.setAttribute('aria-expanded', 'false');
    m.pop.remove();
    if (focusBtn) m.btn.focus();
  }
  function menuKey(e) {
    var list = Array.prototype.filter.call(menu.pop.querySelectorAll('[role^="menuitem"]'), function (x) { return !x.disabled; });
    var at = list.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { (list[(at + 1) % list.length] || list[0]).focus(); return true; }
    if (e.key === 'ArrowUp') { (list[(at - 1 + list.length) % list.length] || list[0]).focus(); return true; }
    if (e.key === 'Home') { list[0].focus(); return true; }
    if (e.key === 'End') { list[list.length - 1].focus(); return true; }
    if (e.key === 'Escape') { closeMenu(true); return true; }
    if (e.key === 'Tab') { closeMenu(false); return false; }
    return false;
  }
  document.getElementById('evt-tools').addEventListener('click', function (e) {
    var mi = e.target.closest && e.target.closest('[data-mi]');
    if (!mi || !menu || mi.disabled) return;
    e.stopPropagation();
    var f = menu.actions[Number(mi.getAttribute('data-mi'))];
    if (f && api) f();
    if (menu) fillMenu(); // stays open: the checks follow (Esc or a click outside closes it)
  }, true);
  document.addEventListener('mousedown', function (e) { if (menu && !menu.pop.contains(e.target) && e.target !== menu.btn) closeMenu(false); });
  window.addEventListener('blur', function () { if (menu && document.activeElement === frame) closeMenu(false); }); // a click in the frame
  function deleteBlock() {
    var i = api && api.info();
    if (!i) return;
    if (i.structural === 'delete') { api.remove(); return; } // undo: no question
    var n = i.inner || 0;
    if (ELT) { // evive #578: an Elementor delete has no undo in evive
      if (!window.confirm('Delete ' + (i.label || 'this element') + (n ? ' and the ' + n + ' element' + (n === 1 ? '' : 's') + ' inside it' : '') +
        ' from the page? It is removed when you Save, and evive cannot undo that (Elementor\'s own revisions can).')) return;
      api.remove();
      return;
    }
    if (!window.confirm('Delete this ' + (i.block || 'block').replace(/^core\//, '') + ' (' + i.path + ')' + (n ? ' and the ' + n + ' block' + (n === 1 ? '' : 's') + ' inside it' : '') +
      ' from the page? It is removed when you Save; evive keeps the earlier version as a revision (undo).')) return;
    api.remove();
  }

  // evive #539: the pending copy of a live page. Publish / Discard changes are disabled while a Save is pending
  // (unsaved tweaks or a save running); they post {post_id, site, action} to the same tweak endpoint.
  var publishBtn = document.getElementById('evt-publish');
  var discardBtn = document.getElementById('evt-discard');
  var done = false;
  function pendingButtons() {
    [publishBtn, discardBtn].forEach(function (b) {
      if (!b) return;
      b.disabled = done || busy || dirty > 0 || !cfg.endpoint || !!previewing; // evive #549: not during a layout preview
      b.title = dirty > 0 ? 'Save your tweaks first' : previewing ? 'Use the new layout or Cancel it first' : '';
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
        if (!r.ok) {
          var err = new Error((j && (j.error || j.message)) || ('evive answered ' + r.status));
          err.status = r.status; err.code = j && j.code; // evive #556: page_changed
          err.partly = j && j.partly_saved ? j.partly_saved : null; // evive #578 (app #577): what was saved before the error
          throw err;
        }
        return j;
      });
    });
  }
  function pendingAction(action) {
    if (!cfg.pending || !cfg.endpoint || busy || dirty || done || previewing) return;
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
    var a = e.target.closest && e.target.closest('[data-act]') ? e.target.closest('[data-act]').getAttribute('data-act') : null; // evive #575: also a click on an icon
    if (!a || !api) return;
    if (a === 'parent') api.selectParent();
    if (a === 'smaller') api.resize(-5);
    if (a === 'bigger') api.resize(5);
    if (a === 'taller') api.space(e.shiftKey ? 5 : 1); // evive #532
    if (a === 'shorter') api.space(e.shiftKey ? -5 : -1);
    if (a === 'hidephone') api.togglePhoneHidden();
    if (a === 'hidetablet' && api.toggleTabletHidden) api.toggleTabletHidden(); // evive #556
    if (a === 'moveup' && api.move) api.move('up');
    if (a === 'movedown' && api.move) api.move('down');
    if (a === 'reset') api.reset();
    if (a === 'edittext' && api.editText) api.editText(); // evive #533
    if (a === 'toggleopen' && api.toggleOpen) api.toggleOpen(); // evive #543
    if (a === 'layout') openLayout(); // evive #549
    if (a === 'image') openImage();
    // evive #575
    if (a === 'menu') { if (menu && menu.btn === e.target.closest('[data-act]')) closeMenu(true); else openMenu(e.target.closest('[data-act]')); }
    if (a === 'align') { var v = e.target.closest('[data-act]').getAttribute('data-val'), i = api.info(); api.setBasic('align', i && i.tweak && i.tweak.align === v ? null : v); }
    if (a === 'duplicate') api.duplicate();
    if (a === 'stickout' && api.stickOut) api.stickOut(); // evive #581
    if (a === 'overlay' && api.overlay) api.overlay(); // evive #582
    if (a === 'delete') deleteBlock();
  });
  // Keys also work while the focus is on the toolbar (not in the panel: its fields type, Esc closes it).
  document.addEventListener('keydown', function (e) {
    if (menu && menu.pop.contains(e.target)) { if (menuKey(e)) e.preventDefault(); return; } // evive #575
    if (menu && e.key === 'Escape') { e.preventDefault(); closeMenu(true); return; }
    if (menu && e.key === 'ArrowDown' && e.target === menu.btn) { e.preventDefault(); var f = menu.pop.querySelector('[role^="menuitem"]:not([disabled])'); if (f) f.focus(); return; }
    if (panel.contains(e.target)) {
      if (e.key === 'Escape') { e.preventDefault(); closePanel(true); }
      return;
    }
    if (e.key === 'Escape' && !panel.hidden && !(api && api.info && api.info())) { e.preventDefault(); closePanel(true); return; }
    if (api && api.key(e)) e.preventDefault();
  });

  // ── Panels: Change layout and Replace image (evive #549) ───────────────
  var panel = document.getElementById('evt-panel');
  var pstate = null;    // {kind: 'layout'|'image', path, ...}
  var previewing = null; // {path, title} while a layout preview is shown in the frame (Save waits for Use / Cancel)
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function appCall(method, path, body) {
    var h = { 'Authorization': 'Bearer ' + cfg.token };
    if (body && !(body instanceof FormData)) { h['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
    return fetch(appBase + '/' + path, { method: method, headers: h, body: body }).then(function (r) {
      return r.text().then(function (t) {
        var j = null; try { j = JSON.parse(t); } catch (e) { /* not JSON */ }
        if (!r.ok) throw new Error((j && (j.message && j.error === 'insufficient_balance' ? j.message : (j.error || j.message))) || ('evive answered ' + r.status));
        return j;
      });
    });
  }
  function siteCall(path) {
    return fetch(cfg.rest + path, { credentials: 'omit', headers: { 'X-Evive-Tweak': cfg.token } }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (j) {
        if (!r.ok || !j) throw new Error((j && j.message) || ('the site answered ' + r.status));
        return j;
      });
    });
  }
  function openPanel(kind, path, title) {
    if (pstate) closePanel(true);
    pstate = { kind: kind, path: String(path) };
    say(''); // an earlier message (e.g. "Save or Reset … first") no longer applies
    panel.innerHTML = '<div class="evt-ph"><h2 id="evt-panel-title"></h2><button type="button" class="evt-x" data-p="close" aria-label="Close">&times;</button></div>' +
      '<div class="evt-pb"></div><div class="evt-pf"></div><p class="evt-pmsg" role="status"></p>';
    panel.querySelector('h2').textContent = title;
    panel.hidden = false;
    document.body.classList.add('evt-panel-open');
    layout();
    return panel.querySelector('.evt-pb');
  }
  function pmsg(t, err) { var m = panel.querySelector('.evt-pmsg'); if (m) { m.textContent = t || ''; m.className = 'evt-pmsg' + (err ? ' evt-err' : ''); } }
  function closePanel(cancel) {
    if (!pstate) { panel.hidden = true; return; }
    if (pstate.kind === 'layout' && previewing && cancel && api) { api.cancelLayout(previewing.path); previewing = null; }
    pstate = null;
    panel.hidden = true;
    panel.innerHTML = '';
    document.body.classList.remove('evt-panel-open');
    layout();
    if (api && api.refresh) api.refresh();
  }
  panel.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-p]');
    if (!b || b.disabled) return;
    var a = b.getAttribute('data-p');
    if (a === 'close' || a === 'cancel') closePanel(true);
    if (a === 'done') closePanel(false);
    if (a === 'use') useLayout();
    if (a === 'pick') pickLayout(b.getAttribute('data-name'), b.getAttribute('data-title'));
    if (a === 'choose') panel.querySelector('input[type="file"]').click();
    if (a === 'lib') pickMedia(Number(b.getAttribute('data-i')));
    if (a === 'more') loadLib(pstate.search || '', (pstate.page || 1) + 1);
    if (a === 'search') loadLib(panel.querySelector('.evt-search').value, 1);
  });

  // Change layout: the layouts that fit (from evive), a click converts and previews, Use keeps it, Cancel goes back.
  function openLayout() {
    var i = lastInfo;
    if (!i || !i.top || !api || !appBase) return;
    if (api.sectionDirty(i.path)) { say('Save or Reset the changes in this section first: a new layout is made from the saved section.', true); return; }
    var body = openPanel('layout', i.path, 'Change layout of section ' + i.path);
    body.innerHTML = '<p class="evt-muted">Looking for the layouts that fit this section…</p>';
    appCall('GET', 'layouts?path=' + encodeURIComponent(i.path)).then(function (j) {
      if (!pstate || pstate.kind !== 'layout') return;
      pstate.hash = j.hash; pstate.block = j.block;
      var html = '<p class="evt-muted">Layouts of the theme that can hold all of this section\'s content. Click one to see it in the page.</p><div class="evt-lays">';
      (j.layouts || []).forEach(function (l) {
        html += '<button type="button" class="evt-lay" data-p="pick" data-name="' + esc(l.name) + '" data-title="' + esc(l.title) + '"' +
          (l.current ? ' disabled aria-current="true"' : '') + ' title="' + esc(l.description || l.title) + '">' +
          (l.thumbnail ? '<img src="' + esc(l.thumbnail) + '" alt="">' : '') + '<span>' + esc(l.title) + (l.current ? ' <em>(now)</em>' : '') + '</span></button>';
      });
      html += '</div>';
      if (!(j.layouts || []).some(function (l) { return !l.current; })) html += '<p>No other layout of the theme can hold this content.</p>';
      if ((j.not_offered || []).length) {
        html += '<details class="evt-not"><summary>Not offered (' + j.not_offered.length + ')</summary><ul>' +
          j.not_offered.map(function (n) { return '<li><b>' + esc(n.title) + '</b>: ' + esc(n.reason) + '</li>'; }).join('') + '</ul></details>';
      }
      body.innerHTML = html;
      panel.querySelector('.evt-pf').innerHTML = '<button type="button" data-p="cancel">Cancel</button>';
      var first = body.querySelector('.evt-lay:not([disabled])');
      if (first) first.focus();
    }).catch(function (err) { if (pstate) body.innerHTML = '<p class="evt-err">' + esc(err.message) + '</p>'; });
  }
  function pickLayout(name, title) {
    if (!pstate || pstate.kind !== 'layout' || pstate.busy) return;
    var path = pstate.path;
    pstate.busy = true;
    panel.querySelectorAll('.evt-lay').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-name') === name ? 'true' : 'false'); b.classList.add('evt-wait'); });
    panel.querySelector('.evt-pf').innerHTML = '<span class="evt-spin" aria-hidden="true"></span><span>evive is converting the section to ' + esc(title) + '… (a few seconds)</span>';
    pmsg('');
    appCall('POST', 'convert', { path: path, pattern: name, before_hash: pstate.hash }).then(function (j) {
      return api.previewLayout(path, j.markup, { before_hash: j.before_hash, block: j.before_block, title: j.title || title }).then(function () { return j; });
    }).then(function (j) {
      if (!pstate) return;
      pstate.busy = false;
      previewing = { path: path, title: j.title || title };
      panel.querySelectorAll('.evt-lay').forEach(function (b) { b.classList.remove('evt-wait'); });
      panel.querySelector('.evt-pf').innerHTML = '<button type="button" class="evt-primary" data-p="use">Use this layout</button><button type="button" data-p="cancel">Cancel</button>';
      pmsg('The page shows ' + (j.title || title) + ' with the section\'s text. Use it, pick another one, or Cancel.');
      panel.querySelector('[data-p="use"]').focus();
      if (api.refresh) api.refresh();
    }).catch(function (err) {
      if (!pstate) return;
      pstate.busy = false;
      panel.querySelectorAll('.evt-lay').forEach(function (b) { b.classList.remove('evt-wait'); b.setAttribute('aria-pressed', previewing && b.getAttribute('data-title') === previewing.title ? 'true' : 'false'); });
      panel.querySelector('.evt-pf').innerHTML = previewing
        ? '<button type="button" class="evt-primary" data-p="use">Use this layout</button><button type="button" data-p="cancel">Cancel</button>'
        : '<button type="button" data-p="cancel">Cancel</button>';
      pmsg(err.message, true);
    });
  }
  function useLayout() {
    if (!previewing || !api) return;
    api.useLayout(previewing.path);
    previewing = null;
    closePanel(false);
    say('New layout: Save keeps it.'); // short: a longer status wraps the bar (the frame would move)
  }

  // Replace image: upload (button or drop) or the media library; the alt text can be changed.
  function imageId(i) { return i && i.image && i.image.id ? i.image.id : null; }
  function openImage(path, file) {
    var i = lastInfo;
    if (!api || !appBase) return;
    path = String(path || (i && i.path) || '');
    if (!path) return;
    if (!i || i.path !== path) { api.select(path); i = api.info(); }
    if (!i || !i.image) return;
    var body = openPanel('image', path, 'Replace image');
    pstate.chosen = i.imageChanged ? { id: imageId(i), url: i.image.src } : null;
    body.innerHTML =
      '<div class="evt-zone" tabindex="0" role="button" aria-label="Upload an image: choose a file or drop it here"><span>Drop an image here (JPG, PNG, GIF, WebP or SVG, max 20 MB)</span>' +
      '<button type="button" data-p="choose">Upload from computer</button><input type="file" hidden accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml,.svg"></div>' +
      '<label class="evt-field">Alt text (what the image shows)<input type="text" class="evt-alt" maxlength="300"></label>' +
      '<div class="evt-lib"><div class="evt-row"><input type="search" class="evt-search" placeholder="Search the media library" aria-label="Search the media library"><button type="button" data-p="search">Search</button></div>' +
      '<div class="evt-grid" aria-label="Media library"></div><button type="button" data-p="more" class="evt-more" hidden>More</button></div>';
    panel.querySelector('.evt-pf').innerHTML = '<button type="button" class="evt-primary" data-p="done">Done</button>';
    var alt = body.querySelector('.evt-alt'), zone = body.querySelector('.evt-zone'), input = body.querySelector('input[type="file"]');
    alt.value = i.image.alt || '';
    pstate.altTouched = false;
    alt.addEventListener('input', function () {
      pstate.altTouched = true;
      if (pstate.chosen) api.setImage(pstate.path, { id: pstate.chosen.id, url: pstate.chosen.url, alt: alt.value });
    });
    input.addEventListener('change', function () { if (input.files[0]) upload(input.files[0]); input.value = ''; });
    zone.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target === zone) { e.preventDefault(); input.click(); } });
    zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('evt-over'); });
    zone.addEventListener('dragleave', function () { zone.classList.remove('evt-over'); });
    zone.addEventListener('drop', function (e) {
      e.preventDefault(); zone.classList.remove('evt-over');
      if (e.dataTransfer && e.dataTransfer.files[0]) upload(e.dataTransfer.files[0]);
    });
    body.querySelector('.evt-search').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); loadLib(e.target.value, 1); } });
    loadLib('', 1);
    if (file) upload(file); else zone.focus();
  }
  var OK_TYPES = /^image\/(jpeg|png|gif|webp|svg\+xml)$/;
  function upload(file) {
    if (!pstate || pstate.kind !== 'image' || pstate.busy) return;
    var svg = /\.svg$/i.test(file.name || '');
    if (!OK_TYPES.test(file.type || '') && !svg) { pmsg('This file is not an image evive can put on the site: JPG, PNG, GIF, WebP or SVG.', true); return; }
    if (file.size > 20 * 1024 * 1024) { pmsg('The file is larger than 20 MB.', true); return; }
    var fd = new FormData();
    fd.append('file', file, file.name || 'image');
    var alt = panel.querySelector('.evt-alt');
    if (pstate.altTouched && alt.value.trim()) fd.append('description', alt.value.trim()); // the user's words become the alt text
    pstate.busy = true;
    panel.querySelector('.evt-zone').classList.add('evt-wait');
    pmsg('Uploading ' + (file.name || 'the image') + ' through evive…');
    var path = pstate.path;
    appCall('POST', 'upload', fd).then(function (j) {
      if (!pstate || pstate.path !== path) return;
      pstate.busy = false;
      panel.querySelector('.evt-zone').classList.remove('evt-wait');
      pstate.chosen = { id: j.id, url: j.url };
      alt.value = j.alt || alt.value;
      pstate.altTouched = false;
      api.setImage(path, { id: j.id, url: j.url, alt: alt.value });
      pmsg('Uploaded ' + (j.filename || '') + (j.sanitized ? ' (SVG cleaned)' : '') + '. Check the alt text, then Save.');
      loadLib(pstate.search || '', 1);
    }).catch(function (err) {
      if (!pstate) return;
      pstate.busy = false;
      panel.querySelector('.evt-zone').classList.remove('evt-wait');
      pmsg('Not uploaded: ' + err.message, true);
    });
  }
  function loadLib(search, page) {
    if (!pstate || pstate.kind !== 'image') return;
    var grid = panel.querySelector('.evt-grid'), more = panel.querySelector('.evt-more');
    if (page === 1) { grid.innerHTML = '<p class="evt-muted">Loading the media library…</p>'; pstate.items = []; }
    pstate.search = search; pstate.page = page;
    siteCall('media?post_id=' + encodeURIComponent(cfg.post_id) + '&page=' + page + (search ? '&search=' + encodeURIComponent(search) : '')).then(function (j) {
      if (!pstate || pstate.kind !== 'image') return;
      if (page === 1) grid.innerHTML = '';
      (j.items || []).forEach(function (it) {
        var n = pstate.items.push(it) - 1;
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'evt-thumb'; b.setAttribute('data-p', 'lib'); b.setAttribute('data-i', n);
        b.title = it.title + (it.width ? ' (' + it.width + '×' + it.height + ')' : '');
        b.innerHTML = '<img src="' + esc(it.thumb) + '" alt="' + esc(it.alt || it.title) + '" loading="lazy">';
        if (pstate.chosen && pstate.chosen.id === it.id) b.setAttribute('aria-pressed', 'true');
        grid.appendChild(b);
      });
      if (!pstate.items.length) grid.innerHTML = '<p class="evt-muted">' + (search ? 'No images found.' : 'The media library has no images yet.') + '</p>';
      more.hidden = !(j.pages > page);
    }).catch(function (err) { if (pstate) grid.innerHTML = '<p class="evt-err">' + esc(err.message) + '</p>'; });
  }
  function pickMedia(n) {
    var it = pstate && pstate.items && pstate.items[n];
    if (!it) return;
    var alt = panel.querySelector('.evt-alt');
    if (!pstate.altTouched) alt.value = it.alt || '';
    pstate.chosen = { id: it.id, url: it.url };
    api.setImage(pstate.path, { id: it.id, url: it.url, alt: alt.value });
    panel.querySelectorAll('.evt-thumb').forEach(function (b) { b.setAttribute('aria-pressed', Number(b.getAttribute('data-i')) === n ? 'true' : 'false'); });
    pmsg((it.title || 'Image') + ' is in place. Check the alt text, then Save.');
  }

  // evive #556: after "page changed": tell evive to read the page again (GET /api/tweak/elements), then reload the frame.
  function reloadPage() {
    busy = true; save.disabled = true; say('Reloading the page…');
    var again = appBase ? appCall('GET', 'elements').catch(function () { return null; }) : Promise.resolve(null);
    again.then(function () {
      busy = false; reloadNeeded = false; dirty = 0; api = null;
      document.querySelectorAll('#evt-tools button').forEach(function (b) { b.disabled = true; });
      save.textContent = 'Save'; save.disabled = true;
      frame.src = frame.src; // a fresh frame: the page as it is stored now, with its new base_hash
      say('Loading the page…');
    });
  }

  save.addEventListener('click', function () {
    if (reloadNeeded) { reloadPage(); return; } // evive #556
    if (!api || !cfg.endpoint || busy || done) return;
    if (previewing) { say('Use this layout or Cancel it first.', true); return; } // evive #549
    if (pstate) closePanel(false);
    if (api.stopEdit) api.stopEdit(); // evive #533: a text still being edited is part of this save
    var changes = api.changes();
    if (!changes.length) return;
    var body = { post_id: cfg.post_id, site: cfg.site, changes: changes };
    if (ELT && api.baseHash && api.baseHash()) body.base_hash = api.baseHash(); // evive #556 (E2)
    busy = true; save.disabled = true; pendingButtons(); say('Saving…');
    send(body).then(function (j) {
      busy = false;
      api.saved(ELT && j ? j.base_hash : undefined);
      say(ELT ? 'Saved in the Elementor settings of this page.' : 'Saved: evive applies it as a new revision.');
      if (j && j.reload) { // evive #575: a duplicate / delete shifted the block paths: read the page again (evive #578: Elementor too)
        closeMenu(false);
        dirty = 0; api = null; afterReady = 'Saved. The page is read again (' + (ELT ? 'elements' : 'blocks') + ' were added or removed).';
        document.querySelectorAll('#evt-tools button').forEach(function (b) { b.disabled = true; });
        save.textContent = 'Save'; save.disabled = true;
        frame.src = frame.src;
        say('Saved. Loading the page again…');
      }
      try {
        if (window.parent !== window) window.parent.postMessage({ type: 'evive:tweak-saved', post_id: cfg.post_id, changes: changes }, new URL(cfg.endpoint).origin);
      } catch (e) { /* no evive window */ }
    }).catch(function (err) {
      busy = false;
      if (ELT && err.partly && err.partly.length) { // evive #578: part of the Save is stored: read the page again
        reloadNeeded = true;
        save.textContent = 'Reload page'; save.disabled = false;
        pendingButtons();
        say('Partly saved (' + err.partly.join('; ') + '). Not saved: ' + err.message + ' Reload the page to see it as stored.', true);
        return;
      }
      if (ELT && err.code === 'page_changed') { // evive #556: saved elsewhere after this view read it
        reloadNeeded = true;
        save.textContent = 'Reload page'; save.disabled = false;
        pendingButtons();
        say('Not saved: the page was changed elsewhere (in Elementor or by evive) after this view opened. Reload the page, then make your changes again.', true);
        return;
      }
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
