/* evive #522: the block editor knows the eviveTweak attribute (set by evive's tweak mode), so it is kept when the page
 * is saved in the editor. The saved block HTML is not changed (the Connector renders the tweak as CSS on the front end),
 * so blocks never show "unexpected content". A small sidebar panel shows the tweak and can remove it. */
(function (wp) {
  if (!wp || !wp.hooks || !wp.compose || !wp.element) return;
  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var be = wp.blockEditor || wp.editor || {};
  var c = wp.components || {};
  var SIZES = { desktop: 'Desktop', tablet: 'Tablet', phone: 'Phone' };

  wp.hooks.addFilter('blocks.registerBlockType', 'evive/tweak-attribute', function (settings) {
    var attrs = settings.attributes || {};
    if (attrs.eviveTweak) return settings;
    return Object.assign({}, settings, {
      attributes: Object.assign({}, attrs, { eviveTweak: { type: 'object' } })
    });
  });

  function summary(t) {
    var out = [];
    Object.keys(SIZES).forEach(function (s) {
      var v = t && t[s];
      if (!v) return;
      var parts = [];
      if (v.hidden) parts.push('hidden');
      if (v.x || v.y) parts.push('moved ' + (v.x || 0) + ', ' + (v.y || 0));
      if (v.width) parts.push('width ' + v.width + '%');
      if (v.top) parts.push(v.top + (v.top === 1 ? ' step' : ' steps') + ' more space at the top'); // evive #532
      if (v.bottom) parts.push(v.bottom + (v.bottom === 1 ? ' step' : ' steps') + ' more space at the bottom');
      // evive #575: tweak basics
      if (v.z) parts.push('layer ' + (v.z > 0 ? '+' : '') + v.z);
      if (v.radius) parts.push('corners ' + v.radius);
      if (v.shadow) parts.push('shadow ' + v.shadow);
      if (v.opacity) parts.push(v.opacity + '% opacity');
      if (v.align) parts.push('aligned ' + v.align);
      if (v.aspect) parts.push('cropped ' + String(v.aspect).replace('-', ':'));
      if (v.focus) parts.push('focal point ' + v.focus.x + '%, ' + v.focus.y + '%');
      if (v.clip) parts.push('lets blocks stick out'); // evive #581
      if (v.overlay) parts.push('overlay (out of the layout)'); // evive #582
      if (parts.length) out.push(SIZES[s] + ': ' + parts.join(', '));
    });
    return out;
  }

  // evive #539: pending changes. On the pending copy: its Publish keeps it a draft, publish from evive. On a live page
  // with a pending copy: its evive changes are not live yet.
  var pending = window.eviveTweakEditor && window.eviveTweakEditor.pending;
  if (pending && wp.data) {
    var show = function () {
      try {
        wp.data.dispatch('core/notices').createNotice('warning', pending.message, { id: 'evive-pending', isDismissible: false });
      } catch (e) { /* no notices store */ }
    };
    if (window.document.readyState === 'loading') window.document.addEventListener('DOMContentLoaded', show); else show();
  }

  if (!be.InspectorControls || !c.PanelBody || !c.Button) return;
  var withPanel = wp.compose.createHigherOrderComponent(function (BlockEdit) {
    return function (props) {
      var lines = summary(props.attributes && props.attributes.eviveTweak);
      if (!props.isSelected || !lines.length) return el(BlockEdit, props);
      return el(Fragment, null,
        el(BlockEdit, props),
        el(be.InspectorControls, null,
          el(c.PanelBody, { title: 'evive tweak', initialOpen: true },
            lines.map(function (l, i) { return el('p', { key: i, style: { margin: '0 0 4px' } }, l); }),
            el('p', { style: { margin: '8px 0', color: '#757575' } }, 'Set in evive’s tweak mode; shown on the site, not in the editor.'),
            el(c.Button, {
              variant: 'secondary', isDestructive: true,
              onClick: function () { props.setAttributes({ eviveTweak: undefined }); }
            }, 'Remove tweak')
          )
        )
      );
    };
  }, 'withEviveTweakPanel');
  wp.hooks.addFilter('editor.BlockEdit', 'evive/tweak-panel', withPanel);
})(window.wp);
