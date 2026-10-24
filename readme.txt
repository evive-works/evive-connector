=== evive Connector ===
Contributors: stackpros
Tags: evive, elementor, ai, content
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 1.0.7
Requires PHP: 8.0
License: Proprietary

Connects your WordPress site to evive AI for full Elementor and custom post type support.

== Description ==

The evive Connector plugin bridges the gap between evive's AI assistant and your WordPress site.
It exposes higher-level REST API endpoints under /evive/v1/ that allow evive to:

* Read and edit Elementor page content widget by widget
* Clone custom post type items (reviews, portfolio, team members, etc.)
* Access all custom fields via the REST API
* Inject SEO text and new sections into Elementor pages

Without this plugin, evive can only access standard WordPress content (posts and pages).
With this plugin, evive gains full access to Elementor-built pages and custom post types.

All endpoints require WordPress Application Password authentication — the same credentials
you already configured in evive settings.

== Installation ==

1. Upload the plugin zip to Plugins > Add New > Upload Plugin
2. Activate the plugin
3. evive will automatically detect the plugin on your next connection test

== REST API Endpoints ==

* GET  /wp-json/evive/v1/content-map               - Full site content map
* GET  /wp-json/evive/v1/item/{post_type}/{id}      - Get item with Elementor data
* POST /wp-json/evive/v1/clone/{post_type}/{id}     - Clone item with new content
* POST /wp-json/evive/v1/elementor/inject-text      - Add text to Elementor page
* POST /wp-json/evive/v1/elementor/update-widget    - Update widget text content

== Changelog ==

= 1.3.28 =
* Fix: GET /evive/v1/items reported total_pages 0 for a page past the last one (WordPress only counts matches when the page has posts); it now counts again on page 1.
* Fix: titles of private items no longer get WordPress's "Private: " prefix (the status field already says so).

= 1.3.27 =
* Feature (evive #385): GET /evive/v1/items lists the items of any post type a user may edit, including post types without REST access (e.g. a theme's portfolio). Paged (page, per_page up to 100), optional search and status (publish, draft, any), newest first, read-only. Returns items (id, title, slug, status, date), page, per_page, total_pages and next_page.

= 1.3.26 =
* Fix (evive #369): draft preview links on a WordPress Multisite subsite opened on the main site. The preview URL is now built from home_url() of the current site instead of get_site_url('/'), whose first argument is a blog ID. Single sites get the same URL as before.
* Feature (evive #370): the Site Profile reports the active theme (name, version, parent, block theme yes/no, theme.json, page editor elementor/block_editor/classic, the theme's own block patterns and template parts) and the Multisite context (blog ID, home URL, locale), so evive can tell a block theme site from an Elementor site.
* Updates now come from GitHub releases (github.com/evive-works/evive-connector) instead of a manifest on app.evive.works. The release zip no longer contains .bak backup files (evive #375).

= 1.0.27 =
* Approach pivot: dropped byte-overwrite media-replace; new model re-points Elementor references
* New: GET /evive/v1/front-page-id — returns the WP front page ID and title
* New: GET /evive/v1/page-images/{page_id} — lists all background slideshow images with position, attachment_id, thumbnail_url, element_id, gallery_index
* New: POST /evive/v1/elementor/repoint-slideshow-image — swaps one slideshow gallery entry by element_id + gallery_index; old attachment is untouched
* Removed: class-media.php byte-overwrite replace endpoint (media_replace_image MCP tool also removed)
* New file: includes/class-page-images.php

= 1.0.26 =
* New: optional alt_text and title params on POST /evive/v1/media/{id}/replace
* Updates _wp_attachment_image_alt meta and attachment post_title when provided
* Filename, URL, and guid are intentionally unchanged — only metadata changes
* Success response now includes alt_text_updated, title_updated, and the new values
* Backward compatible: omitting the params leaves existing metadata untouched

= 1.0.25 =
* Fix: PHP syntax error in class-media.php — removed doubled double-quotes on message line (adjacent string tokens with no concatenation operator — fatal parse error)
* Fix: switched all string literals to single quotes throughout class-media.php to eliminate double-quoted escape warnings (\d, \P, \L)
* Fix: LiteSpeed class check now uses LiteSpeed\\Purge (properly double-backslashed single-quoted string)

= 1.0.24 =
* Fix: removed hard-blocking dimension/format mismatch — replacement always proceeds
* New: dry_run=true query/body param returns comparison without making changes
* New: success response includes dimensions_old, dimensions_new, aspect_ratio_changed, format_old, format_new, format_changed
* Updated: buildSystemPrompt slider workflow uses dry_run check, warns on aspect ratio change, never refuses confirmed replacements

= 1.0.23 =
* New: POST /evive/v1/media/{id}/replace — replace attachment image bytes in place
* Preserves old image as new media library entry before overwriting
* Dimension mismatch and format mismatch return friendly non-destructive error responses
* Regenerates ALL registered image sizes for the target attachment after replace
* Purges WP object cache, LiteSpeed, WP Rocket, W3TC, SR transients, OPcache
* New MCP tool: media_replace_image(attachment_id, url) — Slider Revolution workflow via media replace
* Updated buildSystemPrompt slider workflow to use media_replace_image

= 1.0.22 =
* Pivot: abandoned direct-SR-DB-write approach for slide bg images (fragile, never reliable)
* Removed: update_slide_image endpoint, update_slide_text endpoint, debug-slide endpoint
* Removed: update_revslider_objects(), json_replace_bg_image(), decode_layers(), purge_caches()
* Removed: settings-column write, layers-column write, image-size regeneration on write
* Kept: read endpoints (rev-slides, rev-slide/{id}), opcache-reset — all working correctly
* New approach planned: POST /evive/v1/media/{id}/replace (replace attachment file + regenerate sizes)

= 1.0.21 =
* Investigation: SR REST constructs layers.1 (slidebg) dynamically — raw layers column only has zone structure
* Investigation: params.bg is correctly updated but SR may also read from wp_revslider_objects table
* Fix: update_slide_image() now reads ALL columns (SELECT *) to handle settings column if present
* Fix: also updates settings column if it contains bg image data
* Fix: new update_revslider_objects() method updates image references in wp_revslider_objects table
* Fix: json_replace_bg_image() recursively patches any JSON blob containing old URL/lib_id
* Fix: regenerates dreama-featured-image-full (and all sizes) for new attachment if not yet generated
* Fix: purge_caches() now uses REGEXP delete for all transient prefix variants + targeted wp_cache_delete per SR group
* Diagnostic: debug_slide_handler returns ALL columns, full decoded params, objects table schema+data, transients list

= 1.0.20 =
* Fix: update_slide_image() now writes params.bg.image as a plain URL string, params.bg.imageId as int, params.bg.imageLib = 'medialibrary' — matching actual SR6 DB structure
* Fix: previous code incorrectly treated params.bg.image as an array (setting .src/.lib_id), causing "Cannot access offset of type string on string" 500 error

= 1.0.17 =
* Fix: extract_bg_image() now reads background image from layer[bg][image][src] and layer[bg][image][lib_id]
* Fix: update_slide_image() writes to the same path, matching SR6 raw DB layer format
* New: POST /evive/v1/opcache-reset endpoint clears OPcache after plugin updates on shared hosting

= 1.0.16 =
* Fix: get_sr_slides_for_slider() uses wp_remote_get() instead of rest_do_request() to avoid SR hook hijacking
* Fix: is_slidebg_layer() helper matches both raw DB (type=slidebg) and SR REST (subtype=slidebg) formats
* Fix: update_slide_image() write-success guard distinguishes DB error vs no-rows-changed
* Routes renamed rev-slide/rev-slides to avoid SR rest_pre_dispatch interception

= 1.0.15 =
* Fix: slide IDs now retrieved from SR REST API (canonical) instead of slider_id column (out of sync)
* Fix: decode_layers() tries plain JSON, stripslashes+JSON, base64+JSON fallbacks

= 1.0.14 =
* New: Slider Revolution editing module (class-revslider.php)
* New: GET/POST endpoints for listing sliders, reading slides, replacing background images, updating text layers
* New: SR7 guard returns informative message if SR >= 7.0 detected
* New: global slide detection and exclusion

= 1.0.13 =
* Fix: backup timestamps now use wp_date() (WordPress site timezone) instead of date() (UTC)

= 1.0.12 =
* Fix: create_backup_direct() now calls boot_backup(true, true) with no extra_options to trigger a full backup

= 1.0.10 =
* New: POST /evive/v1/backup/fix-litespeed patches .htaccess with noabort rule for reliable LiteSpeed backups
* New: POST /evive/v1/backup/fix-wp-config adds ALTERNATE_WP_CRON define as secondary fallback

= 1.0.9 =
* New: DELETE /evive/v1/backup/{timestamp} removes a backup via UpdraftPlus
* Fix: disk space fields removed (unreliable on shared hosting)
* Fix: backup create now kicks WP cron immediately via non-blocking HTTP

= 1.0.8 =
* New: Backup and Restore module (class-backup.php)
* New: GET /evive/v1/backup/status, /backup/list, POST /backup/create


= 1.0.7 =
* Fix: osf_portfolio_gallery now stored as id => url associative array (thumbnails render in WP admin)
* Previous: gallery IDs were stored as indexed array, showing as "File: ID" text links instead of thumbnails

= 1.0.6 =
* Fix: Review text (post_content) now copied when cloning portfolio items
* Fix: get_item endpoint returns post_content so Claude can read existing review text
* Fix: osf_portfolio_start_date automatically set from body date in MM/DD/YYYY format (no longer copied from source)
* Fix: Dutch month names displayed on portfolio single pages (JS patch for OSF template)
* New: translation_of parameter links cloned item to existing post as Polylang translation
* New: language_slug variable now available for translation_of linking

= 1.0.5 =
* Fix: osf_portfolio_gallery meta is no longer copied from source when cloning — clones start with empty gallery
* Fix: plugin_version in /evive/v1/content-map now always reflects the live EVIVE_VERSION constant (bypasses transient cache)
* Fix: elementor_replacements documented with required widget_id and content fields


= 1.0.0 =
* Initial release
