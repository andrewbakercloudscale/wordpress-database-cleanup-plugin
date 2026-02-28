# CloudScale Cleanup

**Version:** 2.0.1
**Author:** Andrew Baker
**Author URI:** https://andrewbaker.ninja
**License:** GPL-2.0+
**Requires at least:** WordPress 5.8
**Requires PHP:** 7.4

## Description

CloudScale Cleanup is a free, open source WordPress plugin that combines database cleanup, media library cleanup, image optimisation, and PNG to JPEG conversion in a single tool. Every operation supports dry run preview and chunked processing safe on any shared host. No accounts, no API keys, no subscriptions, no data leaves your server.

## Features

### 1 Database Cleanup
- Post revisions, drafts, trashed posts, auto drafts
- Expired transients, orphaned post meta, orphaned user meta
- Spam and trashed comments
- Per item toggles and configurable age thresholds
- Scheduled automatic cleanup via WordPress Cron

### 2 Image Cleanup
- Scans the media library for unused attachments not referenced in published content, widgets, or theme mods
- Protects site logo, site icon, and featured images
- Orphan file scanner with recycle bin (move, restore, permanently delete)
- Scheduled automatic cleanup via WordPress Cron

### 3 Image Optimisation
- Resizes oversized originals to configurable maximum dimensions
- Recompresses JPEGs at a configurable quality target
- Optional PNG to JPEG conversion for non transparent PNGs (integrated into the library)
- Regenerates all WordPress thumbnail sizes after processing
- Chunked processing (5 images per request) for any server

### 4 PNG to JPEG Converter
- Drag and drop or browse to upload PNG files
- Batch conversion: queue multiple files and convert all at once
- JPEG quality slider from 1 to 100
- Preset output sizes: Original, 4K, 2K/QHD, Full HD, HD, XGA, SVGA, VGA, 512x512, 256x256
- Custom width/height with optional proportional constraint
- Chunked uploads for large files (configurable chunk size)
- Download converted JPEG directly
- Rename and add converted JPEG to WordPress Media Library with one click
- PNG transparency composited onto white background
- Full debug console with copy/clear functionality
- Files stored in standard WordPress uploads folder

### 5 Settings and Status
- Dashboard widget with last run timestamps
- Front end sidebar widget
- WordPress Cron status display
- About page with plugin links

## Chunked Processing Architecture

Every destructive operation works in three AJAX steps:

1. **Start** — Build the full list of IDs to process, store in a transient, return the total count to JS.
2. **Chunk** — Process one small batch, update the transient with remaining IDs, return log lines and remaining count. JS fires repeatedly until remaining equals zero.
3. **Finish** — Clean up the transient, write the last run timestamp, return a summary line.

Each AJAX request completes in well under 30 seconds on any shared host. Chunk sizes: 50 DB items, 25 image deletions, 5 image optimisations.

PNG to JPEG uploads use a similar chunked approach: the client splits large PNG files into configurable chunks (default 1.5 MB) and uploads them sequentially. The server reassembles chunks, validates the file is a valid PNG, then converts to JPEG.

## Installation

1. Upload the `cloudscale-cleanup` folder to `/wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Navigate to **Tools > CloudScale Cleanup** in the admin menu.

## Security

- All AJAX endpoints are nonce protected.
- All operations require the `manage_options` capability.
- File paths validated against WordPress uploads base directory.
- Upload sessions are user scoped and expire after 6 hours.
- Stale chunks cleaned up automatically via hourly cron.

## Changelog

### 1.8.6
- Massively improved debug console logging: every AJAX call now logs request URL, payload, response status, response body, and full error details.
- Added pre-conversion checks that log AJAX URL and nonce status before each file and abort with clear error if missing.

### 1.8.5
- Added inline CSC fallback: nonce, AJAX URL, chunk settings, and version are now injected directly into the page HTML so conversion works even if wp_localize_script fails to fire.

### 1.8.4
- Repackaged zip to extract files directly into plugin folder to prevent nested directory issues.

### 1.8.3
- Fixed critical issue: AJAX URL and nonce showing as "NOT SET" causing all conversions to fail. Added detailed debug header showing AJAX URL, nonce status, chunk size, and server max. Added preflight check that blocks conversion with a clear error message if configuration is missing. Added console error if CSC localize object is missing.

### 1.8.2
- Fixed "Failed to start upload" error caused by AJAX action name conflict with standalone PNG to JPEG plugin. All merged AJAX actions now use the `csc_pj_` prefix instead of `cspj_` so both plugins can coexist if needed.
- Fixed upload progress badge: single file uploads now show "Uploading…" instead of confusing chunk counters like "Uploading 0 of 2".
- Changed PNG TO JPEG tab colour from purple to lime green so it is visually distinct from the purple Settings tab.

### 1.8.1
- Fixed PNG to JPEG tab colour: now uses a distinct teal/green gradient instead of duplicating the purple Settings tab colour.
- Added delete button (×) on each converted file in the Converted Files results list, with server side cleanup of the file on disk.
- Fixed upload progress badge: now shows percentage for multi chunk uploads instead of confusing chunk numbers (e.g. was showing "Uploading 2/2" which looked like "file 2 of 2"). Single chunk uploads simply show "Uploading…" with no numbers.

### 1.8.0
- Merged CloudScale PNG to JPEG Converter into CloudScale Cleanup as a new tab.
- Added PNG to JPEG tab with drag and drop upload, batch conversion, quality control, output size presets, custom dimensions, chunked uploads, download, rename and add to Media Library, and full debug console.
- Added PNG Conversions counter to Settings tab stats grid.
- Updated CSS and JS assets to v7 with 5 tab support and converter specific styles.
- All CSPJ AJAX handlers now use the shared `csc_nonce` for consistency.
- Added chunk cleanup cron job for stale upload sessions.
- Bumped version to 1.8.0.

### 1.7.5
- Previous release with database cleanup, image cleanup, image optimisation, orphan file scanner with recycle bin, scheduled cleanup, dashboard and front end widgets.

## License

This plugin is licensed under the GNU General Public License v2.0 or later.
See https://www.gnu.org/licenses/gpl-2.0.html for the full license text.
