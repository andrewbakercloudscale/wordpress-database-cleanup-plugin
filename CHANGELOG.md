# Changelog

## 2.5.68 — 2026-05-27
- FIX(migration): The v2.5.65 prefix migration ran `delete_option($old)` unconditionally even when the new key already existed (because the new cron had fired between v2.5.64 deploy and v2.5.65 migration), destroying months of weekly snapshot and hourly-metric history on early upgraders
- FIX(migration): Migration now MERGES time-series arrays (`cscc_health_weekly_snapshots`, `cscc_health_hourly_metrics`, `cscc_cron_run_log`) by `ts_unix` and only deletes the old key after a successful merge or copy
- FIX(build): `build.sh` version-bump sed was using `s/\./\./g` (no-op) to escape dots, so `s/2.5.65/2.5.66/g` matched anywhere — mangled inline CSS `rgba(255,255,255,0.15)` values into `rgba(2.5.3955,2.5.1.15)` on every build (same bug as v2.5.28); replaced with proper dot-escape and word-boundary anchors
- FIX(widget): 11 mangled `rgba()` values in `cloudscale-cleanup.php` reconstructed (Debug Console Copy/Clear/▼ buttons, Copy-log buttons on schedule cards, explain-button default colour, three gradient box-shadows)
- FIX(widget): Dashboard widget disk panel now shows current usage immediately — was gated on `count($weekly) >= 2` which suppressed it for the first week after the weekly cron was added in v2.5.40

## 2.5.66 — 2026-05-27
- BUILD: Exclude nested `repo/` (SVN staging directory) from distribution zip — was duplicating every plugin file inside the WP.org submission package; zip is now 9 files instead of 17

## 2.5.65 — 2026-05-27
- FIX(widget): Restore dashboard widget data on sites that upgraded through the v2.5.64 `csc_` → `cscc_` prefix rename — added `cscc_migrate_legacy_csc_prefix()` one-time migration that copies historical weekly snapshots, last-run timestamps, schedule settings, image-optimise settings, counters, and queues from the old `csc_*` option keys to the new `cscc_*` keys
- FIX(crons): Reschedule orphaned WP-Cron events (`csc_health_hourly_collect`, `csc_health_weekly_snapshot`, `csc_scheduled_db_cleanup`, `csc_scheduled_img_cleanup`) under their new `cscc_*` hook names, preserving recurrence and next-run time

## 2.5.64 — 2026-05-25
- STANDARDS: Renamed all PHP function, option, transient, AJAX action, and constant prefixes from `csc_`/`CSC_` (3 chars, rejected by WP.org) to `cscc_`/`CSCC_` — WordPress.org requires ≥ 4-character unique prefix
- STANDARDS: Removed versioned asset copies (`admin-X-X-X.js/css`) from plugin directory — WP.org prohibits writing files to the plugin folder; cache-busting now uses the `$version` parameter in `wp_enqueue_*`
- STANDARDS: Removed `register_deactivation_hook` that deleted files inside `plugin_dir_path()` — prohibited as a plugin-directory write
- STANDARDS: Removed `cscc_cleanup_stale_assets()` and `cscc_get_versioned_asset()` helper functions (no longer needed)
- STANDARDS: Excluded dev/build artifacts from distribution zip — `terraclaim/`, `docs/`, `generate-help-docs.sh`, `build-review.sh`, `setup-playwright-test-account.sh`, `delete-playwright-test-account.sh`, `archive/`, `CloudScaleCleanup.jpg`; zip is now 17 files only
- FIX(img-optimise): Remove premature `wp_delete_file($actual_path)` before rename in optimise chunk handler — caused original images to be silently deleted when WP image editor returned a different temp path; added `file_exists($tmp_file)` guard before original is ever touched
- STANDARDS: Added `phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen` to binary chunk assembly `fopen()` calls
- STANDARDS: Added safety comment + phpcs suppress to `maybe_unserialize()` on WP core postmeta
- DOCS: Removed "Free, no subscriptions." from readme.txt short description

## 2.5.40 — 2026-05-20
- SECURITY: Removed all `exec()` calls (sysstat/sar disk-usage and CPU/memory metrics) — WordPress.org hard-rejects any `exec()` usage; metrics now use PHP-only fallbacks (`sys_getloadavg()`, `/proc/meminfo`, `RecursiveDirectoryIterator`)
- SECURITY: Added `Throwable`-catching wrapper to all five WP-Cron callbacks (`csc_scheduled_db_cleanup`, `csc_scheduled_img_cleanup`, `cspj_cleanup_chunks`, `csc_health_hourly_collect`, `csc_health_weekly_snapshot`) to prevent PHP-FPM crash loops on uncaught exceptions
- FIX: Added `phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged` on all five `set_time_limit()` calls to clear PCP errors
- SECURITY (path traversal): Added `realpath()` path traversal guard, session ownership check, and index upper-bound check to `csc_ajax_cspj_chunk_upload()` — previously missing from active file while present in repo copy

## 2.5.2 — 2026-03-24
- FIX: Bump version to 2.5.2 to force browser cache-bust on admin.js and admin.css after modal redesign

## 2.5.1 — 2026-03-24
- UI: Replaced all browser-native `alert()` and `confirm()` dialogs with styled in-page modals — no more "andrewbaker.ninja says…" popups
- UI: Unified modal design across all confirmation dialogs and info boxes — white card, icon + bold title, red warning line, plain-English explanation, and Cancel / action buttons
- UI: All confirmation actions now show context-aware explanations: what will happen, whether it's reversible, and what cannot be undone
- UI: Recycle bin browser modals updated to clean white header with close button and subtitle count
- UI: "Explain…" modals updated to match new design (no dark header bar)
- UI: Save to Media Library popup updated to new modal style

## 2.5.0 — 2026-03-24
- NEW: Cron Management section in Settings tab, replacing the basic "WordPress Cron Status" card
- NEW: 24-hour canvas timeline — each scheduled cron job plotted as dots over a 24h window, coloured by hook, with dashed connecting lines for recurring jobs
- NEW: Cron Congestion detection — 5-minute bucket analysis flags windows where 3+ jobs fire simultaneously; shown as red bands on timeline and a warning alert
- NEW: All Scheduled Events table — AJAX-loaded, covers all WP cron hooks (not just CSC), overdue events highlighted amber, Refresh button
- NEW: Manual Triggers — "Run DB Cleanup Now" / "Run Media Cleanup Now" buttons fire cron hooks immediately via AJAX
- NEW: Server Cron Setup box — copy-ready crontab command pre-filled with site URL, with instructions to set DISABLE_WP_CRON
- NEW: WP-Cron health banner with status indicator (OK / Warning / Congestion) rendered after AJAX load

## 2.4.35 — 2026-03-24
- FIX: Table overhead RAG thresholds updated — amber 12–28 MB, red > 28 MB
- FIX: Explain modal now resets text-transform so content displays in normal case

## 2.4.34 — 2026-03-24
- FIX: Table overhead warning threshold raised to 12 MB

## 2.4.33 — 2026-03-24
- FIX: Explain modal text no longer uppercase; added text-transform:none reset to modal root

## 2.4.32 — 2026-03-24
- FIX: Explain button text now displays in normal case (was inheriting text-transform:uppercase from card header)
- PCP: Moved inline `<script>` blocks (cscToggle, cscOrphanToggle) to admin.js
- PCP: Added wp_unslash() to all sanitize_text_field( $_POST ) calls
- PCP: Replaced date() with gmdate() throughout
- PCP: Added esc_attr() to unescaped inline style/attribute echoes
- PCP: Added phpcs:ignore suppressions for rmdir(), error_log(), direct DB queries, SHOW TABLE STATUS, OPTIMIZE TABLE
- PCP: readme.txt tags reduced from 7 to 5 (max allowed)
- PCP: array_map sanitize now uses wp_unslash on the array before mapping

## 2.4.2
- FIX: Inline CSS fallback for Site Health purple tab header when external stylesheet is blocked
- Build-time cache busting: JS and CSS filenames include version hash in the distributable zip
- Replaced `@unlink()`, `@copy()`, `@rmdir()` with `wp_delete_file()`, `copy()`, `rmdir()` per WordPress coding standards
- License updated to GPL-2.0-or-later with License URI
- Requires at least bumped to 6.0; Tested up to 6.9

## 2.4.1
- Site Health tab header styled purple to match CloudScale plugin family theme

## 2.4.0
- Filename-based cache busting for admin JS and CSS — no more stale assets after updates

## 2.3.5
- FIX: MutationObserver now correctly removes stale single-span Max Resource rows on re-render

## 2.3.4
- FIX: MutationObserver guard prevents double-rendering of weeks remaining metric

## 2.3.3
- Disk storage panel styled brown to match section theme; consistent section theming across all panels

## 2.3.2
- Max Resource panel rewritten as three equal inline cards; removes old cached single-span layout

## 2.3.1
- FIX: Max Resource layout corrected to three equal cards; weeks remaining calculation fixed

## 2.3.0
- FIX: Weeks remaining cap corrected; removed all card borders for cleaner layout

## 2.2.9
- FIX: Sysstat timezone now uses OS timezone rather than WordPress timezone setting

## 2.2.6
- FIX: sar time window now uses local server time instead of UTC

## 2.2.0
- Renamed Runway / Wks Left labels to Est. Time to Storage Full for clarity

## 2.1.1
- NEW: Media recycle bin — moved-to-trash images held for configurable days before permanent deletion
- Manifest hardening and terminology updates throughout
