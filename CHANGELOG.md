# Changelog

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
