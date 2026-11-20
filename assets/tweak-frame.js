/* evive #522: tweak mode overlay, inside the preview frame. Click selects a block (data-evive-path), clicking the
 * selected block again selects its parent, Esc deselects. Drag or arrow keys (Shift = 5 steps) move it, the corner
 * handles or +/- resize it (% of the parent, steps of 5; images keep their proportions). Changes are shown live and
 * collected here; the toolbar around the frame (tweak-shell.js) sends them to evive. Nothing is written to WordPress.
 * evive #532: a section (cover, columns row, wide/full-width block, or a top-level block with its own background) gets
 * Taller/Shorter and handles on its top and bottom edge instead of +/- and the corners: extra space (top/bottom, in
 * steps, 0..40) on top of the block's own padding, per screen size.
 * evive #533: double-click a text block (heading, paragraph, list item, button, image caption; the PHP marks them with
 * data-evive-text = the saved inner HTML of their text element) to edit its text in place. Enter ends a one-line edit
 * (heading, button, list item, caption), in a paragraph Enter is a line break and Esc or a click outside ends it.
 * Only inline formatting is kept (bold, italic, links, mark, span); paste is plain text. A text change is sent as
 * {path, block, text: {before, after}} with before = the saved inner HTML as the page was opened. */
(function () {
  'use strict';
  var cfg = window.eviveTweakFrame || {};
  var MEDIA = cfg.media || {
    desktop: '(min-width: 1025px)', tablet: '(min-width: 601px) and (max-width: 1024px)', phone: '(max-width: 600px)'
  };
  var SIZES = ['desktop', 'tablet', 'phone'];
  var IMAGE_BLOCKS = { 'core/image': 1, 'core/post-featured-image': 1, 'core/site-logo': 1 };
  var state = {};     // path -> {block, tweak, original, text: {before, after} | undefined (evive #533)}
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
      if (n.hasAttribute('data-evive-text') && textElement(n)) {
        var src = n.getAttribute('data-evive-text');
        state[pathOf(n)].text = { before: src, after: src };
        n.classList.add('evt-text');
      }
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
      if (textBar && textBar.contains(e.target)) return; // evive #533
      if (editing && editing.el.contains(e.target)) return; // a click inside the text being edited moves the caret
      clickAt(e.target, e.clientX, e.clientY);
    }, true);
    document.addEventListener('submit', function (e) { e.preventDefault(); }, true);
    document.addEventListener('keydown', function (e) { if (api.key(e)) e.preventDefault(); }, true);
    box.addEventListener('pointerdown', startDrag);
    render();
    (function loop() { place(); requestAnimationFrame(loop); })();
    var s = shell();
    if (s) s.ready(api);
  }

  // evive #533: a second click on the same block within 400 ms is a double-click: edit its text (if it has text).
  // A click on the selected block selects its parent only after that time, so a double-click doesn't jump to it.
  var lastClick = { t: 0, hit: null }, parentTimer = null;
  function clickAt(target, x, y) {
    if (editing) stopEdit();
    var hit = blockAt(target);
    var now = Date.now(), dbl = hit && lastClick.hit === hit && now - lastClick.t < 400;
    lastClick = { t: dbl ? 0 : now, hit: hit };
    clearTimeout(parentTimer);
    if (dbl) {
      if (state[pathOf(hit)] && state[pathOf(hit)].text) { startEdit(hit, x, y); return; }
      if (selected !== hit) select(hit);
      return;
    }
    if (selected && hit === selected) {
      parentTimer = setTimeout(function () { select(parentBlock(selected) || selected); }, 400);
      return;
    }
    select(hit);
  }

  // ── Text editing (evive #533) ──────────────────────────────────────────

  var editing = null; // {node, el, path}
  var textBar = null;
  var ONE_LINE = { 'core/heading': 1, 'core/button': 1, 'core/list-item': 1, 'core/image': 1 };
  var KEEP = { STRONG: [], B: [], EM: [], I: [], A: ['href', 'target', 'rel'], MARK: ['class', 'style'], SPAN: ['class', 'style'], BR: [], S: [], SUB: [], SUP: [] };
  var DROP = { SCRIPT: 1, STYLE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, TEMPLATE: 1, NOSCRIPT: 1, TEXTAREA: 1, SELECT: 1, SVG: 1, MATH: 1, TITLE: 1, IMG: 1, VIDEO: 1, AUDIO: 1, INPUT: 1, BUTTON: 0 };

  // The element whose inner HTML is the text: the block itself, the button's link, the image's caption.
  function textElement(node) {
    var b = node.getAttribute('data-evive-block');
    if (b === 'core/button') return node.matches('a,button') ? node : node.querySelector('a,button');
    if (b === 'core/image') return node.querySelector('figcaption');
    if (node.querySelector('[data-evive-path]')) return null; // holds other blocks (a list item with a sub-list)
    return node;
  }

  // Only the inline tags the evive MCP keeps (same list); other tags become their text.
  function cleanHtml(html) {
    var tpl = document.createElement('template');
    tpl.innerHTML = html;
    (function walk(parent) {
      Array.prototype.slice.call(parent.childNodes).forEach(function (n) {
        if (n.nodeType === 3) return;
        if (n.nodeType !== 1) { n.remove(); return; }
        walk(n);
        var tag = n.tagName.toUpperCase();
        if (DROP[tag]) { n.remove(); return; }
        if (!KEEP[tag]) { while (n.firstChild) parent.insertBefore(n.firstChild, n); n.remove(); return; }
        Array.prototype.slice.call(n.attributes).forEach(function (a) {
          var ok = KEEP[tag].indexOf(a.name) >= 0;
          if (ok && a.name === 'href') ok = !/^\s*(javascript|data|vbscript):/i.test(a.value);
          if (ok && a.name === 'target') ok = a.value === '_blank';
          if (ok && a.name === 'style') ok = !/url\s*\(|expression|javascript:/i.test(a.value);
          if (!ok) n.removeAttribute(a.name);
        });
        if (tag === 'A' && !n.getAttribute('href')) { while (n.firstChild) parent.insertBefore(n.firstChild, n); n.remove(); }
      });
    })(tpl.content);
    // The browser leaves a <br> at the end of edited text; it shows nothing.
    var last = tpl.content.lastChild;
    while (last && last.nodeType === 1 && last.tagName === 'BR') { last.remove(); last = tpl.content.lastChild; }
    return tpl.innerHTML;
  }

  // Compare two inner HTMLs as the MCP does (written differently but the same).
  function canon(html) {
    var d = document.createElement('div');
    d.innerHTML = html;
    return d.innerHTML.replace(/&nbsp;/g, ' ').replace(/[\s\u00a0]+/g, ' ').replace(/ ?<br> ?/g, '<br>').trim();
  }

  // evive #533: both sides cleaned, so markup outside KEEP in the saved text (code, a link's data-id or class)
  // does not count as a change when nothing was typed.
  function sameText(a, b) { return canon(cleanHtml(a)) === canon(cleanHtml(b)); }

  function textChanged(st) {
    if (!st.text) return false;
    var after = editing && state[editing.path] === st ? editing.el.innerHTML : st.text.after; // live while typing
    return !sameText(after, st.text.before);
  }

  function startEdit(node, x, y) {
    var st = state[pathOf(node)], el = textElement(node);
    if (!st || !st.text || !el) return;
    if (editing) stopEdit();
    select(node);
    // Edit the saved source (before wptexturize and other content filters), so the saved text is what changes.
    if (st.text.shown == null) st.text.shown = el.innerHTML; // as the page showed it, for an unchanged text
    el.innerHTML = st.text.after;
    editing = { node: node, el: el, path: pathOf(node), oneLine: !!ONE_LINE[st.block] };
    el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'true');
    el.classList.add('evt-editing');
    box.hidden = true;
    el.focus();
    var r = null;
    if (x != null && document.caretRangeFromPoint) r = document.caretRangeFromPoint(x, y);
    else if (x != null && document.caretPositionFromPoint) {
      var p = document.caretPositionFromPoint(x, y);
      if (p) { r = document.createRange(); r.setStart(p.offsetNode, p.offset); }
    }
    if (!r || !el.contains(r.startContainer)) { r = document.createRange(); r.selectNodeContents(el); r.collapse(false); }
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
    showTextBar();
    notify();
  }

  function stopEdit() {
    if (!editing) return;
    var ed = editing, st = state[ed.path];
    editing = null;
    var after = cleanHtml(ed.el.innerHTML);
    if (!ed.el.textContent.trim()) after = st.text.after; // never save an empty text: keep the last one
    st.text.after = sameText(after, st.text.before) ? st.text.before : after;
    ed.el.innerHTML = textChanged(st) ? st.text.after : st.text.shown;
    ed.el.removeAttribute('contenteditable');
    ed.el.removeAttribute('spellcheck');
    ed.el.classList.remove('evt-editing');
    if (textBar) textBar.hidden = true;
    box.hidden = !selected;
    try { window.getSelection().removeAllRanges(); } catch (e) { /* nothing selected */ }
    place();
    notify();
  }

  function showTextBar() {
    if (!textBar) {
      textBar = document.createElement('div');
      textBar.id = 'evt-textbar';
      [['bold', 'B', 'Bold (Ctrl+B)'], ['italic', 'I', 'Italic (Ctrl+I)'], ['link', 'Link', 'Link (Ctrl+K); empty removes it'], ['done', 'Done', 'End editing (Esc)']].forEach(function (b) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('data-cmd', b[0]);
        btn.textContent = b[1];
        btn.title = b[2];
        textBar.appendChild(btn);
      });
      // mousedown, not click: the text keeps its selection.
      textBar.addEventListener('mousedown', function (e) {
        e.preventDefault(); e.stopPropagation();
        var c = e.target.getAttribute && e.target.getAttribute('data-cmd');
        if (c) command(c);
      });
      document.body.appendChild(textBar);
    }
    textBar.hidden = false;
    place();
  }

  function command(c) {
    if (!editing) return;
    if (c === 'done') { stopEdit(); return; }
    if (c === 'bold' || c === 'italic') { document.execCommand('styleWithCSS', false, false); document.execCommand(c); return; }
    if (c === 'link') {
      var sel = window.getSelection(), a = sel.anchorNode;
      while (a && a !== editing.el && !(a.nodeType === 1 && a.tagName === 'A')) a = a.parentNode;
      var link = a && a !== editing.el ? a : null;
      var url = window.prompt('Link (empty = no link)', link ? link.getAttribute('href') : 'https://');
      if (url === null) return;
      url = url.trim();
      if (link) {
        if (!url || url === 'https://') { var r = document.createRange(); r.selectNodeContents(link); sel.removeAllRanges(); sel.addRange(r); document.execCommand('unlink'); }
        else link.setAttribute('href', url);
      } else if (url && url !== 'https://' && !sel.isCollapsed) {
        document.execCommand('createLink', false, url);
      }
    }
  }

  function editKey(e) {
    if (e.key === 'Escape') { stopEdit(); return true; }
    if (e.key === 'Enter') {
      if (editing.oneLine && !e.shiftKey) { stopEdit(); return true; }
      document.execCommand('insertLineBreak');
      return true;
    }
    if ((e.ctrlKey || e.metaKey) && !e.altKey) {
      var k = e.key.toLowerCase();
      if (k === 'b') { command('bold'); return true; }
      if (k === 'i') { command('italic'); return true; }
      if (k === 'k') { command('link'); return true; }
      if (k === 'u') return true; // underline is not kept
    }
    return false;
  }

  // Paste and drop: plain text only (no Word formatting).
  function insertPlain(text) {
    text = String(text || '').replace(/\r\n?/g, '\n');
    if (editing.oneLine) text = text.replace(/\s*\n\s*/g, ' ');
    text.split('\n').forEach(function (line, i) {
      if (i) document.execCommand('insertLineBreak');
      if (line) document.execCommand('insertText', false, line);
    });
  }
  document.addEventListener('paste', function (e) {
    if (!editing || !editing.el.contains(e.target)) return;
    e.preventDefault();
    insertPlain((e.clipboardData || window.clipboardData).getData('text'));
  }, true);
  document.addEventListener('drop', function (e) {
    if (!editing) return;
    e.preventDefault();
    if (editing.el.contains(e.target) && e.dataTransfer) insertPlain(e.dataTransfer.getData('text/plain'));
  }, true);
  document.addEventListener('dragover', function (e) { if (editing) e.preventDefault(); }, true);
  document.addEventListener('input', function (e) { if (editing && editing.el.contains(e.target)) notify(); }, true); // live Save count

  function select(node) {
    if (editing && node !== editing.node) stopEdit(); // evive #533
    if (selected) selected.classList.remove('evt-selected');
    selected = node || null;
    if (selected) {
      selected.classList.remove('evt-hover');
      selected.classList.add('evt-selected');
    }
    box.hidden = !selected || !!editing;
    box.classList.toggle('evt-section', !!selected && isSection(selected)); // evive #532
    place();
    notify();
  }

  function place() {
    if (editing && textBar && !textBar.hidden) {
      var er = editing.el.getBoundingClientRect();
      var top = er.top + window.scrollY - textBar.offsetHeight - 6;
      if (top < window.scrollY) top = er.bottom + window.scrollY + 6;
      textBar.style.top = top + 'px';
      textBar.style.left = Math.max(window.scrollX + 4, Math.min(er.left + window.scrollX, window.scrollX + document.documentElement.clientWidth - textBar.offsetWidth - 4)) + 'px';
    }
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
    // A block with an aspect ratio (a cover set to 16/9) can't get taller by padding: its height follows its width,
    // so it keeps the width resize (-/+, corners), which does make it taller.
    var ar = getComputedStyle(node).aspectRatio;
    if (ar && ar !== 'auto') return false;
    if (name === 'core/cover' || name === 'core/columns') return true;
    // Media blocks are never a section, also when wide or full width: they keep -/+ and the corners.
    if (/^core\/(image|video|audio|embed|gallery)$/.test(name)) return false;
    // Wide or full width only makes a container (a group) a section, not any block.
    if (name === 'core/group' && (node.classList.contains('alignfull') || node.classList.contains('alignwide'))) return true;
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
      if (!own) own = '0px';
      r.push('padding-' + side + ':calc(' + own + ' + ' + n + ' * var(--evive-tweak-step, 0.5rem)) !important');
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
      return JSON.stringify(state[p].tweak) !== state[p].original || textChanged(state[p]);
    }).map(function (p) {
      var st = state[p], t = st.tweak, c = { path: p, block: st.block };
      if (JSON.stringify(t) !== st.original) c.tweak = Object.keys(t).length ? t : null;
      if (textChanged(st)) c.text = { before: st.text.before, after: st.text.after }; // evive #533
      return c;
    });
  }

  // evive #533: what the Save button counts: every changed tweak and every changed text.
  function count() {
    var n = 0;
    Object.keys(state).forEach(function (p) {
      if (JSON.stringify(state[p].tweak) !== state[p].original) n++;
      if (textChanged(state[p])) n++;
    });
    return n;
  }

  function info() {
    if (!selected) return null;
    var p = pathOf(selected), st = state[p];
    return {
      path: p, block: st.block, device: device, tweak: st.tweak[device] || {},
      phoneHidden: !!(st.tweak.phone && st.tweak.phone.hidden),
      section: isSection(selected), // evive #532
      hasParent: !!parentBlock(selected),
      hasText: !!st.text, textChanged: textChanged(st), editing: !!editing // evive #533
    };
  }

  function notify() {
    var s = shell();
    if (s) s.changed(info(), count());
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
        clickAt(under, ev.clientX, ev.clientY);
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
      if (editing) stopEdit();
      var st = state[pathOf(selected)];
      // evive #533: a text edit is undone first (its own step); the next Reset undoes the tweak of this size.
      if (textChanged(st)) {
        st.text.after = st.text.before;
        var el = textElement(selected);
        if (el) el.innerHTML = st.text.shown != null ? st.text.shown : st.text.before;
        place(); notify();
        return;
      }
      var t = JSON.parse(JSON.stringify(st.tweak));
      delete t[device];
      st.tweak = clean(t);
      render(); place(); notify(); // evive #532: the box follows at once
    },
    editText: function () { if (selected) startEdit(selected); }, // evive #533
    stopEdit: function () { stopEdit(); },
    changes: changes,
    info: info,
    saved: function () {
      if (editing) stopEdit();
      Object.keys(state).forEach(function (p) {
        state[p].original = JSON.stringify(state[p].tweak);
        if (textChanged(state[p])) { // evive #533: the saved text is the new start
          state[p].text.before = state[p].text.after;
          state[p].text.shown = null;
        }
      });
      notify();
    },
    key: function (e) {
      if (editing) return editKey(e); // evive #533: while editing text, keys type (arrows move the caret)
      var tag = (e.target && e.target.tagName) || '';
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) && e.target.ownerDocument === document) return false;
      var step = e.shiftKey ? 5 : 1;
      switch (e.key) {
        case 'Escape': if (!selected) return false; api.deselect(); return true;
        case 'Enter': // evive #533: Enter (in the page) or F2 edits the text of the selected block
          if (!e.target || e.target.ownerDocument !== document) return false;
          /* falls through */
        case 'F2': if (!selected || !state[pathOf(selected)].text) return false; startEdit(selected); return true;
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
