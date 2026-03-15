# WordPress Database Cleanup Plugin

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue) ![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple) ![License](https://img.shields.io/badge/License-GPLv2-green) ![Version](https://img.shields.io/badge/Version-2.4.2-orange)

WordPress database and media library cleanup plugin. Removes accumulated junk (revisions, drafts, transients, orphaned metadata, spam), finds unused images and orphaned filesystem files, and optimises oversized images. Full dry run preview before anything is touched. Completely free.

No subscriptions. No external services. No calls home.

> Full write up with screenshots: [WordPress Space Cleanup: A Free WordPress Database and Media Library Cleanup Plugin](https://andrewbaker.ninja/2026/02/25/wordpress-space-cleanup-a-free-wordpress-database-and-media-library-cleanup-plugin/)

## Features

### Database Cleanup

- **Post Revisions** older than configurable threshold (default 30 days)
- **Draft Posts** older than threshold (default 90 days)
- **Trashed Posts** older than threshold (default 30 days)
- **Auto Drafts** created when opening Add New Post and never saved (default 7 days)
- **Expired Transients** that WordPress never cleaned up
- **Orphaned Post Meta** referencing post IDs that no longer exist
- **Orphaned User Meta** left behind when users are deleted
- **Spam Comments** older than threshold (default 30 days)
- **Trashed Comments** older than threshold (default 30 days)

### Image Cleanup

- **Unused Images** in your media library not found in any post content, featured images, widget settings, theme mods, site logo, or site icon
- **Orphaned Filesystem Files** on disk in `wp-content/uploads` with no corresponding WordPress attachment record

### Image Optimisation

- Resize and recompress JPEG and PNG images exceeding configurable maximum dimensions or quality thresholds
- Destructive operation with explicit confirmation required
- Backup recommended before running

## How It Works

### Dry Run Preview

Always run a dry run first. The plugin scans your database and reports exactly what it found, with counts per category, individual post IDs, titles, and dates, without touching anything. Toggle states are respected so the dry run accurately reflects what the actual cleanup will do.

### Chunked Processing

Cleanup operations use a three phase chunked engine: a start action builds the queue and stores it in a transient, a chunk action processes one batch (default 50 items) and updates the transient, and a finish action reports the final summary. The browser never waits more than a few seconds per request. Handles arbitrarily large datasets without PHP timeout issues.

### Toggle Controls

Each category has an independent toggle. Green means included, grey means skipped. Toggle states are saved with **Save Selection** and respected at scan time. Categories you never want touched can be permanently disabled.

### Configurable Thresholds

Every time based category has an age cutoff to prevent deleting recent items. Defaults are conservative. Adjust to match your workflow.

### Scheduled Cleanup

WordPress Cron based scheduler runs database cleanup automatically on selected days at a configured hour. For precise scheduling on low traffic sites, disable WP Cron and use a real system cron hitting `wp-cron.php`.

## Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher

## Installation

1. Download the latest release zip from the [Releases](../../releases) page
2. In WordPress admin go to **Plugins > Add New > Upload Plugin**
3. Upload the zip file, click **Install Now**, then **Activate Plugin**
4. Go to **Tools > CloudScale Cleanup**

### Upgrading

Deactivate > Delete > Upload zip > Activate. If you have an opcode cache running (OPcache, Redis, WP Rocket, W3 Total Cache), deactivate and reactivate after installation to ensure new files are loaded cleanly.

## Architecture

Self contained: single PHP file plus two asset files (CSS and JS). No external dependencies, no calls home. Uses WordPress `$wpdb` for all database operations, respects WordPress nonces for AJAX security, hooks into the standard admin menu and enqueue system.

## License

GPLv2 or later. See [LICENSE](LICENSE) for the full text.

## Author

[Andrew Baker](https://andrewbaker.ninja/) - CIO at Capitec Bank, South Africa.
