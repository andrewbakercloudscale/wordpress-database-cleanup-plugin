'use strict';
const helpLib = require('/Users/cp363412/Desktop/github/shared-help-docs/help-lib.js');

helpLib.run({
    baseUrl:    process.env.WP_BASE_URL,
    cookies:    process.env.WP_COOKIES,
    restUser:   process.env.WP_REST_USER,
    restPass:   process.env.WP_REST_PASS,
    docsDir:    process.env.WP_DOCS_DIR,

    pluginName: 'CloudScale Cleanup',
    pluginDesc: 'Database and media library cleanup with dry-run preview, image optimisation, PNG to JPEG conversion, and chunked processing safe on any server. Free, open source, no subscriptions.',
    pageTitle:  'Help & Documentation — Cleanup',
    pageSlug:   'cleanup-help',
    adminUrl:   `${process.env.WP_BASE_URL}/wp-admin/tools.php?page=cloudscale-cleanup`,

    sections: [
        { id: 'db-cleanup',    label: 'Database Cleanup',       file: 'panel-db-cleanup.png'    },
        { id: 'img-cleanup',   label: 'Media Library Cleanup',  file: 'panel-img-cleanup.png'   },
        { id: 'img-optimise',  label: 'Image Optimisation',     file: 'panel-img-optimise.png'  },
    ],

    docs: {
        'db-cleanup': `
<p>The <strong>Database Cleanup</strong> tab removes accumulated junk from your WordPress database that slows down queries and inflates backup sizes.</p>
<p>Items that can be cleaned:</p>
<ul>
<li><strong>Post revisions</strong> — WordPress saves a revision every time you update a post. These accumulate rapidly on busy sites.</li>
<li><strong>Auto-drafts</strong> — temporary draft posts created by the Gutenberg autosave that were never published.</li>
<li><strong>Trashed posts / pages / comments</strong> — items moved to the Trash but not yet permanently deleted.</li>
<li><strong>Spam and unapproved comments</strong> — comment queue items that were marked as spam.</li>
<li><strong>Orphaned post meta</strong> — post metadata rows whose parent post no longer exists.</li>
<li><strong>Transients</strong> — expired WordPress transient cache entries stored in the options table.</li>
</ul>
<p>Always use <strong>Dry Run</strong> first to preview what will be deleted before committing. Processing is done in chunks to avoid PHP timeout on large databases.</p>`,

        'img-cleanup': `
<p>The <strong>Media Library Cleanup</strong> tab finds and removes media files that are no longer referenced by any post, page, or widget — freeing up disk space and reducing backup sizes.</p>
<ul>
<li><strong>Scan</strong> — analyses your media library against post content, post meta, widget data, and theme options to identify truly unused files.</li>
<li><strong>Preview</strong> — lists all files that would be deleted, with thumbnails so you can verify before committing.</li>
<li><strong>Delete</strong> — permanently removes the selected files and their WordPress attachment records.</li>
</ul>
<p><strong>Caution:</strong> some plugins store media references in custom database tables that the scanner may not check. Always download a backup before running a media cleanup.</p>`,

        'img-optimise': `
<p>The <strong>Image Optimisation</strong> tab reduces the file size of images in your media library, improving page load speed and reducing storage usage.</p>
<ul>
<li><strong>PNG to JPEG conversion</strong> — converts PNG images that do not require transparency to JPEG format, which is typically 3–5× smaller at equivalent visual quality. Transparency is preserved for PNGs that need it.</li>
<li><strong>JPEG recompression</strong> — re-encodes existing JPEG files at a configurable quality level (default 82%). Images already at or below the target quality are skipped.</li>
<li><strong>Quality setting</strong> — balance between file size reduction and image quality. 80–85 is recommended for blog photography; 70–75 for screenshots and diagrams.</li>
<li><strong>Chunked processing</strong> — large libraries are processed in batches to prevent PHP timeouts. You can stop and resume at any time.</li>
</ul>`,
    },
}).catch(err => { console.error('ERROR:', err.message); process.exit(1); });
