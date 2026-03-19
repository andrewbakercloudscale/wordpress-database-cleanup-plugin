'use strict';
const helpLib = require('/Users/cp363412/Desktop/github/shared-help-docs/help-lib.js');

helpLib.run({
    baseUrl:    process.env.WP_BASE_URL,
    cookies:    process.env.WP_COOKIES,
    restUser:   process.env.WP_REST_USER,
    restPass:   process.env.WP_REST_PASS,
    docsDir:    process.env.WP_DOCS_DIR,

    pluginName: 'CloudScale Cleanup',
    pluginDesc: 'WP-Optimize and Advanced Database Cleaner charge $39–$99/year for features that are straightforward to implement. CloudScale Cleanup gives you full database cleanup, media library orphan removal, image optimisation, and PNG-to-JPEG conversion — with a dry-run preview so you never delete anything by accident. Completely free, open source, no subscriptions, no premium tier.',
    pageTitle:  'CloudScale Cleanup: Online Help',
    pageSlug:   'cleanup-help',
    adminUrl:   `${process.env.WP_BASE_URL}/wp-admin/tools.php?page=cloudscale-cleanup`,

    sections: [
        { id: 'db-cleanup',    label: 'Database Cleanup',       file: 'panel-db-cleanup.png'    },
        { id: 'img-cleanup',   label: 'Media Library Cleanup',  file: 'panel-img-cleanup.png'   },
        { id: 'img-optimise',  label: 'Image Optimisation',     file: 'panel-img-optimise.png'  },
    ],

    docs: {
        'db-cleanup': `
<div style="background:#f0f9ff;border-left:4px solid #0e6b8f;padding:18px 22px;border-radius:0 8px 8px 0;margin-bottom:28px;">
<h2 style="margin:0 0 10px;font-size:1.3em;color:#0f172a;">Why CloudScale Cleanup?</h2>
<p style="margin:0 0 10px;">WordPress databases grow silently. Post revisions, expired transients, spam comments, orphaned metadata, and unused term relationships accumulate over months and years. On a busy site the database can balloon to 10x the size it needs to be, slowing down every query.</p>
<p style="margin:0 0 10px;">WP-Optimize locks most of its cleanup options behind a $39/year paywall. Advanced Database Cleaner charges $49/year. CloudScale Cleanup does everything they do — and adds media library orphan detection and image optimisation — at zero cost.</p>
<p style="margin:0 0 10px;">Every operation has a <strong>dry-run preview mode</strong>: you see exactly what will be deleted before anything is removed. Cleanup runs in chunks to avoid timeouts on shared hosting. Your data is never sent anywhere.</p>
<p style="margin:0;"><strong>Completely free.</strong> No premium tier, no upgrade prompts, source code on GitHub.</p>
</div>
<p>The <strong>Database Cleanup</strong> tab removes accumulated junk from your WordPress database that slows down queries and inflates backup sizes. On a WordPress site with 3–5 years of content and active editing, these items routinely account for 30–70% of total database size.</p>
<p><strong>Always run Dry Run first</strong> — it shows exactly what will be deleted and how many rows are affected, without making any changes. Processing is done in configurable chunks (default: 500 rows per batch) using a timed loop that checks elapsed time before each chunk to avoid PHP <code>max_execution_time</code> errors.</p>
<p><strong>Items cleaned and the tables affected:</strong></p>
<ul>
<li><strong>Post revisions</strong> — rows in <code>wp_posts</code> where <code>post_type = 'revision'</code>, plus their associated rows in <code>wp_postmeta</code>. WordPress saves a revision on every manual save and autosave. A single post edited 50 times generates 50 revision rows. Cleaned with a single <code>DELETE FROM wp_posts WHERE post_type = 'revision'</code> followed by an orphaned postmeta sweep.</li>
<li><strong>Auto-drafts</strong> — <code>wp_posts</code> rows where <code>post_status = 'auto-draft'</code>. Created by the Gutenberg editor each time you open a new post. Accumulate invisibly in the database.</li>
<li><strong>Trashed content</strong> — posts, pages, and custom post types in <code>post_status = 'trash'</code>. Comments in <code>comment_approved = 'trash'</code>. WordPress does not auto-purge trash on a schedule unless you configure it.</li>
<li><strong>Spam comments</strong> — <code>wp_comments</code> rows where <code>comment_approved = 'spam'</code>. High-traffic sites accumulate tens of thousands of these.</li>
<li><strong>Orphaned post meta</strong> — rows in <code>wp_postmeta</code> where the <code>post_id</code> no longer exists in <code>wp_posts</code>. Generated when posts are force-deleted without a corresponding meta cleanup. Identified with: <code>SELECT pm.* FROM wp_postmeta pm LEFT JOIN wp_posts p ON pm.post_id = p.ID WHERE p.ID IS NULL</code>.</li>
<li><strong>Expired transients</strong> — <code>wp_options</code> rows where <code>option_name</code> matches <code>_transient_%</code> or <code>_transient_timeout_%</code> and the timeout value is in the past. On sites using a persistent object cache (Redis, Memcached), transients are stored there instead — this cleanup is a no-op in that case.</li>
</ul>`,

        'img-cleanup': `
<p>The <strong>Media Library Cleanup</strong> tab identifies media attachments that exist in the WordPress database but are not referenced anywhere on the site, freeing disk space and reducing backup sizes.</p>
<p><strong>How attachment references are checked:</strong> The scanner searches for each attachment's URL and ID in:</p>
<ul>
<li><code>wp_posts.post_content</code> — direct <code>&lt;img&gt;</code> tags, Gutenberg block attributes, and shortcode references.</li>
<li><code>wp_postmeta</code> — featured image (<code>_thumbnail_id</code>), WooCommerce gallery images, and any meta value containing the attachment ID or URL.</li>
<li><code>wp_options</code> — widget configurations, theme customizer settings, and site icon references.</li>
</ul>
<p><strong>Workflow:</strong></p>
<ol>
<li><strong>Scan</strong> — builds a list of all attachment IDs from <code>wp_posts WHERE post_type = 'attachment'</code> and cross-references against the above sources. Processing is chunked to handle large libraries without timeout.</li>
<li><strong>Preview</strong> — shows thumbnails and filenames of all attachments flagged as unreferenced. Review carefully before deleting — some plugins store media references in custom tables not covered by the scan (e.g. sliders, page builders). If in doubt, download the attachment before deleting.</li>
<li><strong>Delete</strong> — calls <code>wp_delete_attachment($id, true)</code> for each selected item. This removes the database row, all generated image sizes from disk, and clears any related postmeta.</li>
</ol>
<p><strong>Important:</strong> Always take a full backup before running media cleanup. Files deleted via this tool are permanently removed from the server's filesystem — they cannot be recovered from the WordPress trash.</p>`,

        'img-optimise': `
<p>The <strong>Image Optimisation</strong> tab reduces the on-disk file size of images in your media library using PHP's GD or Imagick library (whichever is available on your server). No external service or API is used — all processing is local.</p>
<ul>
<li><strong>PNG to JPEG conversion</strong> — converts PNG files that have no alpha-channel transparency to JPEG format. PNGs with transparency are automatically detected and skipped. The original <code>.png</code> file is replaced with a <code>.jpg</code> at the configured quality level. Typical size reduction: 3–5× for photographic content; 20–40% for diagrams and screenshots. WordPress attachment metadata is updated so all references to the image continue to work.</li>
<li><strong>JPEG recompression</strong> — re-encodes existing JPEG files using PHP GD's <code>imagejpeg()</code> at the configured quality level. Images already stored at or below the target quality are detected via embedded EXIF data and skipped to avoid double-compression artefacts.</li>
<li><strong>Quality setting</strong> — JPEG quality on a 1–100 scale. Recommended: 82 (default) for blog photography, 75 for UI screenshots and diagrams. Below 70 introduces visible banding in gradients. Above 90 produces diminishing returns with significantly larger files.</li>
<li><strong>Chunked processing</strong> — the library is processed in batches of 10 images per AJAX request, with progress displayed in real time. You can close the browser and return — the tool tracks processed attachment IDs in a WordPress option and resumes from where it left off. To reset progress and reprocess everything, click <strong>Reset Progress</strong>.</li>
<li><strong>Server requirements:</strong> PHP 7.4+ with GD compiled with JPEG and PNG support, or Imagick extension. Check via <code>phpinfo()</code> or <code>php -m | grep -i imagick</code>. Minimum recommended memory: <code>memory_limit = 128M</code> for large images; 256M for images over 5000×5000 pixels.</li>
</ul>`,
    },
}).catch(err => { console.error('ERROR:', err.message); process.exit(1); });
