/* evive #556: tweak mode overlay for Elementor pages, inside the preview frame (the block pages have tweak-frame.js).
 * The PHP (class-tweak-elementor.php) gives the page's own elements from _elementor_data: id, type, parent, label, the
 * saved source of the editable texts (E3) and margin/padding/width/hidden per device, plus base_hash (E2). This script
 * marks those elements in the page by their data-id (data-evive-elt), only inside the page's own document
 * ([data-elementor-id = this post]): header, footer and other templates are shown but never selectable, global widgets
 * are marked but not selectable (E4).
 * Click selects an element, a click on the selected element (or Parent) selects its parent in the Elementor tree, Esc
 * deselects. Double-click (or Edit text, Enter, F2) edits a text in place as in #533: B / I / Link, Enter ends a one-line
 * text, Esc or a click outside ends it; paste is plain text. The toolbar (tweak-shell.js) has width -/+ (5 %, or 20 px
 * for a width in px), Taller/Shorter for sections and containers (padding at the bottom, 10 px, Shift 50 px; all four
 * sides are sent in px so Elementor writes the rule), Hide on phone / tablet, Move up / down within the parent, Reset.
 * Every value belongs to the device of the toolbar (desktop / tablet / mobile), as Elementor's own responsive values.
 * Changes are shown live and collected as the evive MCP's change set (evive_apply_elementor_tweak, #554):
 *   {type: 'text', id, field, before, after} | {type: 'spacing', id, device, padding: {top, right, bottom, left}, unit}
 *   | {type: 'width', id, device, value, unit} | {type: 'hidden', id, device, hidden} | {type: 'move', id, direction}
 * The toolbar sends them with base_hash to evive; nothing here writes to WordPress.
 * evive #578: the tweak basics of #575 (the same toolbar: Layer, Style, Align, Crop + focal point, Duplicate / Delete) in
 * Elementor's own settings, as the evive MCP #576 stores them: layer {z -5..5} per device; corners {radius none|s|m|l|round}
 * (Style: all devices = desktop, tablet and mobile then follow it); shadow {none|soft|medium} and opacity (image widget)
 * once for all devices (Elementor has one); align left / center / right per device (heading, image, text editor, icon:
 * their own alignment; else align-self inside a flex container); crop of an image widget per device = a height (from the
 * shape picked: the image's width now x the shape) + object-fit cover, with a focal point snapped to Elementor's nine
 * positions; duplicate / delete {type, id} (the app sends them on their own; a delete has no undo). Tablet and mobile
 * follow the device above them unless they have a value of their own (as in Elementor).
 * Elementor's own scripts keep running (sliders, animations, sticky), but clicks, taps and drags never reach them while
 * tweaking (captured on the window), and motion effects and entrance animations are paused (tweak-elementor.css). */
(function () {
  'use strict';
  var cfg = window.eviveTweakElementor || {};
  var MEDIA = cfg.media || { desktop: '(min-width: 1025px)', tablet: '(min-width: 768px) and (max-width: 1024px)', phone: '(max-width: 767px)' };
  var DEVS = ['desktop', 'tablet', 'mobile'];
  var MQ = { desktop: MEDIA.desktop, tablet: MEDIA.tablet, mobile: MEDIA.phone };
  var NAMES = { desktop: 'desktop', tablet: 'tablet', mobile: 'phone' };
  var rows = {};    // id -> element row from the PHP
  var state = {};   // id -> {row, node, texts: {field: {before, after, shown}}, dev: {device: {padding, width, hidden}}, orig}
  var orders = {};  // parent id ('' = top) -> {orig: [ids], now: [ids]}
  var device = 'desktop', selected = null, hover = null, box, live, root;
  var baseHash = String(cfg.base_hash || '');
  var structural = {}; // evive #578: id -> {op: 'duplicate'|'delete', copy (the shown copy)}
  var picking = null;  // evive #578: the id of the image whose focal point is being picked

  function shell() {
    try { return window.parent !== window && window.parent.eviveTweakShell ? window.parent.eviveTweakShell : null; }
    catch (e) { return null; }
  }
  function hint(t) { var s = shell(); if (s && s.hint) s.hint(t); }
  function idOf(node) { return node && node.getAttribute ? node.getAttribute('data-evive-elt') : null; }
  function copy(o) { return JSON.parse(JSON.stringify(o || {})); }

  // ── Elements ──────────────────────────────────────────────────────────
  (cfg.elements || []).forEach(function (r) { rows[r.id] = r; });

  function mark() {
    var pid = String(cfg.post_id || '');
    root = null;
    document.querySelectorAll('[data-elementor-id]').forEach(function (n) {
      if (!root && n.getAttribute('data-elementor-id') === pid) root = n;
    });
    if (!root) return 0;
    var n = 0;
    root.querySelectorAll('[data-id]').forEach(function (el) {
      var id = el.getAttribute('data-id'), r = rows[id];
      if (!r || el.closest('[data-elementor-id]') !== root || state[id] || el.hasAttribute('data-evive-elt')) return;
      el.setAttribute('data-evive-elt', id);
      if (r.selectable === false) { el.classList.add('evt-locked'); return; }
      n++;
      var st = { row: r, node: el, texts: {}, dev: copy(r.devices) };
      DEVS.forEach(function (d) { // the frame shows hidden elements dimmed (tweak-elementor.css), not display: none
        if (el.classList.contains('elementor-hidden-' + d)) el.classList.remove('elementor-hidden-' + d);
      });
      Object.keys(r.texts || {}).forEach(function (f) {
        st.texts[f] = { before: r.texts[f], after: r.texts[f], shown: null };
        if (fieldEl(st, f)) el.classList.add('evt-text');
      });
      st.orig = JSON.stringify(st.dev);
      state[id] = st;
    });
    Object.keys(rows).forEach(function (id) { // the order of each parent's elements, as saved
      var r = rows[id], k = r.parent || '';
      if (!orders[k]) orders[k] = { list: [] };
      orders[k].list[r.index] = id;
    });
    Object.keys(orders).forEach(function (k) {
      var o = orders[k];
      o.orig = o.list.filter(function (x) { return x != null; });
      o.now = o.orig.slice();
      delete o.list;
    });
    return n;
  }

  // The element whose inner HTML is a text field (E3), or null.
  var FIELD_SEL = {
    heading: { title: '.elementor-heading-title' },
    button: { text: '.elementor-button-text' },
    image: { caption: 'figcaption' },
    testimonial: { testimonial_content: '.elementor-testimonial-content', testimonial_name: '.elementor-testimonial-name', testimonial_job: '.elementor-testimonial-job' }
  };
  function fieldEl(st, field) {
    var n = st.node, wt = st.row.widget_type, el = null;
    if (wt === 'text-editor' && field === 'editor') {
      el = n.querySelector(':scope > .elementor-widget-container') || n;
      return el.querySelector('[data-evive-elt]') ? null : el;
    }
    if (wt === 'icon-list') {
      var m = /^icon_list\.(\d+)\.text$/.exec(field);
      var it = m ? n.querySelectorAll('.elementor-icon-list-item')[Number(m[1])] : null;
      return it ? it.querySelector('.elementor-icon-list-text') : null;
    }
    var sel = FIELD_SEL[wt] && FIELD_SEL[wt][field];
    el = sel ? n.querySelector(sel) : null;
    // A heading with a link: its text is inside the <a>; edit that, so the link stays while editing.
    if (el && el.children.length === 1 && el.firstElementChild.tagName === 'A' && el.textContent.trim() === el.firstElementChild.textContent.trim()) el = el.firstElementChild;
    return el;
  }
  // The text field under the pointer (or the first one of the element).
  function fieldAt(st, target) {
    var fields = Object.keys(st.texts), first = null;
    for (var i = 0; i < fields.length; i++) {
      var el = fieldEl(st, fields[i]);
      if (!el) continue;
      if (!first) first = fields[i];
      if (target && el.contains(target)) return fields[i];
    }
    return first;
  }

  function eltAt(node) {
    while (node && node.nodeType !== 1) node = node.parentNode;
    return node && node.closest ? node.closest('[data-evive-elt]') : null;
  }
  function parentNode(st) {
    var p = st && st.row.parent;
    return p && state[p] ? state[p].node : null;
  }
  function isSection(st) { return st.row.el_type === 'section' || st.row.el_type === 'container'; }

  // ── Init ──────────────────────────────────────────────────────────────
  function init() {
    var count = mark();
    live = document.createElement('style');
    live.id = 'evt-live';
    document.head.appendChild(live);
    box = document.createElement('div');
    box.id = 'evt-box';
    box.hidden = true;
    document.body.appendChild(box);
    document.documentElement.classList.add('evt-on', 'evt-elementor');

    // Clicks, taps and drags select here and never reach the page's own scripts (links, sliders, lightboxes, popups).
    ['pointerdown', 'pointerup', 'mousedown', 'mouseup', 'touchstart', 'touchend', 'dblclick', 'auxclick'].forEach(function (t) {
      window.addEventListener(t, function (e) {
        if (ours(e.target)) return;
        e.stopPropagation();
        if (t === 'auxclick') e.preventDefault();
      }, true);
    });
    window.addEventListener('click', function (e) {
      if (ours(e.target)) return;
      if (editing && editing.el.contains(e.target)) { e.stopPropagation(); e.preventDefault(); return; } // the caret moves
      e.preventDefault(); e.stopPropagation();
      if (picking) { pickAt(e.clientX, e.clientY); return; } // evive #578
      clickAt(e.target, e.clientX, e.clientY);
    }, true);
    window.addEventListener('submit', function (e) { e.preventDefault(); e.stopPropagation(); }, true);
    window.addEventListener('keydown', function (e) { if (api.key(e)) { e.preventDefault(); e.stopPropagation(); } }, true);
    document.addEventListener('mouseover', function (e) {
      var b = eltAt(e.target);
      if (b && !state[idOf(b)]) b = null;
      if (b === hover) return;
      if (hover) hover.classList.remove('evt-hover');
      hover = b && b !== selected ? b : null;
      if (hover) hover.classList.add('evt-hover');
    }, true);
    render();
    (function loop() { place(); requestAnimationFrame(loop); })();
    var s = shell();
    if (s) {
      s.ready(api);
      if (!root) hint('The page content of this Elementor page was not found in the page, so nothing can be selected.');
      else if (!count) hint('This page has no elements that can be tweaked.');
    }
  }
  function ours(t) { return !!(t && t.nodeType === 1 && ((box && box.contains(t)) || (textBar && textBar.contains(t)))); }

  var lastClick = { t: 0, hit: null }, parentTimer = null;
  function clickAt(target, x, y) {
    if (editing) stopEdit();
    var hit = eltAt(target);
    if (!hit && x != null && document.elementsFromPoint) { // evive #578: an element sent behind the page (z-index < 0) is still selectable
      var under = document.elementsFromPoint(x, y);
      for (var u = 0; u < under.length && !hit; u++) { var c = eltAt(under[u]); if (c && state[idOf(c)]) hit = c; }
    }
    clearTimeout(parentTimer);
    if (!hit || !state[idOf(hit)]) {
      // E4: the header, footer and other templates, and global widgets, are shown but not tweaked.
      var why = hit && rows[idOf(hit)] && rows[idOf(hit)].why;
      if (why === 'global widget') hint('This is a global widget: it is shared with other pages, so it is not changed here. Ask evive in the chat, or change it in Elementor.');
      else if (why) hint('This element (' + why + ') cannot be changed in tweak mode. Ask evive in the chat.');
      else if (!root || !root.contains(target)) hint('This is not part of this page (the header, footer or another Elementor template). Ask evive in the chat to change it.');
      select(null);
      return;
    }
    var now = Date.now(), dbl = lastClick.hit === hit && now - lastClick.t < 400;
    lastClick = { t: dbl ? 0 : now, hit: hit };
    if (dbl) {
      var st = state[idOf(hit)], f = fieldAt(st, target);
      if (f) { startEdit(hit, f, x, y); return; }
      if (Object.keys(st.texts).length === 0 && st.row.el_type === 'widget') hint('The text of this kind of element (' + (st.row.widget_type || 'widget') + ') cannot be edited here: ask evive in the chat. You can still move, hide or resize it.');
      if (selected !== hit) select(hit);
      return;
    }
    if (selected && hit === selected) {
      parentTimer = setTimeout(function () { var p = parentNode(state[idOf(selected)]); if (p) select(p); }, 400);
      return;
    }
    select(hit);
  }

  // ── Text editing (as #533) ────────────────────────────────────────────
  var editing = null, textBar = null;
  var MULTI = { 'text-editor:editor': 1, 'testimonial:testimonial_content': 1 };
  var INLINE = { STRONG: [], B: [], EM: [], I: [], A: ['href', 'target', 'rel'], MARK: ['class', 'style'], SPAN: ['class', 'style'], BR: [], S: [], SUB: [], SUP: [] };
  var BLOCK = { P: [], UL: [], OL: [], LI: [], BLOCKQUOTE: [] };
  var DROP = { SCRIPT: 1, STYLE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, TEMPLATE: 1, NOSCRIPT: 1, TEXTAREA: 1, SELECT: 1, SVG: 1, MATH: 1, TITLE: 1, IMG: 1, VIDEO: 1, AUDIO: 1, INPUT: 1 };

  function cleanHtml(html, rich) {
    var tpl = document.createElement('template');
    tpl.innerHTML = html;
    (function walk(parent) {
      Array.prototype.slice.call(parent.childNodes).forEach(function (n) {
        if (n.nodeType === 3) return;
        if (n.nodeType !== 1) { n.remove(); return; }
        walk(n);
        var tag = n.tagName.toUpperCase();
        if (DROP[tag]) { n.remove(); return; }
        if (tag === 'DIV' && rich) { // a new line in the text editor: a paragraph
          var p = document.createElement('p');
          while (n.firstChild) p.appendChild(n.firstChild);
          n.replaceWith(p); n = p; tag = 'P';
        }
        var keep = INLINE[tag] || (rich ? BLOCK[tag] : null);
        if (!keep) { while (n.firstChild) parent.insertBefore(n.firstChild, n); n.remove(); return; }
        Array.prototype.slice.call(n.attributes).forEach(function (a) {
          var ok = keep.indexOf(a.name) >= 0;
          if (ok && a.name === 'href') ok = !/^\s*(javascript|data|vbscript):/i.test(a.value);
          if (ok && a.name === 'target') ok = a.value === '_blank';
          if (ok && a.name === 'style') ok = !/url\s*\(|expression|javascript:/i.test(a.value);
          if (!ok) n.removeAttribute(a.name);
        });
        if (tag === 'A' && !n.getAttribute('href')) { while (n.firstChild) parent.insertBefore(n.firstChild, n); n.remove(); }
      });
    })(tpl.content);
    var last = tpl.content.lastChild;
    while (last && last.nodeType === 1 && last.tagName === 'BR') { last.remove(); last = tpl.content.lastChild; }
    return tpl.innerHTML;
  }
  function canon(html) {
    var d = document.createElement('div');
    d.innerHTML = html;
    return d.innerHTML.replace(/&nbsp;/g, ' ').replace(/[\s ]+/g, ' ').replace(/ ?<br> ?/g, '<br>').replace(/>\s+</g, '><').trim();
  }
  function isRich(st, f) { return !!MULTI[st.row.widget_type + ':' + f]; }
  function sameText(st, f, a, b) { var r = isRich(st, f); return canon(cleanHtml(a, r)) === canon(cleanHtml(b, r)); }
  function textChanged(st, f) {
    var t = st.texts[f];
    if (!t) return false;
    var after = editing && editing.st === st && editing.field === f ? editing.el.innerHTML : t.after;
    return !sameText(st, f, after, t.before);
  }
  function anyText(st) { return Object.keys(st.texts).some(function (f) { return textChanged(st, f); }); }

  function startEdit(node, field, x, y) {
    var st = state[idOf(node)];
    if (!st || !st.texts[field]) return;
    var el = fieldEl(st, field), t = st.texts[field];
    if (!el) return;
    if (editing) stopEdit();
    select(node);
    if (t.shown == null) t.shown = el.innerHTML;
    el.innerHTML = t.after; // the saved source, not the rendered text
    editing = { st: st, field: field, el: el, node: node, oneLine: !isRich(st, field) };
    el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'true');
    el.classList.add('evt-editing');
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* old browser */ }
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
    var ed = editing, t = ed.st.texts[ed.field];
    editing = null;
    var after = cleanHtml(ed.el.innerHTML, !ed.oneLine);
    if (!ed.el.textContent.trim()) after = t.after; // never an empty text: keep the last one
    t.after = sameText(ed.st, ed.field, after, t.before) ? t.before : after;
    ed.el.innerHTML = textChanged(ed.st, ed.field) ? t.after : t.shown;
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
        btn.type = 'button'; btn.setAttribute('data-cmd', b[0]); btn.textContent = b[1]; btn.title = b[2];
        textBar.appendChild(btn);
      });
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
    if (e.key === 'Enter' && editing.oneLine) {
      if (!e.shiftKey) { stopEdit(); return true; }
      document.execCommand('insertLineBreak');
      return true;
    }
    if ((e.ctrlKey || e.metaKey) && !e.altKey) {
      var k = e.key.toLowerCase();
      if (k === 'b') { command('bold'); return true; }
      if (k === 'i') { command('italic'); return true; }
      if (k === 'k') { command('link'); return true; }
      if (k === 'u') return true;
    }
    return false;
  }
  function insertPlain(text) {
    text = String(text || '').replace(/\r\n?/g, '\n');
    if (editing.oneLine) text = text.replace(/\s*\n\s*/g, ' ');
    text.split('\n').forEach(function (line, i) {
      if (i) document.execCommand(editing.oneLine ? 'insertLineBreak' : 'insertParagraph');
      if (line) document.execCommand('insertText', false, line);
    });
  }
  document.addEventListener('paste', function (e) {
    if (!editing || !editing.el.contains(e.target)) return;
    e.preventDefault();
    insertPlain((e.clipboardData || window.clipboardData).getData('text'));
  }, true);
  document.addEventListener('drop', function (e) {
    e.preventDefault();
    if (editing && editing.el.contains(e.target) && e.dataTransfer) insertPlain(e.dataTransfer.getData('text/plain'));
  }, true);
  document.addEventListener('dragover', function (e) { e.preventDefault(); }, true);
  document.addEventListener('input', function (e) { if (editing && editing.el.contains(e.target)) notify(); }, true);

  // ── Selection and the box ─────────────────────────────────────────────
  function select(node) {
    if (editing && (!node || node !== editing.node)) stopEdit();
    if (picking && (!node || idOf(node) !== picking)) stopPick(); // evive #578
    if (selected) selected.classList.remove('evt-selected');
    selected = node || null;
    if (selected) { selected.classList.remove('evt-hover'); selected.classList.add('evt-selected'); }
    box.hidden = !selected || !!editing;
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

  // ── Values per device ─────────────────────────────────────────────────
  function cur() { return selected ? state[idOf(selected)] : null; }
  function dv(st, d) { return st.dev[d] || {}; }
  function setDev(st, d, patch) {
    var v = Object.assign({}, st.dev[d] || {}, patch);
    Object.keys(v).forEach(function (k) { if (v[k] === undefined) delete v[k]; });
    if (Object.keys(v).length) st.dev[d] = v; else delete st.dev[d];
  }
  var origDev = function (st) { return JSON.parse(st.orig); };
  function changedAt(st, d, prop) {
    var o = origDev(st)[d] || {}, n = st.dev[d] || {};
    if (prop === 'padding') return JSON.stringify([o.padding || null, o.padding_unit || null]) !== JSON.stringify([n.padding || null, n.padding_unit || null]);
    if (prop === 'crop') return JSON.stringify(cropNorm(o.crop)) !== JSON.stringify(cropNorm(n.crop)); // evive #578
    return JSON.stringify(o[prop] == null ? null : o[prop]) !== JSON.stringify(n[prop] == null ? null : n[prop]);
  }
  // The device whose value applies on device d (Elementor: desktop -> tablet -> mobile), or null.
  function owner(st, d, prop) {
    if (prop === 'hidden') return d;
    if (prop === 'shadow' || prop === 'opacity') return dv(st, 'desktop')[prop] ? 'desktop' : null; // evive #578: one for all devices
    if (prop === 'width' && st.row.el_type === 'column') return dv(st, d).width ? d : null; // columns are full width on phones
    for (var i = DEVS.indexOf(d); i >= 0; i--) if (dv(st, DEVS[i])[prop]) return DEVS[i];
    return null;
  }

  function px(v) { return Math.round(parseFloat(v) || 0); }
  // Taller / Shorter: the padding of a section or container on this device, all four sides in px.
  function paddingNow(st) {
    var o = owner(st, device, 'padding'), v = o ? dv(st, o) : null;
    if (v && (v.padding_unit || 'px') === 'px' && v.padding) {
      var cs0 = getComputedStyle(st.node), p = {};
      ['top', 'right', 'bottom', 'left'].forEach(function (k) {
        p[k] = v.padding[k] == null ? px(cs0['padding' + k[0].toUpperCase() + k.slice(1)]) : v.padding[k];
      });
      return p;
    }
    var cs = getComputedStyle(st.node);
    return { top: px(cs.paddingTop), right: px(cs.paddingRight), bottom: px(cs.paddingBottom), left: px(cs.paddingLeft) };
  }
  function space(n) {
    var st = cur();
    if (!st || !isSection(st)) return;
    var p = paddingNow(st);
    p.bottom = Math.max(0, Math.min(1000, p.bottom + n));
    setDev(st, device, { padding: p, padding_unit: 'px' });
    changed();
  }

  // Width -/+: the width of this device in its unit (saved, else measured in %).
  function widthNow(st) {
    var o = owner(st, device, 'width');
    if (o) return dv(st, o).width;
    var n = st.node, t = st.row.el_type, w = n.getBoundingClientRect().width, base;
    if (t === 'section') { var c = n.querySelector(':scope > .elementor-container'); base = w; w = c ? c.getBoundingClientRect().width : w; }
    else if (t === 'container' && n.querySelector(':scope > .e-con-inner')) { base = w; w = n.querySelector(':scope > .e-con-inner').getBoundingClientRect().width; }
    else {
      var p = n.parentElement, cs = p ? getComputedStyle(p) : null;
      base = p ? p.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0) : w;
    }
    return { value: Math.max(1, Math.min(100, Math.round(w / (base || w || 1) * 100))), unit: '%' };
  }
  function resize(dir) {
    var st = cur();
    if (!st || !(st.row.width_units || []).length) return;
    var w = widthNow(st), unit = (st.row.width_units.indexOf(w.unit) >= 0) ? w.unit : st.row.width_units[0];
    var step = unit === 'px' ? 20 : 5, min = unit === 'px' ? 20 : (st.row.el_type === 'column' ? 10 : 5), max = unit === 'px' ? 3000 : 100;
    var v = Math.round(w.value / step) * step + dir * step;
    setDev(st, device, { width: { value: Math.max(min, Math.min(max, v)), unit: unit } });
    changed();
  }
  function toggleHidden(d) {
    var st = cur();
    if (!st || st.row.el_type === 'column') return;
    setDev(st, d, { hidden: dv(st, d).hidden ? undefined : true });
    changed();
  }

  // ── Moves ─────────────────────────────────────────────────────────────
  function siblingsOf(st) { return orders[st.row.parent || ''] || { orig: [], now: [] }; }
  function move(dir) {
    var st = cur();
    if (!st) return;
    var o = siblingsOf(st), i = o.now.indexOf(st.row.id), j = i + (dir === 'up' ? -1 : 1);
    if (i < 0 || j < 0 || j >= o.now.length) return;
    var other = o.now[j];
    o.now[j] = st.row.id; o.now[i] = other;
    var on = state[other] ? state[other].node : document.querySelector('[data-evive-elt="' + other + '"]');
    if (on && on.parentNode === st.node.parentNode) {
      if (dir === 'up') on.parentNode.insertBefore(st.node, on);
      else on.parentNode.insertBefore(st.node, on.nextSibling);
    }
    changed();
  }
  function resetOrder(key) {
    var o = orders[key];
    if (!o || o.now.join() === o.orig.join()) return false;
    var nodes = o.orig.map(function (id) { return document.querySelector('[data-evive-elt="' + id + '"]'); }).filter(Boolean);
    if (nodes.length) {
      var parent = nodes[0].parentNode, group = nodes.filter(function (n) { return n.parentNode === parent; });
      var last = group.reduce(function (a, n) { return a && (a.compareDocumentPosition(n) & Node.DOCUMENT_POSITION_FOLLOWING) ? n : a; }, group[0]);
      var tail = last ? last.nextSibling : null, ref = tail;
      for (var i = group.length - 1; i >= 0; i--) { parent.insertBefore(group[i], ref); ref = group[i]; }
    }
    o.now = o.orig.slice();
    return true;
  }
  // The moves from the saved order to the new one: each element moved up past its neighbours, one step at a time.
  function moveChanges() {
    var out = [];
    Object.keys(orders).forEach(function (k) {
      var o = orders[k];
      if (o.now.join() === o.orig.join()) return;
      var arr = o.orig.slice();
      o.now.forEach(function (id, i) {
        var j = arr.indexOf(id);
        while (j > i) { out.push({ type: 'move', id: id, direction: 'up' }); arr[j] = arr[j - 1]; arr[j - 1] = id; j--; }
      });
    });
    return out;
  }

  // ── Live CSS ──────────────────────────────────────────────────────────
  function sel(id) { return '[data-evive-elt="' + String(id).replace(/[^A-Za-z0-9_-]/g, '') + '"][data-evive-elt]'; }
  function rulesFor(st, d) {
    var r = [], v, s = sel(st.row.id), out = '';
    if (dv(st, d).hidden) r.push('opacity:.3', 'outline:2px dashed #c0392b', 'outline-offset:-2px');
    var po = owner(st, d, 'padding');
    if (po && changedAt(st, po, 'padding') && (v = dv(st, po)).padding) {
      var u = v.padding_unit || 'px';
      ['top', 'right', 'bottom', 'left'].forEach(function (k) {
        if (v.padding[k] == null) return;
        r.push('--padding-' + k + ':' + v.padding[k] + u, 'padding-' + k + ':' + v.padding[k] + u + ' !important');
      });
    }
    var wo = owner(st, d, 'width');
    if (wo && changedAt(st, wo, 'width') && (v = dv(st, wo)).width) {
      var w = v.width.value + v.width.unit, t = st.row.el_type;
      if (t === 'widget') r.push('width:' + w + ' !important', 'max-width:' + w + ' !important', '--container-widget-width:' + w, '--container-widget-flex-grow:0', 'flex-basis:auto !important');
      else if (t === 'column') r.push('width:' + w + ' !important', '--width:' + w);
      else if (t === 'container') {
        if (st.node.querySelector(':scope > .e-con-inner')) { r.push('--content-width:' + w); out += s + '>.e-con-inner{max-width:' + w + ' !important}'; }
        else r.push('--width:' + w, 'width:' + w + ' !important');
      } else if (t === 'section') out += s + '>.elementor-container{max-width:' + w + ' !important}';
    }
    out += basicCss(st, d, s); // evive #578
    if (r.length) out = s + '{' + r.join(';') + '}' + out;
    return out;
  }
  function render() {
    var out = '';
    Object.keys(state).forEach(function (id) {
      DEVS.forEach(function (d) {
        var css = rulesFor(state[id], d);
        if (css && MQ[d]) out += '@media ' + MQ[d] + '{' + css + '}';
      });
    });
    live.textContent = out;
  }
  function changed() { render(); place(); notify(); }

  // ── Tweak basics (evive #578, MCP #576) ───────────────────────────────
  var BASIC_PROPS = ['z', 'radius', 'shadow', 'opacity', 'align', 'crop'];
  var RADIUS_PX = { none: 0, s: 4, m: 8, l: 16, round: 999 }; // the MCP's ELT_RADIUS
  var SHADOW_CSS = { soft: '0px 2px 8px 0px rgba(0,0,0,0.12)', medium: '0px 6px 20px 0px rgba(0,0,0,0.18)' }; // the MCP's ELT_SHADOW (Elementor's own values)
  var ASPECT = { '16-9': 9 / 16, '4-3': 3 / 4, '1-1': 1, '3-4': 4 / 3 };
  var FX = ['left', 'center', 'right'], FY = ['top', 'center', 'bottom'];
  function cropNorm(c) { return c && c.height ? { height: c.height, unit: c.unit || 'px', focus: c.focus || null } : null; }
  function snap(n) { return n < 100 / 3 ? 0 : n > 200 / 3 ? 100 : 50; } // the nearest of Elementor's nine positions
  function focusPos(f) { return FY[f.y / 50] + ' ' + FX[f.x / 50]; } // Elementor's object-position ("top left")
  function imgOf(st) { return st.row.widget_type === 'image' ? st.node.querySelector('img') : null; }
  // The value of a basic that applies on device d (tablet and mobile follow the device above them), in dev map m.
  function eff(m, d, prop) {
    if (prop === 'shadow' || prop === 'opacity') return (m.desktop || {})[prop] || null;
    for (var i = DEVS.indexOf(d); i >= 0; i--) { var v = (m[DEVS[i]] || {})[prop]; if (v) return v; }
    return null;
  }
  function tweakOf(st) {
    var m = st.dev, c = eff(m, device, 'crop'), t = {};
    ['z', 'radius', 'shadow', 'opacity', 'align'].forEach(function (k) { var v = eff(m, device, k); if (v) t[k] = v; });
    if (c) { t.cropped = c; t.aspect = c.aspect || 'custom'; if (c.focus) t.focus = c.focus; }
    return t;
  }
  function basicWords(st, t, parts) {
    if (t.z) parts.push(t.z === 5 ? 'in front' : t.z === -5 ? 'at the back' : 'layer ' + (t.z > 0 ? '+' : '') + t.z);
    if (t.radius) parts.push({ none: 'square corners', round: 'round corners', custom: 'own corners' }[t.radius] || 'corners ' + { s: 'small', m: 'medium', l: 'large' }[t.radius]);
    if (t.shadow) parts.push(t.shadow === 'custom' ? 'own shadow' : t.shadow + ' shadow');
    if (t.opacity) parts.push(t.opacity === 'custom' ? 'own opacity' : t.opacity + '% opacity');
    if (t.align) parts.push('aligned ' + t.align);
    if (t.cropped) parts.push('cropped ' + (ASPECT[t.aspect] ? t.aspect.replace('-', ':') + ' (' + t.cropped.height + (t.cropped.unit || 'px') + ' high)' : 'to ' + t.cropped.height + (t.cropped.unit || 'px') + ' high'));
    if (t.focus) parts.push('focal point ' + focusPos(t.focus));
    if (picking === st.row.id) parts.push('click the image to set its focal point (Esc cancels)');
    if (structural[st.row.id]) parts.push(structural[st.row.id].op === 'delete' ? 'deleted on Save' : 'duplicated on Save');
  }
  // Toolbar keys -> this element's values. all (Style): corners on desktop, tablet and mobile follow it.
  function setBasic(key, value, all) {
    var st = cur();
    if (!st) return;
    if (editing) stopEdit();
    var b = st.row.basics || {}, d = device, own = function (k, v) { var p = {}; p[k] = v || undefined; setDev(st, d, p); };
    if (key === 'z') own('z', value);
    else if (key === 'align') { if (!b.align) return; own('align', value); }
    else if (key === 'radius') { d = all ? 'desktop' : device; own('radius', value); if (all) ['tablet', 'mobile'].forEach(function (x) { setDev(st, x, { radius: undefined }); }); }
    else if (key === 'shadow') { d = 'desktop'; own('shadow', value === 'none' ? null : value); }
    else if (key === 'opacity') { if (!b.opacity) return; d = 'desktop'; own('opacity', value === 100 ? null : value); }
    else if (key === 'aspect') {
      if (!b.crop) return;
      if (!value) own('crop', null);
      else {
        var im = imgOf(st), w = im ? im.getBoundingClientRect().width : 0, c = eff(st.dev, d, 'crop');
        if (!w || !ASPECT[value]) return;
        own('crop', { height: Math.max(20, Math.min(2000, Math.round(w * ASPECT[value]))), unit: 'px', focus: c && c.focus ? c.focus : undefined, aspect: value });
      }
    } else if (key === 'focus') {
      var cc = eff(st.dev, d, 'crop');
      if (!b.crop || !cc) return;
      own('crop', Object.assign({}, cc, { focus: value || undefined }));
    } else return;
    var prop = key === 'aspect' || key === 'focus' ? 'crop' : key;
    if (!value && d !== 'desktop' && owner(st, d, prop) && owner(st, d, prop) !== d) {
      hint('Elementor uses the ' + NAMES[owner(st, d, prop)] + ' value on ' + NAMES[d] + ' too: change it there, or pick another value for ' + NAMES[d] + '.');
    }
    changed();
  }
  // The change set of one element on device d (the MCP #576 types).
  function basicChanges(st, d, out) {
    var v = dv(st, d), id = st.row.id;
    if (changedAt(st, d, 'z')) out.push({ type: 'layer', id: id, device: d, z: v.z || null });
    if (changedAt(st, d, 'radius')) out.push({ type: 'corners', id: id, device: d, radius: v.radius || null });
    if (changedAt(st, d, 'align')) out.push({ type: 'align', id: id, device: d, align: v.align || null });
    if (changedAt(st, d, 'crop')) {
      var c = cropNorm(v.crop);
      out.push(c ? { type: 'crop', id: id, device: d, height: c.height, unit: c.unit, focus: c.focus } : { type: 'crop', id: id, device: d, height: null });
    }
    if (d !== 'desktop') return;
    if (changedAt(st, d, 'shadow')) out.push({ type: 'shadow', id: id, shadow: v.shadow || 'none' });
    if (changedAt(st, d, 'opacity')) out.push({ type: 'opacity', id: id, opacity: v.opacity || 100 });
  }
  // Live CSS of the basics on device d, where it differs from the page as saved (Elementor's own CSS shows the rest).
  function basicCss(st, d, s) {
    var o = origDev(st), out = '', t = st.row.el_type, wt = st.row.widget_type, img = wt === 'image';
    function diff(p) { var a = eff(st.dev, d, p), b = eff(o, d, p); return p === 'crop' ? JSON.stringify(cropNorm(a)) !== JSON.stringify(cropNorm(b)) : JSON.stringify(a) !== JSON.stringify(b); }
    function rule(sels, decl) { out += sels.map(function (x) { return s + x; }).join(',') + '{' + decl + '}'; }
    // Where Elementor puts corners and shadow (its selectors per kind of element; with and without the widget container).
    var box = img ? [' img'] : t === 'widget' ? ['', '>.elementor-widget-container'] : t === 'column' ? ['>.elementor-element-populated', '>.elementor-widget-wrap'] : t === 'section' ? ['', '>.elementor-background-overlay'] : [''];
    if (diff('z')) { var z = eff(st.dev, d, 'z'); rule([''], z ? 'z-index:' + z + ' !important;--z-index:' + z : 'z-index:auto !important;--z-index:auto'); }
    if (diff('radius')) { var r = eff(st.dev, d, 'radius'); r = r && RADIUS_PX[r] !== undefined ? RADIUS_PX[r] + 'px' : '0px'; rule(box, 'border-radius:' + r + ' !important;--border-radius:' + r); }
    if (diff('shadow')) { var sh = SHADOW_CSS[eff(st.dev, d, 'shadow')] || 'none'; rule(box, 'box-shadow:' + sh + ' !important'); }
    if (diff('opacity') && img) { var op = eff(st.dev, d, 'opacity'); rule([' img'], 'opacity:' + (op && op !== 'custom' ? op / 100 : 1) + ' !important'); }
    if (diff('align') && st.row.basics && st.row.basics.align) {
      var a = eff(st.dev, d, 'align');
      if (st.row.basics.align === 'own') rule(['', '>.elementor-widget-container', ' .elementor-heading-title', ' .elementor-icon-wrapper'], 'text-align:' + (a && a !== 'custom' ? a : 'revert') + ' !important');
      else rule([''], 'align-self:' + ({ left: 'flex-start', center: 'center', right: 'flex-end' }[a] || 'auto') + ' !important');
    }
    var c = cropNorm(eff(st.dev, d, 'crop'));
    if (img && picking === st.row.id) rule([' img'], 'height:auto !important;object-fit:fill !important'); // uncropped while picking
    else if (img && diff('crop')) {
      if (c) rule([' img'], 'height:' + c.height + c.unit + ' !important;object-fit:cover !important;object-position:' + (c.focus ? focusPos(c.focus) : 'center center') + ' !important');
      else rule([' img'], 'height:auto !important;object-fit:fill !important;object-position:50% 50% !important');
    }
    return out;
  }
  // Focal point: "Set focal point", then a click on the (uncropped) image; anywhere else ends picking.
  function startPick() {
    var st = cur();
    if (!st || !imgOf(st) || !eff(st.dev, device, 'crop')) { hint('Crop the image first (Crop on this device): Elementor only uses a focal point on a cropped image.'); return; }
    picking = st.row.id;
    document.documentElement.classList.add('evt-picking');
    box.hidden = true;
    changed();
  }
  function stopPick() {
    if (!picking) return;
    picking = null;
    document.documentElement.classList.remove('evt-picking');
    box.hidden = !selected || !!editing;
    changed();
  }
  function pickAt(x, y) {
    var st = state[picking], im = st && imgOf(st), r = im && im.getBoundingClientRect();
    stopPick();
    if (!r || !r.width || !r.height || x < r.left || x > r.right || y < r.top || y > r.bottom) return;
    if (selected !== st.node) select(st.node);
    setBasic('focus', { x: snap((x - r.left) / r.width * 100), y: snap((y - r.top) / r.height * 100) });
    hint('Focal point set (Elementor has nine positions: the nearest one is used).');
  }
  // Duplicate / Delete on Save (a second click or Reset undoes it). The copy is shown right after the element.
  function ancestors(st) { var out = [], p = st.row.parent; while (p && rows[p]) { out.push(p); p = rows[p].parent; } return out; }
  function structOk(st) { return !ancestors(st).some(function (a) { return structural[a] && structural[a].op === 'delete'; }); }
  function toggleStructural(op) {
    var st = cur();
    if (!st) return;
    if (editing) stopEdit();
    var id = st.row.id, x = structural[id];
    if (x) undoStructural(id);
    if (x && x.op === op) { changed(); return; }
    if (!structOk(st)) return;
    x = structural[id] = { op: op };
    if (op === 'duplicate') {
      var c = st.node.cloneNode(true);
      [c].concat(Array.prototype.slice.call(c.querySelectorAll('[data-evive-elt]'))).forEach(function (n) {
        n.removeAttribute('data-evive-elt'); n.removeAttribute('contenteditable');
        n.classList.remove('evt-selected', 'evt-hover', 'evt-text', 'evt-editing', 'evt-deleted');
      });
      c.classList.add('evt-copy');
      c.setAttribute('aria-label', 'Copy of ' + (st.row.label || id) + ' (added on Save)');
      st.node.after(c);
      x.copy = c;
    } else st.node.classList.add('evt-deleted');
    changed();
  }
  function undoStructural(id) {
    var x = structural[id];
    if (!x) return;
    if (x.copy) x.copy.remove();
    if (state[id]) state[id].node.classList.remove('evt-deleted');
    delete structural[id];
  }

  // ── The change set, count and info for the toolbar ────────────────────
  function changes() {
    var out = [];
    Object.keys(state).forEach(function (id) {
      var st = state[id];
      Object.keys(st.texts).forEach(function (f) {
        if (textChanged(st, f)) out.push({ type: 'text', id: id, field: f, before: st.texts[f].before, after: st.texts[f].after });
      });
      DEVS.forEach(function (d) {
        var v = dv(st, d);
        if (changedAt(st, d, 'padding')) out.push({ type: 'spacing', id: id, device: d, padding: v.padding || null, unit: v.padding_unit || 'px' });
        if (changedAt(st, d, 'width')) out.push(v.width ? { type: 'width', id: id, device: d, value: v.width.value, unit: v.width.unit } : { type: 'width', id: id, device: d, value: null });
        if (changedAt(st, d, 'hidden')) out.push({ type: 'hidden', id: id, device: d, hidden: !!v.hidden });
        basicChanges(st, d, out); // evive #578
      });
    });
    out = out.concat(moveChanges());
    Object.keys(structural).forEach(function (id) { out.push({ type: structural[id].op, id: id }); }); // evive #578: sent last, one per call
    return out;
  }
  function count() {
    var n = 0;
    changes().forEach(function (c) { if (c.type !== 'move') n++; });
    Object.keys(orders).forEach(function (k) { if (orders[k].now.join() !== orders[k].orig.join()) n++; });
    return n;
  }
  function info() {
    var st = cur();
    if (!st) return null;
    var r = st.row, v = dv(st, device), o = siblingsOf(st), i = o.now.indexOf(r.id), parts = [];
    if (anyText(st)) parts.push('text edited');
    if (v.hidden) parts.push('hidden');
    if (changedAt(st, device, 'padding') && v.padding) parts.push('space at the bottom ' + v.padding.bottom + (v.padding_unit || 'px'));
    if (v.width) parts.push('width ' + v.width.value + v.width.unit);
    if (o.now.join() !== o.orig.join() && o.orig.indexOf(r.id) !== i) parts.push('moved');
    var t = tweakOf(st); // evive #578
    basicWords(st, t, parts);
    return {
      builder: 'elementor', id: r.id, label: r.label || r.el_type, block: r.label || r.el_type, path: r.id,
      el_type: r.el_type, widget_type: r.widget_type || '', device: NAMES[device], summary: parts,
      section: isSection(st), hasWidth: !!(r.width_units || []).length, canHide: r.el_type !== 'column',
      phoneHidden: !!dv(st, 'mobile').hidden, tabletHidden: !!dv(st, 'tablet').hidden,
      canUp: i > 0, canDown: i >= 0 && i < o.now.length - 1, hasParent: !!parentNode(st),
      hasText: !!fieldAt(st, null), textChanged: anyText(st), editing: !!editing,
      shorter: isSection(st) && paddingNow(st).bottom > 0,
      // evive #578: the basics, in the shape of the block toolbar (#575)
      tweak: t, narrow: !!(r.basics && r.basics.align), crop: !!(r.basics && r.basics.crop) && !!imgOf(st),
      canOpacity: !!(r.basics && r.basics.opacity), picking: picking === r.id,
      structural: structural[r.id] ? structural[r.id].op : null, canStructural: structOk(st),
      inner: st.node.querySelectorAll('[data-evive-elt]').length
    };
  }
  function notify() { var s = shell(); if (s) s.changed(info(), count()); }

  // After a Save: what was sent is the new start; the texts and base_hash are read again from the site (as stored).
  function saved(hash) {
    if (editing) stopEdit();
    Object.keys(state).forEach(function (id) {
      var st = state[id];
      Object.keys(st.texts).forEach(function (f) { var t = st.texts[f]; if (textChanged(st, f)) { t.before = t.after; t.shown = null; } });
      st.orig = JSON.stringify(st.dev);
    });
    Object.keys(orders).forEach(function (k) { orders[k].orig = orders[k].now.slice(); });
    Object.keys(structural).forEach(undoStructural); // evive #578: the shell reads the page again after a duplicate / delete
    if (hash) baseHash = String(hash);
    notify();
    if (!cfg.rest || !cfg.token) return;
    fetch(cfg.rest + 'elementor?post_id=' + encodeURIComponent(cfg.post_id), { credentials: 'omit', headers: { 'X-Evive-Tweak': cfg.token } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.base_hash) return;
        baseHash = j.base_hash;
        (j.elements || []).forEach(function (r) {
          var st = state[r.id];
          if (!st || !r.texts) return;
          Object.keys(r.texts).forEach(function (f) {
            var t = st.texts[f];
            if (t && !textChanged(st, f) && !(editing && editing.st === st)) { t.before = t.after = r.texts[f]; }
          });
        });
      }).catch(function () { /* keep what was sent */ });
  }

  var api = {
    builder: 'elementor',
    setDevice: function (d) { d = d === 'phone' ? 'mobile' : d; if (DEVS.indexOf(d) >= 0) { device = d; notify(); } },
    selectParent: function () { var p = parentNode(cur()); if (p) select(p); },
    deselect: function () { select(null); },
    select: function (id) { var st = state[String(id)]; select(st ? st.node : null); },
    resize: function (delta) { resize(delta < 0 ? -1 : 1); },
    space: function (n) { space(n * 10); },
    togglePhoneHidden: function () { toggleHidden('mobile'); },
    toggleTabletHidden: function () { toggleHidden('tablet'); },
    move: move,
    reset: function () {
      var st = cur();
      if (!st) return;
      if (editing) stopEdit();
      if (structural[st.row.id]) { undoStructural(st.row.id); changed(); return; } // evive #578: a duplicate / delete first
      // A text edit first, then this device's values, then the order of the element's parent.
      var f = Object.keys(st.texts).filter(function (x) { return textChanged(st, x); })[0];
      if (f) {
        var t = st.texts[f], el = fieldEl(st, f);
        t.after = t.before;
        if (el) el.innerHTML = t.shown != null ? t.shown : t.before;
        changed();
        return;
      }
      var o = origDev(st);
      if (['padding', 'width', 'hidden'].concat(BASIC_PROPS).some(function (p) { return changedAt(st, device, p); })) {
        if (o[device]) st.dev[device] = o[device]; else delete st.dev[device];
        changed();
        return;
      }
      if (resetOrder(st.row.parent || '')) { changed(); return; }
      hint('Nothing to reset on ' + NAMES[device] + ' for this element.');
    },
    editText: function () { var st = cur(), f = st && fieldAt(st, null); if (f) startEdit(st.node, f); },
    // evive #578: the basics, called by the #575 toolbar (tweak-shell.js)
    setBasic: function (key, value, all) { setBasic(key, value, all); },
    layer: function (op) {
      var st = cur(); if (!st) return;
      var z = tweakOf(st).z || 0;
      z = op === 'front' ? 5 : op === 'back' ? -5 : op === 'up' ? z + 1 : op === 'down' ? z - 1 : 0;
      setBasic('z', Math.max(-5, Math.min(5, z)) || null);
    },
    pickFocus: function () { if (picking) stopPick(); else startPick(); },
    duplicate: function () { toggleStructural('duplicate'); },
    remove: function () { toggleStructural('delete'); },
    stopEdit: function () { stopEdit(); },
    refresh: function () { notify(); },
    changes: changes,
    baseHash: function () { return baseHash; },
    info: info,
    saved: saved,
    sectionDirty: function () { return false; },
    key: function (e) {
      if (editing) return editKey(e);
      var tag = (e.target && e.target.tagName) || '';
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) && e.target.ownerDocument === document) return false;
      var st = cur();
      switch (e.key) {
        case 'Escape': if (picking) { stopPick(); return true; } if (!selected) return false; select(null); return true; // evive #578
        case 'Enter':
          if (!e.target || e.target.ownerDocument !== document) return false;
          /* falls through */
        case 'F2': if (!st || !fieldAt(st, null)) return false; api.editText(); return true;
        case '+': case '=': if (!st) return false; if (isSection(st)) space(e.shiftKey ? 50 : 10); else resize(1); return true;
        case '-': case '_': if (!st) return false; if (isSection(st)) space(e.shiftKey ? -50 : -10); else resize(-1); return true;
      }
      return false;
    }
  };
  window.eviveTweak = api;

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
