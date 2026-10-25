=== CloudScale Cleanup ===
Contributors: andrewjbaker
Tags: cleanup, database, media, revisions, transients
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.5.138
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Database and media library cleanup with dry-run preview, chunked processing, image optimisation, and scheduled automation.

== Description ==

Most cleanup plugins delete things immediately with no preview and no control over what gets touched. **CloudScale Cleanup** shows you exactly what it found before anything is deleted, processes large datasets without timeouts, and gives you per-category toggle controls so you never accidentally clean something you need.

**Database Cleanup**

Choose any combination of:

* Post revisions older than a configurable threshold (default 30 days)
* Draft posts older than threshold (default 90 days)
* Trashed posts older than threshold (default 30 days)
* Auto drafts created when opening Add New Post and never saved (default 7 days)
* Expired transients that WordPress never cleaned up
* Orphaned post meta referencing post IDs that no longer exist
* Orphaned user meta left behind when users are deleted
* Spam comments older than threshold (default 30 days)
* Trashed comments older than threshold (default 30 days)

**Image Cleanup**

* Unused images in your media library not referenced in any post content, featured images, widget settings, theme mods, site logo, or site icon
* Orphaned filesystem files on disk in `wp-content/uploads` with no corresponding WordPress attachment record

**Image Optimisation**

* Resize and recompress JPEG and PNG images exceeding configurable maximum dimensions or quality thresholds
* Convert JPEG and PNG originals to WebP (transparency kept), with thumbnails regenerated and post content repointed to the new files
* Lossy or lossless: lossless mode re-saves PNGs at maximum compression (or as lossless WebP) with every pixel unchanged, and never re-encodes a JPEG at lower quality
* Destructive operation, requires explicit confirmation before running
* Back up your uploads folder before using this feature

**Dry Run Preview**

Always run a dry run first. The plugin scans your database and reports exactly what it found, counts per category, individual post IDs, titles, and dates, without touching anything. Toggle states are respected so the dry run accurately reflects what the actual cleanup will do.

**Chunked Processing**

Cleanup operations use a three-phase chunked engine: a start action builds the queue and stores it in a transient, a chunk action processes one batch (default 50 items) and updates the transient, and a finish action reports the final summary. The browser never waits more than a few seconds per request. Handles arbitrarily large datasets without PHP timeout issues.

**Toggle Controls**

Each category has an independent toggle. Green means included, grey means skipped. Toggle states are saved and respected at scan time. Categories you never want touched can be permanently disabled.

**Configurable Thresholds**

Every time-based category has an age cutoff to prevent deleting recent items. Defaults are conservative, adjust to match your workflow.

**Scheduled Cleanup**

WordPress Cron-based scheduler runs database cleanup automatically on selected days at a configured hour. For precise scheduling on low-traffic sites, disable WP Cron and use a real system cron hitting `wp-cron.php`.

**Site Health Panel**

Live server metrics panel showing disk usage, storage runway estimate, and CPU and memory usage, read from the server's load average and `/proc/meminfo` where the host exposes them.

**Optional: turn off the WordPress emoji script**

An opt-in setting (Cron tab, Site Options) removes WordPress's emoji detection script, its styles and its DNS prefetch hint, so emoji are drawn by the browser instead of being swapped for images from the WordPress.org emoji CDN. It is off by default and WordPress behaves as normal until you tick it.

== External Services ==

= Telegram Bot API (optional, error alerts only) =

This plugin has no Telegram settings screen of its own. When another CloudScale plugin on the same site has a Telegram bot token and chat ID configured, this plugin reuses them for its own error alerts. Nothing is sent while no bot token and chat ID are configured.

**Service:** Telegram Messenger Inc., Telegram Bot API (api.telegram.org)
**Endpoint:** the Bot API sendMessage method, at the path /bot followed by your bot token and /sendMessage on api.telegram.org
**When data is sent:** only when one of this plugin's scheduled cleanups (database or media) fails with an error.
**Data sent:** the bot token and chat ID, and an alert containing the error message, the plugin name and alert level, the site's domain, the optional environment label, the server's hostname and IP address, and, for the request that raised the alert, its HTTP method and request URL, the client IP address and the logged-in user's username if there is one. No database content or post content is transmitted.
**Purpose:** real-time error alerts to a Telegram chat of your choosing, so site owners can respond to cleanup failures quickly.

The shared alert class this plugin ships also contains the getUpdates ("Fetch Chat ID") and test-message calls that the other CloudScale plugins' settings screens use. This plugin never registers those actions, so it never makes either call.

Telegram Privacy Policy: https://telegram.org/privacy
Telegram Terms of Service: https://telegram.org/tos
Telegram Bot API documentation: https://core.telegram.org/bots/api

= Links to the plugin author's site (never requested) =

The admin screen has a Help button linking to https://help.cloudscale.consulting/plugin-help/database-cleanup/ and a link to https://cloudscale.consulting, and the optional front-end widget shows a link to https://cloudscale.consulting only when its "Show credit link" box is ticked. These are ordinary links a person can click. The plugin never requests either site and sends them no data.

== Installation ==

**Option 1, WordPress admin (recommended)**

1. Download `cloudscale-cleanup.zip`
2. In your WordPress admin, go to **Plugins > Add New Plugin > Upload Plugin**
3. Select the zip file and click **Install Now**
4. Click **Activate Plugin**
5. Go to **Tools > CloudScale Cleanup**

**Option 2, Manual via FTP/SFTP**

1. Unzip `cloudscale-cleanup.zip`
2. Upload the `cloudscale-cleanup` folder to `/wp-content/plugins/`
3. Activate via **Plugins > Installed Plugins**
4. Go to **Tools > CloudScale Cleanup**

== Frequently Asked Questions ==

= Will it delete things without asking? =

No. Every operation requires either a dry run confirmation or an explicit action click. The dry run shows you exactly what would be deleted before you commit to anything.

= Is it safe on large sites? =

Yes. All cleanup operations use chunked processing that queues items in a transient and processes them in small batches. There are no bulk DELETE queries that could time out or lock tables.

= What does "orphaned post meta" mean? =

When a post is deleted from WordPress, its associated meta rows in `wp_postmeta` are usually cleaned up too, but not always. Over time, rows can accumulate in `wp_postmeta` referencing post IDs that no longer exist. These rows are safe to delete and can add up to significant database bloat on active sites.

= What does "orphaned filesystem files" mean? =

Files that exist on disk inside `wp-content/uploads` but have no corresponding attachment record in the WordPress database. These are typically left behind when attachments are deleted through means other than the WordPress media library (direct FTP deletion, migration errors, plugin conflicts).

= Is ZipArchive required? =

Only if you use the image optimisation export feature. The cleanup and scanning features work without it.

= Can I undo a cleanup? =

No. Deleted database rows are gone. Take a database backup before running any cleanup, especially on a site you have not cleaned before.

== Screenshots ==

1. Database cleanup panel showing per-category toggles, configurable thresholds, dry run results with post titles and dates, and chunked progress bar.
2. Image cleanup panel showing unused media library items and orphaned filesystem files with individual checkboxes.
3. Image optimisation panel with configurable max dimensions and quality settings.
4. Site Health panel showing disk usage, storage runway, and server resource metrics.

== Changelog ==

= 2.5.138 =
* New: lossless mode in Image Optimisation. PNGs are re-saved at maximum compression with identical pixels, or as lossless WebP when WebP conversion is on; JPEG compression is left alone. Uses Imagick when present; with GD only, 16-bit and colour-profiled PNGs are skipped rather than changed.

= 2.5.135 =
* New: optional WebP conversion in Image Optimisation. JPEG and PNG originals become WebP at your quality setting, thumbnails are regenerated, and links in post content to the full image and every thumbnail size are updated. Skipped automatically when the server's image library cannot write WebP.
* Fixed: PNG to JPEG optimisation now leaves PNGs with transparency alone, as the settings help always said. It converted them, filling see-through areas with a solid colour.
* Fixed: after a PNG to JPEG conversion, post content that used a thumbnail size now points at the new thumbnail too, and a large upload's original file stays attached to its image.
* Fixed: the "Convert PNGs to JPEG" box no longer shows ticked on a site where the setting was never saved and is therefore off.

= 2.5.134 =
* Fixed: scheduled cleanups run at the chosen hour in the site's timezone. They previously ran offset by the site's UTC offset (02:00 ran at 04:00 on a UTC+2 site), and the Next Run badge hid it. Existing schedules are re-queued on the corrected clock automatically.
* Fixed: a schedule that includes today now runs today if the hour has not passed yet, instead of waiting a week.

= 2.5.132 =
* Fixed: scheduled database and image cleanups that went missing from the cron queue (for example after the 2.5 hook rename) are queued again automatically. The hourly temporary-file sweep is queued on upgraded sites too. Standby copies stay disarmed.
* Fixed: the unused-media scan no longer flags images used only in drafts, pending, scheduled or private posts, or media that plugins link through post meta (audio narration, ACF fields, WooCommerce galleries). The orphaned-file scan reads the same posts.
* Changed: scheduled database cleanups never delete drafts. Drafts are only removed by a manual run, and their age is measured from the last edit rather than creation.

= 2.5.130 =
* Changed: the WordPress emoji script is no longer removed by default. Turning it off is now an opt-in setting (Cron tab, Site Options).
* Fixed: unticking "Enable scheduled cleanup" or "Convert PNGs to JPEG" and saving now actually turns them off.
* Fixed: unused-media and orphaned-file scans find image references on sites whose uploads folder is not wp-content/uploads, including multisite subsites.
* Fixed: PNG to JPEG optimisation no longer overwrites an existing JPEG of the same name.
* Fixed: the "Test Metrics" button on the Site Health tab reports the real readings; several button and badge colours that had been corrupted are restored.
* Changed: file moves and folder removal use the WordPress filesystem API; deactivating the plugin now stops its scheduled events; uninstall removes every option the plugin created.
* Readme: External Services and Privacy sections now list exactly what a Telegram alert contains.

= 2.5.127 =
* Changed: alert settings (Telegram, environment label, mute) are stored under this plugin's own prefix, migrated automatically, and kept in sync with the other CloudScale plugins.

= 2.5.1 =
* NEW: Cron Management section in Settings tab
* NEW: 24-hour cron job timeline, canvas graph showing every scheduled job's fire times, coloured by hook
* NEW: Cron Congestion detection, highlights 5-minute windows where 3 or more jobs fire simultaneously
* NEW: All Scheduled Events table (all WP cron events, not just CSC) with overdue flagging and Refresh button
* NEW: Manual Triggers, fire DB Cleanup or Media Cleanup immediately without waiting for schedule
* NEW: Server Cron Setup card with copy-ready crontab command prefilled with site URL
* NEW: WP-Cron health banner with RAG status (OK / Warning / Congestion detected)

= 2.4.38 =
* PCP compliance: removed inline script blocks, added wp_unslash(), replaced date() with gmdate(), escaped all output variables
* Table overhead RAG thresholds updated: amber 12-28 MB, red > 28 MB
* readme.txt tags reduced to 5; CHANGELOG brought up to date
* Explain modal text now displays in normal case (fixed uppercase inheritance)

= 2.4.1 =
* Site Health tab header styled purple to match CloudScale plugin family theme

= 2.4.0 =
* Filename-based cache busting for admin JS and CSS, no more stale assets after updates

= 2.3.5 =
* FIX: MutationObserver now correctly removes stale single-span Max Resource rows on re-render

= 2.3.4 =
* FIX: MutationObserver guard prevents double-rendering of weeks remaining metric

= 2.3.3 =
* Disk storage panel styled brown to match section theme; consistent section theming across all panels

= 2.3.2 =
* Max Resource panel rewritten as three equal inline cards; removes old cached single-span layout

= 2.3.1 =
* FIX: Max Resource layout corrected to three equal cards; weeks remaining calculation fixed in admin.js

= 2.3.0 =
* FIX: Weeks remaining cap corrected; removed all card borders for cleaner layout

= 2.2.9 =
* FIX: Sysstat timezone now uses OS timezone rather than WordPress timezone setting

= 2.2.6 =
* FIX: sar time window now uses local server time instead of UTC

= 2.2.0 =
* Renamed Runway / Wks Left labels to Est. Time to Storage Full for clarity

= 2.1.1 =
* NEW: Media recycle bin, moved-to-trash images held for configurable days before permanent deletion
* Manifest hardening and terminology updates throughout

== Upgrade Notice ==

= 2.5.1 =
New: Cron Management section with 24-hour timeline graph, Cron Congestion detection, scheduled events table, manual triggers, and server cron setup guide.

== Privacy Policy ==

CloudScale Cleanup does not collect or transmit any data outside your server except when Telegram alerts are configured in another CloudScale plugin on the same site. In that case, when a scheduled cleanup fails, this plugin sends an alert to that Telegram chat containing the error message, the site's domain, the server's hostname and IP address, and the HTTP method, request URL, client IP address and logged-in username of the request that raised it (see External Services). No data is sent to Telegram if no bot token and chat ID are configured. No other external requests are made by this plugin.
