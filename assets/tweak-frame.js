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
 * {path, block, text: {before, after}} with before = the saved inner HTML as the page was opened.
 * evive #543: a FAQ (core/details) edits its own <summary> (the question) like a heading. Clicks never open or close a
 * FAQ in tweak mode (so the caret stays); the toolbar's Open FAQ / Close FAQ does, to reach the answer paragraphs.
 * evive #549: a section's new layout (block markup from evive) is rendered by the site (POST evive/v1/tweak/render,
 * tweak token) and swapped in for the section (preview); Use keeps it as a change {path, block, replace}, Cancel / Reset
 * put the old section back. An image block gets another image at once (src, alt) as a change {path, block, image};
 * an image file dropped on an image block goes to the toolbar (tweak-shell.js uploads it).
 * evive #575: tweak basics: z (layer within its section), radius, shadow, opacity, align, and for images aspect (crop)
 * + focus (focal point: Crop > Set focal point, then click the image, shown uncropped while picking); the same keys,
 * values and CSS as Evive_Tweak (PHP) and the evive MCP (#573). Duplicate / Delete of a block are changes of their own
 * ({path, block, before_hash, duplicate|delete: true}, hash = data-evive-hash); the frame shows the copy (not selectable)
 * or the block as deleted until Save, and the page is read again after the Save (the paths after it shift).
 * evive #581: a block that is moved or brought to front (this screen size) and cut off by a clipping block around it
 * (overflow hidden / clip: a cover, a group with rounded corners), up to its section: info() names that block
 * (clippedBy) and the toolbar offers Let it stick out, which sets clip "visible" on it for this screen size (as
 * Evive_Tweak::basics_rules(): overflow visible, the cover's layers inherit its rounded corners). Reset on that block
 * removes it; a Reset on a block inside it removes it too when nothing inside sticks out any more.
 * evive #582: a block with a width gets flex-shrink:0 / flex-basis:auto and its siblings flex-shrink:0 (not a column of
 * Columns), as Evive_Tweak::css(), so its siblings in a flex row keep their size. info() says when the row is then wider than its container (wider)
 * and which block around it grows with it (grows: measured with and without its width). Overlay (overlay true, this
 * screen size) takes it out of the layout (position:absolute, its parent positioned); toggling it keeps the block
 * where it is by adding the difference to x / y (-200..200, MCP #586). Reset removes it with the rest. */
(function () {
  'use strict';
  var cfg = window.eviveTweakFrame || {};
  var MEDIA = cfg.media || {
    desktop: '(min-width: 1025px)', tablet: '(min-width: 601px) and (max-width: 1024px)', phone: '(max-width: 600px)'
  };
  var SIZES = ['desktop', 'tablet', 'phone'];
  var IMAGE_BLOCKS = { 'core/image': 1, 'core/post-featured-image': 1, 'core/site-logo': 1 };
  // evive #575: the basics' values (as Evive_Tweak::BASICS and the MCP), values that mean "none", blocks that crop.
  var BASICS = { radius: ['none', 's', 'm', 'l', 'round'], shadow: ['none', 'soft', 'medium'], opacity: [100, 80, 60, 40], align: ['left', 'center', 'right'], aspect: ['orig', '16-9', '4-3', '1-1', '3-4'] };
  var BASICS_DEFAULT = { opacity: 100, aspect: 'orig' };
  var CROP_BLOCKS = { 'core/image': 1, 'core/post-featured-image': 1, 'core/cover': 1, 'core/media-text': 1 };
  // evive #581: a cover's layers that take its rounded corners when it lets children stick out (Evive_Tweak::COVER_LAYERS).
  var COVER_LAYERS = [' > .wp-block-cover__image-background', ' > .wp-block-cover__video-background', ' > .wp-block-cover__background', ' > .wp-block-cover__gradient-background', ' > .wp-block-cover__embed-background'];
  var RADIUS = { none: '0px', s: 'var(--wp--custom--radius--s, 4px)', m: 'var(--wp--custom--radius--m, 8px)', l: 'var(--wp--custom--radius--l, 16px)', round: '9999px' };
  var SHADOW = { none: 'none', soft: 'var(--wp--preset--shadow--s, var(--wp--preset--shadow--natural, none))', medium: 'var(--wp--preset--shadow--m, var(--wp--preset--shadow--deep, none))' };
  var structural = {}; // evive #575: path -> {op: 'duplicate'|'delete', hash, block, copy (the shown copy)}
  var focusPick = null; // evive #575: path of the image whose focal point the next click sets
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
        var n = Math.max(-200, Math.min(200, Math.round(Number(v[k]) || 0))); // evive #582 (MCP #586): was -40..40
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
      // evive #575: the basics, as Evive_Tweak::sanitize_basics() and the MCP's tweakBasicsClean().
      var z = Math.max(-5, Math.min(5, Math.round(Number(v.z) || 0)));
      if (z) c.z = z;
      Object.keys(BASICS).forEach(function (k) {
        var val = v[k];
        if (val == null || val === '' || typeof val === 'boolean') return;
        var hit = BASICS[k].filter(function (x) { return typeof x === 'number' ? Number(val) === x : val === x; })[0];
        if (hit !== undefined && hit !== BASICS_DEFAULT[k]) c[k] = hit;
      });
      var f = v.focus, ok = function (n) { return n !== null && n !== '' && typeof n !== 'boolean' && isFinite(Number(n)) && Number(n) >= 0 && Number(n) <= 100; };
      if (f && typeof f === 'object' && ok(f.x) && ok(f.y)) c.focus = { x: Math.round(Number(f.x)), y: Math.round(Number(f.y)) };
      if (v.clip === 'visible') c.clip = 'visible'; // evive #581
      if (v.overlay === true) c.overlay = true; // evive #582
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

  // The state of one block from its data attributes (also for the blocks of a new layout, evive #549).
  function makeState(n) {
    var t = {};
    try { t = JSON.parse(n.getAttribute('data-evive-tweak') || '{}') || {}; } catch (e) { t = {}; }
    t = clean(t);
    var base = {};
    try { base = JSON.parse(n.getAttribute('data-evive-space') || '{}') || {}; } catch (e) { base = {}; }
    var st = { block: n.getAttribute('data-evive-block') || '', tweak: t, original: JSON.stringify(t), base: base };
    if (n.hasAttribute('data-evive-text') && textElement(n)) {
      var src = n.getAttribute('data-evive-text');
      st.text = { before: src, after: src };
      n.classList.add('evt-text');
    }
    // The live rules below replace the server's CSS for this block while tweaking.
    Array.prototype.slice.call(n.classList).forEach(function (c) { if (c.indexOf('evive-tw-') === 0) n.classList.remove(c); });
    return st;
  }

  function init() {
    document.querySelectorAll('[data-evive-path]').forEach(function (n) { state[pathOf(n)] = makeState(n); });
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
      if (focusPick && pickAt(e.clientX, e.clientY)) return; // evive #575
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
  var ONE_LINE = { 'core/heading': 1, 'core/button': 1, 'core/list-item': 1, 'core/image': 1, 'core/details': 1 };
  var KEEP = { STRONG: [], B: [], EM: [], I: [], A: ['href', 'target', 'rel'], MARK: ['class', 'style'], SPAN: ['class', 'style'], BR: [], S: [], SUB: [], SUP: [] };
  var DROP = { SCRIPT: 1, STYLE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, TEMPLATE: 1, NOSCRIPT: 1, TEXTAREA: 1, SELECT: 1, SVG: 1, MATH: 1, TITLE: 1, IMG: 1, VIDEO: 1, AUDIO: 1, INPUT: 1, BUTTON: 0 };

  // The element whose inner HTML is the text: the block itself, the button's link, the image's caption.
  function textElement(node) {
    var b = node.getAttribute('data-evive-block');
    if (b === 'core/button') return node.matches('a,button') ? node : node.querySelector('a,button');
    if (b === 'core/image') return node.querySelector('figcaption');
    if (b === 'core/details') return node.querySelector(':scope > summary'); // evive #543: the question
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
    // evive #543: in a <summary> the browser takes Space as "open/close" and types nothing; type it ourselves.
    if (e.key === ' ' && editing.el.tagName === 'SUMMARY' && !e.ctrlKey && !e.metaKey && !e.altKey) {
      document.execCommand('insertText', false, ' ');
      return true;
    }
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

  // evive #549: an image file dropped on an image block: the toolbar uploads it and puts it in.
  var dropOn = null;
  function hasFiles(e) { return !!(e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0); }
  function imageBlockAt(node) {
    var b = blockAt(node);
    return b && b.getAttribute('data-evive-block') === 'core/image' && b.querySelector('img') ? b : null;
  }
  function dropMark(b) {
    if (dropOn === b) return;
    if (dropOn) dropOn.classList.remove('evt-drop');
    dropOn = b;
    if (dropOn) dropOn.classList.add('evt-drop');
  }
  document.addEventListener('dragover', function (e) {
    if (editing || !hasFiles(e)) return;
    e.preventDefault(); // never let the browser open the file instead of the page
    var b = imageBlockAt(e.target);
    e.dataTransfer.dropEffect = b ? 'copy' : 'none';
    dropMark(b);
  }, true);
  document.addEventListener('dragleave', function (e) { if (!e.relatedTarget) dropMark(null); }, true);
  document.addEventListener('drop', function (e) {
    if (editing || !hasFiles(e)) return;
    e.preventDefault(); e.stopPropagation();
    var b = imageBlockAt(e.target), file = e.dataTransfer.files && e.dataTransfer.files[0];
    dropMark(null);
    var s = shell();
    if (!b || !file || !s || !s.dropImage) return;
    select(b);
    s.dropImage(pathOf(b), file);
  }, true);
  document.addEventListener('input', function (e) { if (editing && editing.el.contains(e.target)) notify(); }, true); // live Save count

  // evive #575: picking a focal point: a click on the (uncropped) image sets it; anywhere else ends picking.
  function pickAt(x, y) {
    var node = document.querySelector('[data-evive-path="' + focusPick.replace(/[^0-9.]/g, '') + '"]');
    var im = focusImage(node), r = im && im.getBoundingClientRect();
    var path = focusPick;
    focusPick = null;
    document.documentElement.classList.remove('evt-picking');
    box.hidden = !selected || !!editing;
    if (!r || x < r.left || x > r.right || y < r.top || y > r.bottom || !r.width || !r.height) { render(); notify(); return false; }
    if (selected !== node) select(node);
    update({ focus: { x: Math.round((x - r.left) / r.width * 100), y: Math.round((y - r.top) / r.height * 100) } });
    var s = shell();
    if (s && s.hint) s.hint('Focal point set.');
    return path !== null;
  }
  function startPick() {
    if (!selected || !focusImage(selected)) return;
    focusPick = pathOf(selected);
    document.documentElement.classList.add('evt-picking');
    box.hidden = true; // the click reaches the image, not the selection box
    render(); place(); notify();
  }
  function stopPick() {
    if (!focusPick) return;
    focusPick = null;
    document.documentElement.classList.remove('evt-picking');
    box.hidden = !selected || !!editing;
    render(); place(); notify();
  }

  // evive #575: Duplicate / Delete on Save (a second click undoes it). The copy is shown right after the block.
  function toggleStructural(op) {
    if (!selected) return;
    if (editing) stopEdit();
    var p = pathOf(selected), x = structural[p];
    if (x) undoStructural(p);
    if (x && x.op === op) { place(); notify(); return; }
    if (!structOk(p)) return;
    x = structural[p] = { op: op, hash: selected.getAttribute('data-evive-hash'), block: state[p].block };
    if (op === 'duplicate') {
      var copy = selected.cloneNode(true);
      [copy].concat(Array.prototype.slice.call(copy.querySelectorAll('[data-evive-path]'))).forEach(function (n) {
        ['data-evive-path', 'data-evive-block', 'data-evive-tweak', 'data-evive-text', 'data-evive-hash', 'data-evive-space', 'contenteditable'].forEach(function (a) { n.removeAttribute(a); });
        n.classList.remove('evt-selected', 'evt-hover', 'evt-text', 'evt-editing');
      });
      copy.classList.add('evt-copy');
      copy.setAttribute('aria-label', 'Copy of block ' + p + ' (added on Save)');
      selected.after(copy);
      x.copy = copy;
    } else {
      selected.classList.add('evt-deleted');
    }
    place(); notify();
  }
  function undoStructural(p) {
    var x = structural[p];
    if (!x) return;
    if (x.copy) x.copy.remove();
    var n = document.querySelector('[data-evive-path="' + p.replace(/[^0-9.]/g, '') + '"]');
    if (n) n.classList.remove('evt-deleted');
    delete structural[p];
  }

  function select(node) {
    if (editing && node !== editing.node) stopEdit(); // evive #533
    if (focusPick && node && pathOf(node) !== focusPick) stopPick(); // evive #575
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
      if (t.width && st.block !== 'core/column') r.push('flex-shrink:0', 'flex-basis:auto'); // evive #582
      if (!t.hidden) r = r.concat(spaceRules(t, st.base || {})); // evive #532
      var b = basicRules(focusPick === path ? Object.assign({}, t, { aspect: undefined }) : t, mediaKind(st.block)); // evive #575
      r = r.concat(b.own);
      if (!r.length && !b.subs.length) return;
      out += '@media ' + MEDIA[s] + '{' + (r.length ? sel + '{' + r.join(';') + '}' : '');
      b.subs.forEach(function (x) { out += sel + x[0] + '{' + x[1].join(';') + '}'; });
      if (t.width && IMAGE_BLOCKS[st.block]) out += sel + ' img{width:100% !important;height:auto !important}';
      if (t.width && !t.hidden && st.block !== 'core/column') out += ':where(:has(> ' + sel + ')) > *{flex-shrink:0}'; // evive #582: siblings too
      if (t.overlay && !t.hidden) out += ':where(:has(> ' + sel + ')){position:relative}'; // evive #582
      out += '}';
    });
    // evive #575: a layer stays within its section (the top-level block): that gets a stacking context of its own.
    var top = path.split('.')[0];
    if (top !== path && SIZES.some(function (s) { return st.tweak[s] && st.tweak[s].z && !st.tweak[s].hidden; })) {
      out += '[data-evive-path="' + top.replace(/[^0-9]/g, '') + '"]{isolation:isolate}';
    }
    return out;
  }

  // evive #575: same as Evive_Tweak::media_kind() and basics_rules() in PHP. Returns {own: [rules], subs: [[sel, rules]]}.
  function mediaKind(block) {
    if (IMAGE_BLOCKS[block]) return 'image';
    if (block === 'core/cover') return 'cover';
    if (block === 'core/media-text') return 'media-text';
    return '';
  }
  function basicRules(t, kind) {
    var own = [], subs = {}, order = [];
    function add(sel, rule) { if (!subs[sel]) { subs[sel] = []; order.push(sel); } subs[sel].push(rule); }
    if (t.hidden) return { own: own, subs: [] };
    if (t.overlay) own.push('position:absolute'); // evive #582
    if (t.z) { if (!t.overlay) own.push('position:relative'); own.push('z-index:' + t.z); }
    if (t.opacity) own.push('opacity:' + (t.opacity / 100));
    if (t.align) {
      var m = { left: ['0', 'auto'], center: ['auto', 'auto'], right: ['auto', '0'] }[t.align];
      if (m) own.push('margin-left:' + m[0] + ' !important', 'margin-right:' + m[1] + ' !important');
    }
    var img = kind === 'image' ? ' img' : kind === 'media-text' ? ' .wp-block-media-text__media img' : '';
    if (t.radius && RADIUS[t.radius]) {
      if (img) add(img, 'border-radius:' + RADIUS[t.radius] + ' !important');
      else { own.push('border-radius:' + RADIUS[t.radius] + ' !important'); if (kind === 'cover' && !t.clip) own.push('overflow:hidden'); }
    }
    if (t.shadow && SHADOW[t.shadow]) {
      if (img) add(img, 'box-shadow:' + SHADOW[t.shadow] + ' !important');
      else own.push('box-shadow:' + SHADOW[t.shadow] + ' !important');
    }
    var ratio = t.aspect ? String(t.aspect).replace('-', '/') : '';
    var pos = t.focus ? 'object-position:' + t.focus.x + '% ' + t.focus.y + '% !important' : '';
    if (kind === 'cover') {
      if (ratio) own.push('aspect-ratio:' + ratio + ' !important', 'min-height:0 !important');
      if (pos) add(' .wp-block-cover__image-background', pos);
    } else if (img) {
      if (ratio) { add(img, 'aspect-ratio:' + ratio + ' !important'); add(img, 'height:auto !important'); add(img, 'object-fit:cover !important'); }
      if (pos) add(img, pos);
    }
    if (t.clip === 'visible') { // evive #581
      own.push('overflow:visible !important');
      if (kind === 'cover') COVER_LAYERS.forEach(function (l) { add(l, 'border-radius:inherit'); });
    }
    return { own: own, subs: order.map(function (k) { return [k, subs[k]]; }) };
  }
  // evive #575: the image whose focal point is picked (the cover's background, the media-text's media).
  function focusImage(node) {
    if (!node) return null;
    var k = mediaKind(node.getAttribute('data-evive-block') || '');
    if (k === 'cover') return node.querySelector('.wp-block-cover__image-background');
    if (k === 'media-text') return node.querySelector('.wp-block-media-text__media img');
    if (k === 'image') return node.querySelector('img');
    return null;
  }

  function render() {
    var out = '';
    Object.keys(state).forEach(function (p) { out += css(p, state[p]); });
    live.textContent = out;
  }

  function changes() {
    // evive #549: a new layout is a change of its own (the whole section), an image too (never with a tweak or text).
    var out = [];
    Object.keys(layouts).forEach(function (p) {
      if (layouts[p].used) out.push({ path: p, block: layouts[p].block, replace: layouts[p].used.replace });
    });
    Object.keys(state).forEach(function (p) {
      var im = state[p].image;
      if (im) out.push({ path: p, block: 'core/image', image: { id: im.id, url: im.url, alt: im.alt } });
    });
    // evive #575: duplicates and deletes, each a change of its own (the app sends them last, from the end of the page).
    Object.keys(structural).forEach(function (p) {
      var x = structural[p], c = { path: p, block: x.block, before_hash: x.hash };
      c[x.op] = true;
      out.push(c);
    });
    return out.concat(Object.keys(state).filter(function (p) {
      return JSON.stringify(state[p].tweak) !== state[p].original || textChanged(state[p]);
    }).map(function (p) {
      var st = state[p], t = st.tweak, c = { path: p, block: st.block };
      if (JSON.stringify(t) !== st.original) c.tweak = Object.keys(t).length ? t : null;
      if (textChanged(st)) c.text = { before: st.text.before, after: st.text.after }; // evive #533
      return c;
    }));
  }

  // evive #533: what the Save button counts: every changed tweak and every changed text.
  function count() {
    var n = 0;
    Object.keys(layouts).forEach(function (p) { if (layouts[p].used) n++; }); // evive #549
    Object.keys(state).forEach(function (p) {
      if (JSON.stringify(state[p].tweak) !== state[p].original) n++;
      if (textChanged(state[p])) n++;
      if (state[p].image) n++; // evive #549
    });
    return n + Object.keys(structural).length; // evive #575
  }

  function info() {
    if (!selected) return null;
    var p = pathOf(selected), st = state[p];
    return {
      path: p, block: st.block, device: device, tweak: st.tweak[device] || {},
      phoneHidden: !!(st.tweak.phone && st.tweak.phone.hidden),
      section: isSection(selected), // evive #532
      hasParent: !!parentBlock(selected),
      hasText: !!st.text, textChanged: textChanged(st), editing: !!editing, // evive #533
      details: selected.tagName === 'DETAILS', open: !!selected.open, // evive #543
      // evive #549: the top-level section (Change layout) and the image block (Replace image)
      top: p.indexOf('.') < 0, section_path: p.split('.')[0], sectionDirty: sectionDirty(p.split('.')[0]),
      layout: layoutStatus(p.split('.')[0]),
      image: imageOf(selected) ? { src: imageOf(selected).currentSrc || imageOf(selected).src, alt: st.image ? st.image.alt : imageOf(selected).getAttribute('alt') || '', id: st.image ? st.image.id : null } : null,
      imageChanged: !!st.image,
      // evive #575: the basics
      crop: !!CROP_BLOCKS[st.block] && !!focusImage(selected), picking: focusPick === p,
      narrow: !!(st.tweak[device] && st.tweak[device].align) || selected.getBoundingClientRect().width < parentWidth(selected) - 2,
      structural: structural[p] ? structural[p].op : null, inner: selected.querySelectorAll('[data-evive-path]').length,
      canStructural: structOk(p),
      // evive #581: the block around it that cuts it off, or the one it sticks out of (clip "visible").
      clippedBy: clipInfo(clippedBy(selected)), sticksOutOf: clipInfo(sticksOutOf(selected)),
      // evive #582: the row is wider than its container, the block around it that grows with it, Overlay
      wider: wider(selected), grows: clipInfo(growsWith(selected)), overlay: !!(st.tweak[device] && st.tweak[device].overlay),
      canOverlay: canOverlay(selected)
    };
  }

  // ── One block's size only (evive #582) ─────────────────────────────────

  function widthHere(node) {
    var st = state[pathOf(node)], t = st && st.tweak[device];
    return !!t && !t.hidden && !t.overlay && !!t.width;
  }
  function inFlow(n) {
    var cs = getComputedStyle(n);
    return cs.display !== 'none' && cs.position !== 'absolute' && cs.position !== 'fixed';
  }
  // True when a block with a width on this screen size makes its row (or itself) wider than the parent's content box.
  // Layout widths (offsetWidth), so a move (translate) doesn't count. A row that wraps: only the block itself.
  function wider(node) {
    if (!widthHere(node)) return false;
    var p = node.parentElement;
    if (!p) return false;
    var cs = getComputedStyle(p);
    var inner = p.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0);
    var row = /flex/.test(cs.display) && !/column/.test(cs.flexDirection) && cs.flexWrap === 'nowrap';
    var kids = row ? Array.prototype.filter.call(p.children, inFlow) : [node];
    var total = 0;
    kids.forEach(function (k) {
      var kc = getComputedStyle(k);
      total += k.offsetWidth + (parseFloat(kc.marginLeft) || 0) + (parseFloat(kc.marginRight) || 0);
    });
    if (row && kids.length > 1) total += (parseFloat(cs.columnGap) || 0) * (kids.length - 1);
    return inner > 0 && total > inner + 1;
  }
  // A block around it (up to its section) that you can see grow: a cover, a section, or one with a background or border.
  function visibleBox(n) {
    var b = n.getAttribute('data-evive-block') || '';
    if (b === 'core/cover' || isSection(n)) return true;
    var cs = getComputedStyle(n);
    if (cs.backgroundImage && cs.backgroundImage !== 'none') return true;
    if (parseFloat(cs.borderTopWidth) > 0 || parseFloat(cs.borderBottomWidth) > 0) return true;
    var m = /rgba?\(([^)]+)\)/.exec(cs.backgroundColor || '');
    return !!m && (m[1].split(',').length < 4 || parseFloat(m[1].split(',')[3]) > 0);
  }
  // The nearest such block that is taller with this block's width (this screen size) than without it, or null.
  function growsWith(node) {
    if (!widthHere(node)) return null;
    var list = blockAncestors(node).filter(visibleBox);
    if (!list.length) return null;
    var st = state[pathOf(node)], keep = st.tweak;
    var now = list.map(function (a) { return a.getBoundingClientRect().height; });
    var t = JSON.parse(JSON.stringify(keep));
    delete t[device].width;
    st.tweak = clean(t);
    render();
    var before = list.map(function (a) { return a.getBoundingClientRect().height; });
    st.tweak = keep;
    render();
    for (var i = 0; i < list.length; i++) if (now[i] > before[i] + 1) return list[i];
    return null;
  }
  // Overlay: not for a section (top-level block) or a column of Columns.
  function canOverlay(node) {
    var st = state[pathOf(node)];
    return !!st && (pathOf(node) || '').indexOf('.') >= 0 && st.block !== 'core/column' && !isSection(node);
  }
  // Toggle overlay on this screen size; the block stays where it is: the difference goes to x / y (in steps).
  function toggleOverlay() {
    if (!selected || !canOverlay(selected)) return;
    var st = state[pathOf(selected)], t = JSON.parse(JSON.stringify(st.tweak));
    var a = selected.getBoundingClientRect();
    t[device] = Object.assign({}, t[device] || {});
    t[device].overlay = !t[device].overlay;
    st.tweak = clean(t);
    render();
    var b = selected.getBoundingClientRect(), d = st.tweak[device] || {};
    t = JSON.parse(JSON.stringify(st.tweak));
    t[device] = Object.assign({}, d, { x: (d.x || 0) + Math.round((a.left - b.left) / stepPx), y: (d.y || 0) + Math.round((a.top - b.top) / stepPx) });
    st.tweak = clean(t);
    render(); place(); notify();
  }

  // ── Let it stick out (evive #581) ──────────────────────────────────────

  // The section (top-level block) a block is in: the ancestors are checked up to it.
  function blockAncestors(node) {
    var out = [], n = parentBlock(node);
    while (n) { out.push(n); if ((pathOf(n) || '').indexOf('.') < 0) break; n = parentBlock(n); }
    return out;
  }
  function clips(n) {
    var cs = getComputedStyle(n);
    return /^(hidden|clip)$/.test(cs.overflowX) || /^(hidden|clip)$/.test(cs.overflowY);
  }
  function outside(inner, outer) {
    var a = inner.getBoundingClientRect(), b = outer.getBoundingClientRect();
    return a.width > 0 && a.height > 0 && (a.left < b.left - 1 || a.top < b.top - 1 || a.right > b.right + 1 || a.bottom > b.bottom + 1);
  }
  // Only for a block that is moved or brought to front on this screen size (as the card asks), not for every block.
  function tweakedHere(node) {
    var st = state[pathOf(node)], t = st && st.tweak[device];
    return !!t && !t.hidden && (!!t.x || !!t.y || t.z > 0 || !!t.width || !!t.overlay); // evive #582: a resize or overlay too
  }
  function clippedBy(node) {
    if (!node || !tweakedHere(node)) return null;
    var list = blockAncestors(node);
    for (var i = 0; i < list.length; i++) {
      if (state[pathOf(list[i])] && clips(list[i]) && outside(node, list[i])) return list[i];
    }
    return null;
  }
  function sticksOutOf(node) {
    if (!node) return null;
    var list = blockAncestors(node);
    for (var i = 0; i < list.length; i++) {
      var t = state[pathOf(list[i])] && state[pathOf(list[i])].tweak[device];
      if (t && t.clip && outside(node, list[i])) return list[i];
    }
    return null;
  }
  // "the photo block" (a cover with an image or video), "the cover block", "the card" (a group in the card style), ...
  function clipInfo(n) {
    if (!n) return null;
    var b = n.getAttribute('data-evive-block') || '', name;
    if (b === 'core/cover') name = n.querySelector(':scope > .wp-block-cover__image-background, :scope > .wp-block-cover__video-background') ? 'the photo block' : 'the cover block';
    else if (b === 'core/group' && /(^|\s)is-style-card(\s|$)/.test(n.className)) name = 'the card';
    else name = 'the ' + (b.replace(/^core\//, '') || 'parent') + ' block';
    return { path: pathOf(n), block: b, name: name };
  }
  function setClip(node, on) {
    var st = state[pathOf(node)], t = JSON.parse(JSON.stringify(st.tweak));
    t[device] = Object.assign({}, t[device] || {});
    t[device].clip = on ? 'visible' : null;
    st.tweak = clean(t);
  }
  function stickOut() {
    var n = clippedBy(selected);
    if (!n) return;
    setClip(n, true);
    render(); place(); notify();
  }
  // After a Reset of a block: a block around it that lets children stick out on this screen size stops doing so when
  // nothing inside it sticks out any more.
  function unstick(node) {
    var changed = false;
    blockAncestors(node).forEach(function (a) {
      var t = state[pathOf(a)] && state[pathOf(a)].tweak[device];
      if (!t || !t.clip) return;
      var out = Array.prototype.some.call(a.querySelectorAll('[data-evive-path]'), function (d) { return outside(d, a); });
      if (!out) { setClip(a, false); changed = true; }
    });
    if (changed) render();
  }
  // evive #575: a duplicate / delete needs the block's hash; the app refuses it in a section with a new layout or image.
  function structOk(p) {
    var n = document.querySelector('[data-evive-path="' + p.replace(/[^0-9.]/g, '') + '"]');
    if (!n || !/^[0-9a-f]{64}$/.test(n.getAttribute('data-evive-hash') || '')) return false;
    var top = p.split('.')[0];
    if (layouts[top]) return false;
    return !subtree(top).some(function (k) { return !!state[k].image; });
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

  // ── Layouts and images (evive #549) ────────────────────────────────────

  // path of a top-level section -> {block, orig, used, preview}; each {node, states, replace?, title?}. The one shown
  // is preview || used || orig: its block states are the live ones in `state`, the others keep theirs.
  var layouts = {};
  function sectionNode(path) { return document.querySelector('[data-evive-path="' + String(path).replace(/[^0-9]/g, '') + '"]'); }
  function subtree(path) { return Object.keys(state).filter(function (k) { return k === path || k.indexOf(path + '.') === 0; }); }
  function takeStates(path) { var o = {}; subtree(path).forEach(function (k) { o[k] = state[k]; delete state[k]; }); return o; }
  function shown(L) { return L.preview || L.used || L.orig; }
  function swapTo(path, L, target) {
    var cur = shown(L);
    if (cur === target) return;
    if (editing) stopEdit();
    cur.states = takeStates(path);
    cur.node.replaceWith(target.node);
    Object.keys(target.states || {}).forEach(function (k) { state[k] = target.states[k]; });
    render();
  }
  // Unsaved tweaks, texts or images in a section (a new layout is made from the saved section, so they would be lost).
  function sectionDirty(path) {
    return subtree(path).some(function (k) {
      var st = state[k];
      return JSON.stringify(st.tweak) !== st.original || textChanged(st) || !!st.image;
    });
  }
  function layoutStatus(path) {
    var L = layouts[path];
    if (!L) return null;
    return L.preview ? { state: 'preview', title: L.preview.title || '' } : L.used ? { state: 'used', title: L.used.title || '' } : null;
  }
  function imageOf(node) { return node && node.getAttribute('data-evive-block') === 'core/image' ? node.querySelector('img') : null; }
  // The block styles of a rendered section: stylesheets the page doesn't have yet, inline CSS, block-support CSS.
  function addStyles(j) {
    (j.styles || []).forEach(function (s) {
      if (s.href && !document.getElementById(s.id + '-css')) {
        var l = document.createElement('link');
        l.rel = 'stylesheet'; l.id = s.id + '-css'; l.href = s.href;
        document.head.appendChild(l);
      }
      if (s.inline) { var st = document.createElement('style'); st.setAttribute('data-evt-layout', s.id); st.textContent = s.inline; document.head.appendChild(st); }
    });
    if (j.css) { var c = document.createElement('style'); c.setAttribute('data-evt-layout', 'block-supports'); c.textContent = j.css; document.head.appendChild(c); }
  }
  function previewLayout(path, markup, meta) {
    path = String(path);
    meta = meta || {};
    if (!cfg.rest || !cfg.token) return Promise.reject(new Error('This page has no tweak connection for previews.'));
    if (!sectionNode(path)) return Promise.reject(new Error('Section ' + path + ' is not on this page.'));
    return fetch(cfg.rest + 'render', {
      method: 'POST', credentials: 'omit',
      headers: { 'Content-Type': 'application/json', 'X-Evive-Tweak': cfg.token },
      body: JSON.stringify({ post_id: cfg.post_id, path: path, markup: markup })
    }).then(function (r) {
      return r.text().then(function (t) {
        var j = null; try { j = JSON.parse(t); } catch (e) { /* not JSON */ }
        if (!r.ok || !j || typeof j.html !== 'string') throw new Error((j && j.message) || ('the site answered ' + r.status));
        return j;
      });
    }).then(function (j) {
      var tpl = document.createElement('template');
      tpl.innerHTML = j.html.trim();
      var node = tpl.content.firstElementChild;
      if (!node || pathOf(node) !== path) throw new Error('the site did not render this section');
      addStyles(j);
      var L = layouts[path];
      if (!L) L = layouts[path] = { block: meta.block || (state[path] && state[path].block) || '', orig: { node: sectionNode(path) }, used: null, preview: null };
      var states = {};
      [node].concat(Array.prototype.slice.call(node.querySelectorAll('[data-evive-path]'))).forEach(function (n) { states[pathOf(n)] = makeState(n); });
      var next = { node: node, states: states, replace: { before_hash: meta.before_hash || '', markup: markup }, title: meta.title || '' };
      swapTo(path, L, next);
      L.preview = next;
      select(node);
      try { node.scrollIntoView({ block: 'nearest' }); } catch (e) { /* old browser */ }
      return true;
    });
  }
  function useLayout(path) {
    var L = layouts[String(path)];
    if (!L || !L.preview) return;
    L.used = L.preview; L.preview = null;
    select(shown(L).node);
  }
  function cancelLayout(path) {
    path = String(path);
    var L = layouts[path];
    if (!L || !L.preview) return;
    var back = L.used || L.orig;
    swapTo(path, L, back);
    L.preview = null;
    if (!L.used) delete layouts[path]; // exactly as before
    select(back.node);
  }
  function resetLayout(path) {
    var L = layouts[path];
    if (!L) return;
    swapTo(path, L, L.orig);
    delete layouts[path];
    select(L.orig.node);
  }
  // Show another image at once; Save sends {path, block: "core/image", image: {id, url, alt}}.
  function setImage(path, img) {
    var node = document.querySelector('[data-evive-path="' + String(path).replace(/[^0-9.]/g, '') + '"]');
    var st = node && state[pathOf(node)], el = imageOf(node);
    if (!st || !el || !img || !img.id || !img.url) return false;
    if (!st.imgShown) {
      st.imgShown = {};
      ['src', 'srcset', 'sizes', 'alt'].forEach(function (a) { st.imgShown[a] = el.getAttribute(a); });
    }
    st.image = { id: Number(img.id), url: String(img.url), alt: img.alt == null ? (st.image ? st.image.alt : el.getAttribute('alt') || '') : String(img.alt) };
    el.removeAttribute('srcset'); el.removeAttribute('sizes');
    el.setAttribute('src', st.image.url);
    el.setAttribute('alt', st.image.alt);
    place(); notify();
    return true;
  }
  function resetImage(st, node) {
    var el = imageOf(node);
    if (el && st.imgShown) {
      Object.keys(st.imgShown).forEach(function (a) { if (st.imgShown[a] == null) el.removeAttribute(a); else el.setAttribute(a, st.imgShown[a]); });
    }
    delete st.image; st.imgShown = null;
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
      if (structural[pathOf(selected)]) { undoStructural(pathOf(selected)); place(); notify(); return; } // evive #575: its own step
      if (st.image) { resetImage(st, selected); place(); notify(); return; } // evive #549: the image first
      // evive #533: a text edit is undone first (its own step); the next Reset undoes the tweak of this size.
      if (textChanged(st)) {
        st.text.after = st.text.before;
        var el = textElement(selected);
        if (el) el.innerHTML = st.text.shown != null ? st.text.shown : st.text.before;
        place(); notify();
        return;
      }
      // evive #549: a section with a new layout gets its old section back (with the old tweaks and texts).
      var p = pathOf(selected);
      if (layouts[p] && (layouts[p].used || layouts[p].preview) && shown(layouts[p]).node === selected) { resetLayout(p); return; }
      var t = JSON.parse(JSON.stringify(st.tweak));
      delete t[device];
      st.tweak = clean(t);
      render();
      unstick(selected); // evive #581
      place(); notify(); // evive #532: the box follows at once
    },
    // evive #575: the basics. key: z, radius, shadow, opacity, align, aspect, focus; value null removes it. all: every
    // screen size (corners, shadow, opacity), else this one.
    setBasic: function (key, value, all) {
      if (!selected || !BASICS[key] && key !== 'z' && key !== 'focus') return;
      var st = state[pathOf(selected)], t = JSON.parse(JSON.stringify(st.tweak));
      (all ? SIZES : [device]).forEach(function (s) {
        t[s] = Object.assign({}, t[s] || {});
        t[s][key] = value;
      });
      st.tweak = clean(t);
      render(); place(); notify();
    },
    // front (5), back (-5), up (+1), down (-1), normal (none)
    layer: function (op) {
      var t = cur(); if (!t) return;
      var z = t.z || 0;
      z = op === 'front' ? 5 : op === 'back' ? -5 : op === 'up' ? z + 1 : op === 'down' ? z - 1 : 0;
      api.setBasic('z', Math.max(-5, Math.min(5, z)) || null);
    },
    pickFocus: function () { if (focusPick) stopPick(); else startPick(); },
    duplicate: function () { toggleStructural('duplicate'); },
    stickOut: stickOut, // evive #581
    overlay: toggleOverlay, // evive #582
    remove: function () { toggleStructural('delete'); },
    // evive #549
    previewLayout: previewLayout,
    useLayout: useLayout,
    cancelLayout: cancelLayout,
    setImage: setImage,
    sectionDirty: sectionDirty,
    editText: function () { if (selected) startEdit(selected); }, // evive #533
    // evive #543: open or close the selected FAQ (clicks on its question don't while tweaking).
    toggleOpen: function () {
      if (!selected || selected.tagName !== 'DETAILS') return;
      if (editing) stopEdit();
      selected.open = !selected.open;
      place(); notify();
    },
    stopEdit: function () { stopEdit(); },
    refresh: function () { notify(); }, // evive #549
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
        if (state[p].image) { delete state[p].image; state[p].imgShown = null; } // evive #549: the saved image stays
      });
      Object.keys(layouts).forEach(function (p) { if (layouts[p].used && !layouts[p].preview) delete layouts[p]; }); // evive #549
      structural = {}; // evive #575: the shell reads the page again after a duplicate / delete
      notify();
    },
    key: function (e) {
      if (editing) return editKey(e); // evive #533: while editing text, keys type (arrows move the caret)
      var tag = (e.target && e.target.tagName) || '';
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) && e.target.ownerDocument === document) return false;
      var step = e.shiftKey ? 5 : 1;
      switch (e.key) {
        case 'Escape': if (focusPick) { stopPick(); return true; } if (!selected) return false; api.deselect(); return true; // evive #575
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
