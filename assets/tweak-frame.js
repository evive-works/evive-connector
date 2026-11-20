/* evive #522: tweak mode overlay, inside the preview frame. Click selects a block (data-evive-path), clicking the
 * selected block again selects its parent, Esc deselects. Drag or arrow keys (Shift = 5 steps) move it, the corner
 * handles or +/- resize it (% of the parent, steps of 5; images keep their proportions). Changes are shown live and
 * collected here; the toolbar around the frame (tweak-shell.js) sends them to evive. Nothing is written to WordPress.
 * evive #532: a section (cover, columns row, wide/full-width block, or a top-level block with its own background) gets
 * Taller/Shorter and handles on its top and bottom edge instead of +/- and the corners: extra space (top/bottom, in
 * steps, 0..40) on top of the block's own padding, per screen size. */
(function () {
  'use strict';
  var cfg = window.eviveTweakFrame || {};
  var MEDIA = cfg.media || {
    desktop: '(min-width: 1025px)', tablet: '(min-width: 601px) and (max-width: 1024px)', phone: '(max-width: 600px)'
  };
  var SIZES = ['desktop', 'tablet', 'phone'];
  var IMAGE_BLOCKS = { 'core/image': 1, 'core/post-featured-image': 1, 'core/site-logo': 1 };
  var state = {};     // path -> {block, tweak, original}
  var device = 'desktop';
  var selected = null;
  var hover = null;
  var box, live, stepPx = 8;

  function shell() {
    try { return window.parent !== window && window.parent.eviveTweakShell ? window.parent.eviveTweakShell : null; }
    catch (e) { return null; }
  }

  // Same rules as Evive_Tweak::sanitize() in PHP, with a fixed key order (so JSON can be compared).
  function clean(t) {
    var out = {};
    SIZES.forEach(function (s) {
      var v = t && t[s];
      if (!v || typeof v !== 'object') return;
      var c = {};
      ['x', 'y'].forEach(function (k) {
        var n = Math.max(-40, Math.min(40, Math.round(Number(v[k]) || 0)));
        if (n) c[k] = n;
      });
      if (v.width != null && v.width !== '' && !isNaN(Number(v.width))) {
        c.width = Math.max(10, Math.min(100, Math.round(Number(v.width) / 5) * 5));
      }
      if (v.hidden) c.hidden = true;
      ['top', 'bottom'].forEach(function (k) { // evive #532
        var n = Math.max(0, Math.min(40, Math.round(Number(v[k]) || 0)));
        if (n) c[k] = n;
      });
      if (Object.keys(c).length) out[s] = c;
    });
    return out;
  }

  function pathOf(node) { return node && node.getAttribute ? node.getAttribute('data-evive-path') : null; }
  function blockAt(node) {
    while (node && node.nodeType !== 1) node = node.parentNode;
    return node && node.closest ? node.closest('[data-evive-path]') : null;
  }
  function parentBlock(node) { return node && node.parentElement ? blockAt(node.parentElement) : null; }

  function init() {
    document.querySelectorAll('[data-evive-path]').forEach(function (n) {
      var t = {};
      try { t = JSON.parse(n.getAttribute('data-evive-tweak') || '{}') || {}; } catch (e) { t = {}; }
      t = clean(t);
      var base = {};
      try { base = JSON.parse(n.getAttribute('data-evive-space') || '{}') || {}; } catch (e) { base = {}; }
      state[pathOf(n)] = { block: n.getAttribute('data-evive-block') || '', tweak: t, original: JSON.stringify(t), base: base };
      // The live rules below replace the server's CSS for this block while tweaking.
      Array.prototype.slice.call(n.classList).forEach(function (c) { if (c.indexOf('evive-tw-') === 0) n.classList.remove(c); });
    });
    live = document.createElement('style');
    live.id = 'evt-live';
    document.head.appendChild(live);

    var probe = document.createElement('div');
    probe.style.cssText = 'position:absolute;visibility:hidden;width:var(--evive-tweak-step, 0.5rem);height:0';
    document.body.appendChild(probe);
    stepPx = probe.getBoundingClientRect().width || 8;
    probe.remove();

    box = document.createElement('div');
    box.id = 'evt-box';
    box.hidden = true;
    ['nw', 'ne', 'sw', 'se'].forEach(function (c) {
      var h = document.createElement('div');
      h.className = 'evt-h evt-h-' + c;
      h.setAttribute('data-corner', c);
      box.appendChild(h);
    });
    ['n', 's'].forEach(function (c) { // evive #532: space at the top / bottom of a section
      var h = document.createElement('div');
      h.className = 'evt-h evt-h-' + c;
      h.setAttribute('data-edge', c === 'n' ? 'top' : 'bottom');
      h.title = c === 'n' ? 'Drag: space at the top' : 'Drag: space at the bottom';
      box.appendChild(h);
    });
    document.body.appendChild(box);
    document.documentElement.classList.add('evt-on');

    document.addEventListener('mouseover', function (e) {
      var b = blockAt(e.target);
      if (b === hover) return;
      if (hover) hover.classList.remove('evt-hover');
      hover = b && b !== selected ? b : null;
      if (hover) hover.classList.add('evt-hover');
    }, true);
    // Clicks select; links, buttons and forms do nothing in tweak mode.
    document.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      if (box.contains(e.target)) return; // handled by the pointer events of the box
      clickAt(e.target);
    }, true);
    document.addEventListener('submit', function (e) { e.preventDefault(); }, true);
    document.addEventListener('keydown', function (e) { if (api.key(e)) e.preventDefault(); }, true);
    box.addEventListener('pointerdown', startDrag);
    render();
    (function loop() { place(); requestAnimationFrame(loop); })();
    var s = shell();
    if (s) s.ready(api);
  }

  function clickAt(target) {
    var hit = blockAt(target);
    if (selected && hit === selected) hit = parentBlock(selected) || selected;
    select(hit);
  }

  function select(node) {
    if (selected) selected.classList.remove('evt-selected');
    selected = node || null;
    if (selected) {
      selected.classList.remove('evt-hover');
      selected.classList.add('evt-selected');
    }
    box.hidden = !selected;
    box.classList.toggle('evt-section', !!selected && isSection(selected)); // evive #532
    place();
    notify();
  }

  function place() {
    if (!selected || !box) return;
    var r = selected.getBoundingClientRect();
    box.style.left = (r.left + window.scrollX) + 'px';
    box.style.top = (r.top + window.scrollY) + 'px';
    box.style.width = r.width + 'px';
    box.style.height = r.height + 'px';
  }

  // evive #532: a section gets space at the top/bottom instead of a width.
  function isSection(node) {
    var name = node.getAttribute('data-evive-block') || '';
    if (name === 'core/cover' || name === 'core/columns') return true;
    if (node.classList.contains('alignfull') || node.classList.contains('alignwide')) return true;
    if ((pathOf(node) || '').indexOf('.') >= 0) return false;
    if (node.classList.contains('has-background')) return true;
    var cs = getComputedStyle(node);
    if (cs.backgroundImage && cs.backgroundImage !== 'none') return true;
    var m = /rgba?\(([^)]+)\)/.exec(cs.backgroundColor || '');
    return !!m && (m[1].split(',').length < 4 || parseFloat(m[1].split(',')[3]) > 0);
  }

  // evive #532: same rules as Evive_Tweak::space_rules() in PHP.
  function spaceRules(t, base) {
    var r = [], total = 0;
    ['top', 'bottom'].forEach(function (side) {
      var n = t[side] || 0;
      if (n <= 0) return;
      total += n;
      var own = base[side] || '';
      if (!own && base.cover) own = '1em';
      if (own) r.push('padding-' + side + ':calc(' + own + ' + ' + n + ' * var(--evive-tweak-step, 0.5rem)) !important');
      else r.push('border-' + side + ':calc(' + n + ' * var(--evive-tweak-step, 0.5rem)) solid transparent');
    });
    if (total && base.minh) r.push('min-height:calc(' + base.minh + ' + ' + total + ' * var(--evive-tweak-step, 0.5rem)) !important');
    return r;
  }

  function cur() {
    if (!selected) return null;
    var st = state[pathOf(selected)];
    if (!st) return null;
    return st.tweak[device] || {};
  }

  function update(patch) {
    if (!selected) return;
    var st = state[pathOf(selected)];
    var t = JSON.parse(JSON.stringify(st.tweak));
    t[device] = Object.assign({}, t[device] || {}, patch);
    st.tweak = clean(t);
    render();
    place(); // evive #532: the box (and its edge handles) follow the new size at once
    notify();
  }

  function css(path, st) {
    var sel = '[data-evive-path="' + path.replace(/[^0-9.]/g, '') + '"][data-evive-path]';
    var out = '';
    SIZES.forEach(function (s) {
      var t = st.tweak[s];
      if (!t || !MEDIA[s]) return;
      var r = [];
      if (t.hidden) {
        r.push('opacity:.25', 'outline:2px dashed #c0392b', 'outline-offset:-2px'); // still selectable while tweaking
      }
      if (t.x || t.y) {
        r.push('translate:calc(' + (t.x || 0) + ' * var(--evive-tweak-step, 0.5rem)) calc(' + (t.y || 0) + ' * var(--evive-tweak-step, 0.5rem))');
      }
      if (t.width) r.push('width:' + t.width + '%', 'max-width:' + t.width + '%', 'box-sizing:border-box');
      if (!t.hidden) r = r.concat(spaceRules(t, st.base || {})); // evive #532
      if (!r.length) return;
      out += '@media ' + MEDIA[s] + '{' + sel + '{' + r.join(';') + '}';
      if (t.width && IMAGE_BLOCKS[st.block]) out += sel + ' img{width:100% !important;height:auto !important}';
      out += '}';
    });
    return out;
  }

  function render() {
    var out = '';
    Object.keys(state).forEach(function (p) { out += css(p, state[p]); });
    live.textContent = out;
  }

  function changes() {
    return Object.keys(state).filter(function (p) {
      return JSON.stringify(state[p].tweak) !== state[p].original;
    }).map(function (p) {
      var t = state[p].tweak;
      return { path: p, block: state[p].block, tweak: Object.keys(t).length ? t : null };
    });
  }

  function info() {
    if (!selected) return null;
    var p = pathOf(selected), st = state[p];
    return {
      path: p, block: st.block, device: device, tweak: st.tweak[device] || {},
      phoneHidden: !!(st.tweak.phone && st.tweak.phone.hidden),
      section: isSection(selected), // evive #532
      hasParent: !!parentBlock(selected)
    };
  }

  function notify() {
    var s = shell();
    if (s) s.changed(info(), changes().length);
  }

  function parentWidth(node) {
    var p = node.parentElement;
    if (!p) return node.getBoundingClientRect().width || 1;
    var cs = getComputedStyle(p);
    var w = p.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0);
    return w > 0 ? w : (node.getBoundingClientRect().width || 1);
  }

  function widthNow() {
    var t = cur();
    if (t && t.width) return t.width;
    var pct = selected.getBoundingClientRect().width / parentWidth(selected) * 100;
    return Math.max(10, Math.min(100, Math.round(pct / 5) * 5));
  }

  function startDrag(e) {
    if (!selected || e.button !== 0) return;
    e.preventDefault();
    var corner = e.target.getAttribute && e.target.getAttribute('data-corner');
    var edge = e.target.getAttribute && e.target.getAttribute('data-edge'); // evive #532
    var t = cur() || {};
    var start = {
      x: e.clientX, y: e.clientY, tx: t.x || 0, ty: t.y || 0, moved: false, top: t.top || 0, bottom: t.bottom || 0,
      w: selected.getBoundingClientRect().width, pw: parentWidth(selected)
    };
    box.setPointerCapture(e.pointerId);
    document.documentElement.classList.add('evt-dragging');
    function move(ev) {
      var dx = ev.clientX - start.x, dy = ev.clientY - start.y;
      if (!start.moved && Math.abs(dx) < 3 && Math.abs(dy) < 3) return;
      start.moved = true;
      if (edge === 'bottom') {
        update({ bottom: Math.max(0, Math.min(40, start.bottom + Math.round(dy / stepPx))) });
      } else if (edge === 'top') {
        // Dragging the top edge up makes more space at the top.
        update({ top: Math.max(0, Math.min(40, start.top - Math.round(dy / stepPx))) });
      } else if (corner) {
        var sign = corner.indexOf('w') >= 0 ? -1 : 1;
        var pct = (start.w + sign * dx) / start.pw * 100;
        update({ width: Math.max(10, Math.min(100, Math.round(pct / 5) * 5)) });
      } else {
        update({ x: start.tx + Math.round(dx / stepPx), y: start.ty + Math.round(dy / stepPx) });
      }
    }
    function up(ev) {
      box.removeEventListener('pointermove', move);
      box.removeEventListener('pointerup', up);
      box.removeEventListener('pointercancel', up);
      document.documentElement.classList.remove('evt-dragging');
      if (!start.moved && ev.type === 'pointerup') {
        box.style.pointerEvents = 'none';
        var under = document.elementFromPoint(ev.clientX, ev.clientY);
        box.style.pointerEvents = '';
        clickAt(under);
      }
    }
    box.addEventListener('pointermove', move);
    box.addEventListener('pointerup', up);
    box.addEventListener('pointercancel', up);
  }

  var api = {
    setDevice: function (d) { if (SIZES.indexOf(d) >= 0) { device = d; notify(); } },
    selectParent: function () { if (selected) select(parentBlock(selected) || selected); },
    deselect: function () { select(null); },
    select: function (path) { select(document.querySelector('[data-evive-path="' + String(path).replace(/[^0-9.]/g, '') + '"]')); },
    nudge: function (dx, dy) {
      var t = cur(); if (!t) return;
      update({ x: (t.x || 0) + dx, y: (t.y || 0) + dy });
    },
    resize: function (delta) {
      if (!selected) return;
      update({ width: Math.max(10, Math.min(100, widthNow() + delta)) });
    },
    // evive #532: more (n > 0) or less space at the bottom (or top) of a section, in steps.
    space: function (n, side) {
      var t = cur(); if (!t) return;
      side = side === 'top' ? 'top' : 'bottom';
      var p = {}; p[side] = Math.max(0, Math.min(40, (t[side] || 0) + n));
      update(p);
    },
    togglePhoneHidden: function () {
      if (!selected) return;
      var st = state[pathOf(selected)];
      var t = JSON.parse(JSON.stringify(st.tweak));
      t.phone = Object.assign({}, t.phone || {}, { hidden: !(t.phone && t.phone.hidden) });
      st.tweak = clean(t);
      render(); notify();
    },
    reset: function () {
      if (!selected) return;
      var st = state[pathOf(selected)];
      var t = JSON.parse(JSON.stringify(st.tweak));
      delete t[device];
      st.tweak = clean(t);
      render(); notify();
    },
    changes: changes,
    info: info,
    saved: function () {
      Object.keys(state).forEach(function (p) { state[p].original = JSON.stringify(state[p].tweak); });
      notify();
    },
    key: function (e) {
      var tag = (e.target && e.target.tagName) || '';
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) && e.target.ownerDocument === document) return false;
      var step = e.shiftKey ? 5 : 1;
      switch (e.key) {
        case 'Escape': if (!selected) return false; api.deselect(); return true;
        case 'ArrowLeft': if (!selected) return false; api.nudge(-step, 0); return true;
        case 'ArrowRight': if (!selected) return false; api.nudge(step, 0); return true;
        case 'ArrowUp': if (!selected) return false; api.nudge(0, -step); return true;
        case 'ArrowDown': if (!selected) return false; api.nudge(0, step); return true;
        case '+': case '=': if (!selected) return false; if (isSection(selected)) api.space(step); else api.resize(5); return true;
        case '-': case '_': if (!selected) return false; if (isSection(selected)) api.space(-step); else api.resize(-5); return true;
      }
      return false;
    }
  };
  window.eviveTweak = api;

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
