# Changelog

Add your change under "## Unreleased" at the top; the release gate turns it into "## X.Y.Z — date".

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
