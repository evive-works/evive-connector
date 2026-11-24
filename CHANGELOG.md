# Changelog

Add your change under "## Unreleased" at the top; the release gate turns it into "## X.Y.Z — date".

## Unreleased

- Pending changes for Elementor pages (evive #552). `POST /pending/{type}/{id}` on a published page with
  `_elementor_edit_mode = builder` now makes the draft copy too (before: 400 `evive_pending_no_blocks`), with the page's
  post_content and its Elementor meta as stored: every `_elementor*` key (`_elementor_data` byte for byte,
  `_elementor_page_settings`, `_elementor_edit_mode`, `_elementor_template_type`, `_elementor_version`, ...) and
  `_wp_page_template`; not Elementor's caches, not Theme Builder conditions, not SEO or the slug. Element ids stay the
  same (the copy has its own CSS file). The base (conflict check, 409) of an Elementor page also covers `_elementor_data`
  (evive's Elementor tools change that without touching post_content); block pages keep the old base, so their copies
  made before this release still publish. Publish: a revision of the live page that carries the old Elementor meta
  (always made for an Elementor page, so restoring it gives the old page back), then the copy's Elementor meta and
  post_content on the live post (same ID, URL, slug, SEO, Polylang links; an Elementor key the copy no longer has is
  removed), Elementor's CSS file and caches of the live post cleared, cache purged, the copy to the trash. Discard,
  preview links and the pending band work as for a block page. Status and publish answers say `builder`
  (`elementor` / `blocks`). Publishing an Elementor page runs under the per-page Elementor lock of #558, so an
  `update-widget` on the live page at the same moment waits instead of writing the old tree back (busy > 15 s: 409
  `evive_busy`, nothing published).
- Duplicate a page (evive #552): `POST /evive/v1/duplicate/{id}` `{title?}` (edit rights on the page) makes a draft
  "Title – kopie" (" – copy" when the page's language is not Dutch) with the same content, excerpt, parent, order, all
  meta (Elementor data and settings, template, featured image, custom fields, SEO), terms and Polylang language (no
  translation link). Not copied: slug, edit lock, old slugs, `_evive_*` meta (pending links, preview tokens), Elementor
  caches and Theme Builder conditions. Answers `id`, `title`, `builder`, `edit_url` and a `preview_url` (as
  `POST /preview-token` gives).
- Elementor tools (evive #552): `evive_purge_post_cache()` also deletes Elementor's element cache
  (`_elementor_element_cache`). Without it a widget text changed with `evive_update_widget` kept showing the old text
  (the new colour did show) on sites where Elementor's element caching is on.

## 1.5.2 — 2026-11-24

- Elementor writes no longer lose each other's changes (evive #558). On 23 Nov 2026 (PROD, whyatwork.nl page 13446)
  evive sent several `update-widget` calls for one page in parallel: each read the whole `_elementor_data`, changed
  one widget and wrote the whole tree back, so the last write won. Some calls then failed with `verify_mismatch`
  (the "saved" value was the old text), others reported success but were overwritten. Typographic quotes were not the
  cause: they are stored byte for byte. Now `update-widget`, `inject-text`, `duplicate-element`, `delete-element`,
  `move-element` and `repoint-slideshow-image` run under a per-page lock (MySQL `GET_LOCK`, per database, table prefix
  and post; an option row where `GET_LOCK` is missing) and read the latest tree inside it. Parallel calls now run one
  after the other; a call that waits more than 15 s gets 409 `evive_busy` (nothing written, safe to retry).
- `verify_mismatch` now names the element and says what is on the page: `page_id`, `widget_id`, `widget_type`, and per
  field `settings_key`, the full `saved` value, `previous` (the value before this call) and `unchanged` (true: the old
  text is still there), plus a `hint` for evive (retry this one change alone; if it fails again, tell the user which
  element still shows which text and what to change by hand in Elementor). The `content` form gives the same.
- The verify compares text that only differs in encoding as equal (HTML entities such as `&rsquo;`/`&amp;`, Unicode
  NFC, `&nbsp;` and runs of whitespace). Typographic and straight quotes are still different.
- A database write of `_elementor_data` that the database refuses is now `save_failed` instead of a later mismatch.

## 1.5.1 — 2026-11-23

- Tweak mode: edit the question of a FAQ (`core/details`) in place (evive #543). The details block gets
  `data-evive-text` = the saved inner HTML of its own `<summary>` (the one before its first inner block; none or more
  than one: not editable, as the evive MCP #542 refuses those). Double-click the question (or select the FAQ and press
  F2 / Enter, or "Edit text") to edit it like a heading: one line, Enter ends. Save sends
  `{path, block: "core/details", text: {before, after}}`; the evive MCP (#542) changes the `<summary>` and the block's
  `summary` attribute. Clicks never open or close a FAQ in tweak mode (no toggle while editing, the caret stays); a
  toolbar button **Open FAQ** (enabled for a selected FAQ, pressed while it is open) opens and closes it, so its
  answer paragraphs can be selected and edited as normal paragraphs. Space types a space in the question (the browser
  takes it as open/close in a summary). The page itself is unchanged: outside tweak mode the FAQ opens and closes as before.

## 1.5.0 — 2026-11-21

- Pending changes for published pages (evive #539, design #538). New `includes/class-pending.php`. Routes (evive/v1,
  edit rights on the live post): `POST /pending/{type}/{id}` makes one draft copy of a published block page, or returns
  the one there is (`created` true/false); `GET /pending/{type}/{id}` status (`pending`, `copy_id`, `live_changed`, who
  and when last changed the live page); `POST .../publish` `{force?}`; `POST .../discard`; `GET /pending` (all pending
  copies on this site). The copy is a normal draft (same content, title, parent, excerpt, template, featured image and
  Polylang language; no slug, no translation link, no SEO or `_evive_*` meta), so tweak mode, previews and evive's draft
  writes work on it unchanged. Meta: live `_evive_pending_id`; copy `_evive_pending_of`, `_evive_pending_base` (sha256 of
  the live post_content + post_modified_gmt). Publish: 409 `evive_pending_conflict` when the live page changed since the
  copy was made (unless `force`), a revision of the live state (`restore_revision_id` in the answer), then only the copy's
  post_content on the live post (title, slug, SEO, featured image stay), cache purged, copy to the trash, meta removed.
  Discard: copy to the trash, meta removed, live untouched. A published page that is not a block page is refused (400).
  A route called with the copy's own ID answers for its live post (rights checked on the live post). The 409 also gives
  `modified_by` / `modified_at` (from `_edit_last`, else the latest revision's author).
- wp-admin: the copy shows "evive: pending changes" in the page list, the live page "evive: changes not live yet". The
  copy's own Publish (block editor, classic editor, REST) keeps it a draft without a slug, with a notice to publish from
  evive. The block editor of the live page warns "This page has evive changes that are not live yet".
- Tweak mode on a copy: a band "Pending changes of a live page: visitors still see the live version" with Publish
  changes / Discard changes (disabled while tweaks are unsaved or a save runs; a confirm names the live URL). They post
  `{post_id, site, action: "publish"|"discard"}` to the tweak endpoint with the same Bearer token (the evive app does
  the work, #541) and tell the evive window (`evive:pending-published` / `evive:pending-discarded`). The draft preview
  strip of a copy shows the same band.

## 1.4.3 — 2026-11-21

- Tweak mode: edit text in place (evive #533). Double-click a heading, paragraph, list item, button or image caption
  (or select it and press F2 / Enter, or "Edit text") to edit its text right in the preview. Enter ends a heading,
  button, list item or caption edit; in a paragraph Enter is a line break and Esc or a click outside ends it. A small
  bar offers Bold, Italic and Link; Ctrl+B / I / K work too. Paste and drop are always plain text. Only inline
  formatting is kept (strong, b, em, i, a[href,target,rel], mark/span[class,style], br, s, sub, sup).
  In the tweak frame each text block gets `data-evive-text`: the saved inner HTML of its text element (from
  post_content, before wptexturize and other filters), which is what the user edits. Save sends a text change as
  `{path, block, text: {before, after}}` (also together with `tweak` on the same block; contract of #533/#534) to the
  tweak endpoint; the evive app and MCP apply it (#534, #535) and refuse it when the page changed meanwhile. The Save
  button counts text edits with the tweaks; Reset undoes a block's text edit first, then its tweak on that size.
  A second click on a selected block selects its parent after 400 ms (so a double-click does not jump to it).
  Drafts only, like the other tweaks.
  Opening an edit and leaving it without typing is not a change, also when the saved text holds markup outside that
  list (code, a link's class or data-id): nothing is sent, so that markup stays.

## 1.4.2 — 2026-11-20

- Tweak mode: make a section taller or shorter (evive #532). `eviveTweak.{desktop|tablet|phone}` learns `top` and
  `bottom`: extra space at the top / bottom in steps of `--evive-tweak-step`, whole numbers 0..40 (0 = none). It is added
  to the block's padding (`padding-*: calc(own + n steps) !important`), own resolved like WordPress does: the block's
  padding attribute, else its style variation in the global styles (e.g. evive-base's Card), else the block type's
  global styles, else core's cover default (1em), else 0. Borders are never
  touched (a border set in the block, or the Card hairline, stays). A block with a minimum height (cover `minHeight`, `style.dimensions.minHeight`, the block type's global
  styles, or core's 430px for a cover without one) gets that much more, so a cover always grows. The block type's global
  styles are looked up by name (a missing entry no longer falls back to the root styles). Padding a theme sets only in
  its stylesheet (evive-base's `is-style-carousel` columns) is not seen: there the extra space replaces it. A block with an aspect ratio (a cover set to 16/9) is not a section in the overlay: its height follows its width, so it keeps -/+ and the corners. Media blocks (image, video, audio, embed, gallery) are never a section, also when wide or full width. In the overlay a section (cover, columns, wide/full-width group, or a
  top-level block with its own background) shows **Taller / Shorter** (space at the bottom; Shift = 5 steps; also the
  +/- keys) and drag handles on its top and bottom edge instead of -/+ and the corner handles; the frame gets
  `data-evive-space` (the block's own padding) so the live view uses the same rules. Toolbar text and the editor panel
  describe it ("6 steps more space at the bottom"). Saving needs the evive MCP (#534) and app (#535) that keep the new
  keys; until then evive drops them. The selection box now follows a change and a Reset at once (not on the next frame).

## 1.4.1 — 2026-11-20

- Draft preview links last 8 hours instead of 1 (filter `evive_preview_ttl`, seconds, default `8 * HOUR_IN_SECONDS`)
  and stay valid: `POST /evive/v1/preview-token/{type}/{id}` for a post that still has a valid token returns the same
  token (same `preview_url`) with `expires_at` moved to now + TTL; a new token only when none is valid, so earlier
  "View draft" links in the chat keep working. The "expired" page names the lifetime from the setting. Tweak tokens
  (#522) unchanged (evive #528).

## 1.4.0 — 2026-11-19

- Tweak mode in the draft preview (evive #522, design #520). `POST /evive/v1/preview-token/{type}/{id}` takes optional
  `tweak_token` (from the evive app, 32-128 chars `A-Za-z0-9_-`, valid 30 minutes, stored as a hash; needs edit rights on
  the page) and `tweak_endpoint` (https URL of the evive app) and then also returns `tweak_url`. Without them the route
  answers as before. The tweak URL shows a toolbar (Desktop 1280 / Tablet 820 / Phone 390, Parent, -/+, Hide on phone,
  Reset, Save) around the preview, in which every block of the post content has `data-evive-path` (indices of real
  blocks from the top of the post content, whitespace skipped, e.g. `2.0.1`) and `data-evive-block`. Click selects a
  block, a second click its parent, Esc deselects; drag or arrow keys move it (Shift = 5 steps), the corner handles or
  -/+ resize it. Save POSTs `{post_id, site, changes: [{path, block, tweak|null}]}` with `Authorization: Bearer
  <tweak token>` to the tweak endpoint; the overlay never writes to WordPress.
- New block attribute `eviveTweak` on all blocks: `{desktop|tablet|phone: {x, y, width, hidden}}`; x/y in steps of the
  spacing preset 20 (-40..40), width 10-100 % of the parent in steps of 5 (images keep their proportions), hidden.
  Phone <= 600px, tablet 601-1024px, desktop >= 1025px, each on its own. Rendered as scoped CSS on a class
  `evive-tw-<hash>`; the saved block HTML is unchanged, so the editor keeps the attribute without "unexpected content",
  and shows it in an "evive tweak" sidebar panel with "Remove tweak".

## 1.3.30

- a site administrator may create a backup again, also on Multisite; delete and server files stay network admin (evive #373)

## 1.3.29

- search-text needs edit rights on the page; backup, cache and server-file routes need an administrator, or a super admin on Multisite (evive #372, #373)

## 1.3.28

- /evive/v1/items total_pages correct past the last page; plain titles (no Private: prefix)

## 1.3.27

- GET /evive/v1/items, paged list of any post type incl. non-REST ones (evive #385)

Older versions: see the git history.
