/* CloudScale Cleanup — Admin JS
 * Chunked processing engine: start → loop chunks → finish
 * Each operation sends small AJAX requests until done.
 */
(function ($) {
    'use strict';

    $(document).ready(function () {

    console.log('[CSC] admin-v7.js loaded — build 2.1.0 — ' + new Date().toISOString());
    // ── Tab switching ──────────────────────────────────────────────────────────

    $('.csc-tab').on('click', function () {
        var target = $(this).data('tab');
        $('.csc-tab').removeClass('active');
        $('.csc-tab-content').removeClass('active');
        $(this).addClass('active');
        $('#tab-' + target).addClass('active');
    });

    // Auto switch to tab from URL parameter (e.g. ?tab=png-to-jpeg)
    (function () {
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab');
        if (tab) {
            var $btn = $('.csc-tab[data-tab="' + tab + '"]');
            if ($btn.length) { $btn.trigger('click'); }
        }
    })();

    // ── Terminal helpers ───────────────────────────────────────────────────────

    function clearTerminal(id) {
        $('#' + id).empty();
    }

    // Parse "[OPTIMISE] ID 1041 — name.ext (87 KB) — flags"
    // or    "[UNUSED]   ID 1041 — name.ext (87 KB)"
    // into column cells for a cleaner table layout.
    function parseTableLine(text) {
        var m = text.match(/^\s*\[(OPTIMISE|UNUSED)\]\s+ID\s+(\d+)\s+—\s+(.+?)\s+\(([^)]+)\)(?:\s+—\s+(.+))?$/);
        if (!m) { return null; }
        return { tag: m[1], id: m[2], name: m[3], size: m[4], flags: m[5] || '' };
    }

    function appendLine(id, line) {
        var $t  = $('#' + id);
        var cls = 'csc-log-item';
        switch (line.type) {
            case 'section':  cls = 'csc-log-section';  break;
            case 'deleted':  cls = 'csc-log-deleted';  break;
            case 'count':    cls = 'csc-log-count';    break;
            case 'success':  cls = 'csc-log-success';  break;
            case 'error':    cls = 'csc-log-error';    break;
            case 'info':     cls = 'csc-log-info';     break;
        }

        // Attempt columnar render for scan item lines
        if (line.type === 'item') {
            var parsed = parseTableLine(line.text);
            if (parsed) {
                // Ensure a table container exists
                if (!$t.find('.csc-log-table').length) {
                    $t.append('<div class="csc-log-table"></div>');
                }
                var tagColor = parsed.tag === 'OPTIMISE' ? '#ff7b72' : '#79c0ff';
                var html = '<div class="csc-log-row">'
                    + '<span class="csc-log-cell csc-log-cell-id" style="color:#6b7690">ID ' + esc(parsed.id) + '</span>'
                    + '<span class="csc-log-cell csc-log-cell-name" title="' + esc(parsed.name) + '">' + esc(parsed.name) + '</span>'
                    + '<span class="csc-log-cell csc-log-cell-size">' + esc(parsed.size) + '</span>'
                    + (parsed.flags ? '<span class="csc-log-cell csc-log-cell-flags" style="color:' + tagColor + '">' + esc(parsed.flags) + '</span>' : '')
                    + '</div>';
                $t.find('.csc-log-table').append(html);
                $t[0].scrollTop = $t[0].scrollHeight;
                return;
            }
        }

        // For non-table lines, close any open table first then write plain span
        $t.append('<span class="' + cls + '">' + esc(line.text) + '\n</span>');
        $t[0].scrollTop = $t[0].scrollHeight;
    }

    function appendLines(termId, lines) {
        if (!lines || !lines.length) return;
        $.each(lines, function (i, line) { appendLine(termId, line); });
    }

    function renderLines(termId, lines) {
        clearTerminal(termId);
        appendLines(termId, lines);
    }

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Progress helpers ───────────────────────────────────────────────────────

    function showProgress(outerSelector, fillId, labelId, labelText) {
        $(outerSelector).show();
        $('#' + fillId).css('width', '0%');
        $('#' + labelId).text(labelText || 'Working…');
    }

    function updateProgress(fillId, labelId, done, total, extraLabel) {
        var pct = total > 0 ? Math.round((done / total) * 100) : 0;
        $('#' + fillId).css('width', pct + '%');
        var label = pct + '% — ' + done + ' / ' + total + ' processed';
        if (extraLabel) { label += ' · ' + extraLabel; }
        $('#' + labelId).text(label);
    }

    function finishProgress(outerSelector, fillId, labelId, finalLabel) {
        $('#' + fillId).css('width', '100%');
        $('#' + labelId).text(finalLabel || 'Complete.');
        setTimeout(function () { $(outerSelector).fadeOut(600); }, 1800);
    }

    // ── Settings collection ────────────────────────────────────────────────────

    function collectSettings() {
        var data = {};
        $('.csc-setting, .csc-small-num').each(function () {
            data[$(this).attr('name')] = $(this).val();
        });
        // Hidden inputs backing the pure-div toggles
        $('input[type="hidden"][data-csc-toggle]').each(function () {
            data[$(this).attr('name')] = $(this).val();
        });
        $('input[type=checkbox][name^=csc_]').not('[name$="[]"]').each(function () {
            data[$(this).attr('name')] = $(this).is(':checked') ? '1' : '0';
        });
        $('input[type=checkbox][name$="[]"]:checked').each(function () {
            var name = $(this).attr('name');
            if (!data[name]) { data[name] = []; }
            data[name].push($(this).val());
        });
        return data;
    }

    // ── Save settings ──────────────────────────────────────────────────────────

    $('.csc-save-btn').on('click', function () {
        var $btn = $(this);
        var origLabel = $btn.text();
        $btn.prop('disabled', true).text('Saving…');
        var payload = collectSettings();
        payload.action = 'csc_save_settings';
        payload.nonce  = CSC.nonce;
        $.post(CSC.ajax_url, payload, function (resp) {
            $btn.prop('disabled', false).text(origLabel);
            notify(resp.success ? 'Settings saved.' : 'Error: ' + (resp.data || 'Unknown'), !resp.success);
        }).fail(function () {
            $btn.prop('disabled', false).text(origLabel);
            notify('Network error.', true);
        });
    });

    function notify(msg, isError) {
        var $n = $('#csc-save-notice');
        $n.text(msg).css('background', isError ? '#e74c3c' : '#27ae60');
        $n.fadeIn(200).delay(2200).fadeOut(400);
    }

    function cscShowModal(title, message) {
        var id = 'csc-warning-modal';
        $('#' + id).remove();
        var html = '<div id="' + id + '" style="position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;padding:16px">'
            + '<div style="background:#fff;border-radius:10px;max-width:480px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.4)">'
            +   '<div style="background:#1a2a3a;border-radius:10px 10px 0 0;padding:16px 20px;display:flex;justify-content:space-between;align-items:center">'
            +     '<strong style="color:#fff;font-size:15px">⚠️ ' + esc(title) + '</strong>'
            +     '<button type="button" onclick="document.getElementById(\'' + id + '\').remove()" style="background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.4);border-radius:5px;color:#fff;font-size:16px;font-weight:700;padding:2px 10px;cursor:pointer;line-height:1">&#x2715;</button>'
            +   '</div>'
            +   '<div style="padding:24px;font-size:14px;line-height:1.6;color:#1d2327">' + esc(message) + '</div>'
            +   '<div style="padding:12px 24px 20px;text-align:right">'
            +     '<button type="button" onclick="document.getElementById(\'' + id + '\').remove()" style="background:#1a2a3a;border:none;border-radius:6px;color:#fff;font-size:13px;font-weight:600;padding:8px 24px;cursor:pointer">Got it</button>'
            +   '</div>'
            + '</div>'
            + '</div>';
        $('body').append(html);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CHUNKED ENGINE
    // ─────────────────────────────────────────────────────────────────────────
    // runChunked({ startAction, chunkAction, finishAction,
    //              termId, progressOuter, progressFill, progressLabel,
    //              confirmMsg, $btn, restoreLabel })
    // ═════════════════════════════════════════════════════════════════════════

    function runChunked(opts) {
        if (opts.confirmMsg && !confirm(opts.confirmMsg)) { return; }

        var total = 0;
        var done  = 0;
        opts.$btn.prop('disabled', true).html('⏳ Starting…');
        clearTerminal(opts.termId);
        appendLine(opts.termId, { type: 'section', text: '=== ' + opts.startLabel + ' ===' });

        showProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Building queue…');

        // Step 1: start
        var startData = { action: opts.startAction, nonce: CSC.nonce };
        if (opts.toggleData) {
            $.extend(startData, opts.toggleData);
        }
        $.post(CSC.ajax_url, startData, function (resp) {
            if (!resp.success) {
                appendLine(opts.termId, { type: 'error', text: 'Error: ' + (resp.data || 'Unknown error') });
                opts.$btn.prop('disabled', false).html(opts.restoreLabel);
                finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Failed.');
                return;
            }

            appendLines(opts.termId, resp.data.lines);
            total = resp.data.total;
            done  = total - resp.data.remaining;
            updateProgress(opts.progressFill, opts.progressLabel, done, total);

            if (total === 0) {
                appendLine(opts.termId, { type: 'info', text: '  Nothing to process.' });
                $.post(CSC.ajax_url, { action: opts.finishAction, nonce: CSC.nonce }, function (resp) {
                    if (resp.success) { appendLines(opts.termId, resp.data.lines); }
                    opts.$btn.prop('disabled', false).html(opts.restoreLabel);
                    finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Nothing to do.');
                }).fail(function () {
                    opts.$btn.prop('disabled', false).html(opts.restoreLabel);
                    finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Nothing to do.');
                });
                return;
            }

            opts.$btn.html('⏳ Processing ' + total + ' items…');
            processNextChunk();
        }).fail(networkError);

        // Step 2: loop
        function processNextChunk() {
            var chunkPayload = { action: opts.chunkAction, nonce: CSC.nonce };
            if (opts.chunkExtra) { $.extend(chunkPayload, opts.chunkExtra); }

            $.post(CSC.ajax_url, chunkPayload, function (resp) {
                if (!resp.success) {
                    appendLine(opts.termId, { type: 'error', text: 'Error: ' + (resp.data || 'Unknown error') });
                    opts.$btn.prop('disabled', false).html(opts.restoreLabel);
                    finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Error — see log.');
                    return;
                }

                appendLines(opts.termId, resp.data.lines);
                var remaining = resp.data.remaining;
                done = total - remaining;
                var extra = opts.progressExtra ? opts.progressExtra(resp.data) : null;
                updateProgress(opts.progressFill, opts.progressLabel, done, total, extra);

                if (remaining > 0) {
                    // Small delay to keep the browser responsive and let the
                    // terminal update paint before firing the next request.
                    setTimeout(processNextChunk, 150);
                } else {
                    finish();
                }
            }).fail(networkError);
        }

        // Step 3: finish
        function finish() {
            $.post(CSC.ajax_url, { action: opts.finishAction, nonce: CSC.nonce }, function (resp) {
                if (resp.success) { appendLines(opts.termId, resp.data.lines); }
                opts.$btn.prop('disabled', false).html(opts.restoreLabel);
                finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Complete.');
                if (typeof opts.onFinish === 'function') { try { opts.onFinish(resp); } catch(e) { console.error('CSC onFinish callback error:', e); } }
            }).fail(networkError);
        }

        function networkError() {
            appendLine(opts.termId, { type: 'error', text: '  Network error. Check your connection and try again.' });
            opts.$btn.prop('disabled', false).html(opts.restoreLabel);
            finishProgress('#' + opts.progressOuter, opts.progressFill, opts.progressLabel, 'Network error.');
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // DATABASE CLEANUP
    // ═════════════════════════════════════════════════════════════════════════

    $('#btn-scan-db').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        clearTerminal('db-terminal');
        appendLine('db-terminal', { type: 'section', text: '=== DRY RUN — Database Scan ===' });

        // Collect toggle states from track divs
        var postData = { action: 'csc_scan_db', nonce: CSC.nonce };
        var tracked = 0;
        document.querySelectorAll('[data-csc-toggle-track]').forEach(function (track) {
            // Walk up to find the option row, then find the hidden input anywhere inside it
            var row = track.closest('.csc-option-row') || track.parentNode;
            var inputs = row.querySelectorAll('input[type="hidden"]');
            inputs.forEach(function (input) {
                if (input.name && input.name.indexOf('csc_clean_') === 0) {
                    postData[input.name] = track.getAttribute('data-on') === '1' ? '1' : '0';
                    tracked++;
                }
            });
        });

        $.post(CSC.ajax_url, postData, function (resp) {
            $btn.prop('disabled', false).html('🔍 Dry Run — Preview');
            if (resp.success) {
                appendLines('db-terminal', resp.data);
                appendLine('db-terminal', { type: 'info', text: '\nDry run complete. No changes have been made. Review the output log above before running cleanup.' });
            } else {
                appendLine('db-terminal', { type: 'error', text: 'Server error: ' + (resp.data || 'Unknown — check PHP error log') });
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            $btn.prop('disabled', false).html('🔍 Dry Run — Preview');
            appendLine('db-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ' — ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
            appendLine('db-terminal', { type: 'error', text: 'Response: ' + jqXHR.responseText.substring(0, 200) });
        });
    });

    $('#btn-run-db').on('click', function () {
        var toggleData = {};
        document.querySelectorAll('[data-csc-toggle-track]').forEach(function (track) {
            var row = track.closest('.csc-option-row') || track.parentNode;
            row.querySelectorAll('input[type="hidden"]').forEach(function (input) {
                if (input.name && input.name.indexOf('csc_clean_') === 0) {
                    toggleData[input.name] = track.getAttribute('data-on') === '1' ? '1' : '0';
                }
            });
        });
        runChunked({
            startAction:   'csc_db_start',
            chunkAction:   'csc_db_chunk',
            finishAction:  'csc_db_finish',
            startLabel:    'DATABASE CLEANUP RUNNING',
            termId:        'db-terminal',
            progressOuter: 'db-progress-outer',
            progressFill:  'db-progress-fill',
            progressLabel: 'db-progress-label',
            confirmMsg:    'This will permanently delete the items shown in the dry run. Proceed?',
            $btn:          $(this),
            restoreLabel:  '🗑 Run Cleanup Now',
            toggleData:    toggleData,
        });
    });

    // ═════════════════════════════════════════════════════════════════════════
    // IMAGE CLEANUP
    // ═════════════════════════════════════════════════════════════════════════

    // ── Media recycle bin status helper ──
    function mediaUpdateRecycleBin( count ) {
        $('#media-recycle-count').text( count > 0 ? '— ' + count + ' attachment(s) in recycle bin' : '— recycle bin is empty' );
    }

    // Check media recycle bin on page load
    $.post( CSC.ajax_url, { action: 'csc_media_recycle_status', nonce: CSC.nonce }, function( resp ) {
        mediaUpdateRecycleBin( resp.success ? resp.data.recycle : 0 );
    });

    $('#btn-scan-img').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        clearTerminal('img-terminal');
        appendLine('img-terminal', { type: 'section', text: '=== DRY RUN — Unused Media Scan ===' });

        $.post(CSC.ajax_url, { action: 'csc_scan_images', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('🔍 Dry Run — Preview');
            if (resp.success) {
                appendLines('img-terminal', resp.data);
                appendLine('img-terminal', { type: 'info', text: '\nDry run complete. No files moved or deleted. Review the output log above before moving to recycle.' });
            } else {
                appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
            }
        }).fail(function () {
            $btn.prop('disabled', false).html('🔍 Dry Run — Preview');
            appendLine('img-terminal', { type: 'error', text: 'Network error.' });
        });
    });

    $('#btn-run-img').on('click', function () {
        runChunked({
            startAction:   'csc_img_start',
            chunkAction:   'csc_img_chunk',
            finishAction:  'csc_img_finish',
            startLabel:    'MOVING UNUSED MEDIA TO RECYCLE',
            termId:        'img-terminal',
            progressOuter: 'img-progress-outer',
            progressFill:  'img-progress-fill',
            progressLabel: 'img-progress-label',
            confirmMsg:    'This will move unused media attachments to the recycle bin. You can restore them afterwards. Proceed?',
            $btn:          $(this),
            restoreLabel:  '♻️ Move to Recycle',
            onFinish:      function( resp ) {
                if ( resp && resp.data && typeof resp.data.recycle !== 'undefined' ) {
                    mediaUpdateRecycleBin( resp.data.recycle );
                }
            },
        });
    });

    // ── Media Recycle: Restore All ──
    $('#btn-restore-media').on('click', function () {
        if ( !confirm('Restore all media from the recycle bin? This will re-create the attachment records and move files back.') ) { return; }
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Restoring…');
        clearTerminal('img-terminal');

        $.post(CSC.ajax_url, { action: 'csc_media_restore', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('↩️ Restore All');
            if (resp.success) {
                appendLines('img-terminal', resp.data.lines);
                mediaUpdateRecycleBin( resp.data.recycle );
            } else {
                appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            $btn.prop('disabled', false).html('↩️ Restore All');
            appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ' — ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
        });
    });

    // ── Media Recycle: Permanently Delete ──
    $('#btn-purge-media').on('click', function () {
        if ( !confirm('PERMANENTLY DELETE all media in the recycle bin? This cannot be undone.') ) { return; }
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Deleting…');
        clearTerminal('img-terminal');

        $.post(CSC.ajax_url, { action: 'csc_media_purge', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('🗑 Permanently Delete');
            if (resp.success) {
                appendLines('img-terminal', resp.data.lines);
                mediaUpdateRecycleBin( resp.data.recycle );
            } else {
                appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            $btn.prop('disabled', false).html('🗑 Permanently Delete');
            appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ' — ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
        });
    });

    // ── Media Recycle: Browse Modal ──
    $('#btn-browse-media-recycle').on('click', function () {
        var $modal = $('#csc-media-recycle-modal').show();
        var $list  = $('#media-recycle-modal-list').html('<div style="padding:30px;text-align:center;color:#999">Loading media recycle bin…</div>');

        $.post(CSC.ajax_url, { action: 'csc_media_recycle_browse', nonce: CSC.nonce }, function (res) {
            if (!res.success || !res.data.files.length) {
                $list.html('<div style="padding:30px;text-align:center;color:#999">Recycle bin is empty.</div>');
                $('#media-recycle-modal-summary').text('0 attachments');
                return;
            }
            $('#media-recycle-modal-summary').text(res.data.total + ' attachment(s) · ' + res.data.total_size);

            $list.empty();
            res.data.files.forEach(function (file) {
                var $row = $('<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #eee">' +
                    '<div style="flex:1;min-width:0">' +
                        '<div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">ID ' + file.id + ' — ' + $('<span>').text(file.name).html() + '</div>' +
                        '<div style="font-size:11px;color:#888;margin-top:2px">' + file.file_count + ' file(s) · ' + file.size_fmt + (file.recycled ? ' · recycled ' + file.recycled : '') + '</div>' +
                    '</div>' +
                    '<button class="csc-media-restore-single" data-att-id="' + file.id + '" style="background:#43a047;color:#fff;border:none;border-radius:5px;padding:5px 12px;font-size:11px;font-weight:600;cursor:pointer;margin-left:10px;white-space:nowrap">↩️ Restore</button>' +
                '</div>');

                $row.find('.csc-media-restore-single').on('click', function () {
                    var $b = $(this);
                    $b.prop('disabled', true).text('⏳…');
                    $.post(CSC.ajax_url, { action: 'csc_media_restore_single', nonce: CSC.nonce, att_id: file.id }, function (res) {
                        if (res.success) {
                            $row.fadeOut(300, function () { $(this).remove(); });
                            $('#media-recycle-modal-summary').text(res.data.remaining + ' attachment(s) remaining');
                            mediaUpdateRecycleBin( res.data.remaining );
                        } else {
                            $b.prop('disabled', false).text('↩️ Restore');
                            alert('Restore failed: ' + (res.data || 'Unknown error'));
                        }
                    }).fail(function () { $b.prop('disabled', false).text('↩️ Restore'); alert('Network error.'); });
                });

                $list.append($row);
            });
        }).fail(function () {
            $list.html('<div style="padding:30px;text-align:center;color:#c00">Failed to load recycle bin.</div>');
        });
    });

    $('#btn-media-recycle-modal-close').on('click', function () { $('#csc-media-recycle-modal').hide(); });
    $('#csc-media-recycle-modal').on('click', function (e) { if (e.target === this) $(this).hide(); });

    // ── Orphan files recycle workflow ─────────────────────────────────────────
    // File types stored as data-ftype on buttons, set by inline onclick pill toggles

    function orphanUpdateRecycleBin( count ) {
        $('#orphan-recycle-count').text( count > 0 ? '— ' + count + ' file(s) in recycle bin' : '— recycle bin is empty' );
    }

    // Check recycle bin status on page load
    $.post( CSC.ajax_url, { action: 'csc_recycle_status', nonce: CSC.nonce }, function( resp ) {
        orphanUpdateRecycleBin( resp.success ? resp.data.recycle : 0 );
    });

    // Scan
    $('#btn-scan-orphan').on('click', function () {
        var fileType = (window.cscOrphanTypes && window.cscOrphanTypes.length) ? window.cscOrphanTypes.join(',') : '';
        if ( !fileType ) {
            cscShowModal('No File Types Selected', 'Please select at least one file type (Images, Documents, Video, or Audio) before scanning for orphaned files.');
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        clearTerminal('img-terminal');
        appendLine('img-terminal', { type: 'section', text: '=== UNREGISTERED FILE SCAN ===' });

        $.post(CSC.ajax_url, { action: 'csc_scan_orphan_files', nonce: CSC.nonce, file_type: fileType }, function (resp) {
            $btn.prop('disabled', false).html('🔍 Scan Unregistered Files');
            if (resp.success) {
                appendLines('img-terminal', resp.data.lines);
                orphanUpdateRecycleBin( resp.data.recycle );
            } else {
                appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            $btn.prop('disabled', false).html('🔍 Scan Unregistered Files');
            appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ' — ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
        });
    });

    // Move to recycle
    $('#btn-recycle-orphan').on('click', function () {
        var fileType = (window.cscOrphanTypes && window.cscOrphanTypes.length) ? window.cscOrphanTypes.join(',') : '';
        if ( !fileType ) {
            cscShowModal('No File Types Selected', 'Please select at least one file type (Images, Documents, Video, or Audio) before moving files to recycle.');
            return;
        }
        if ( !confirm('Move all orphaned files to the recycle bin? You can restore or permanently delete them afterwards.') ) { return; }
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Moving…');
        clearTerminal('img-terminal');

        $.post(CSC.ajax_url, { action: 'csc_recycle_orphan_files', nonce: CSC.nonce, file_type: fileType }, function (resp) {
            $btn.prop('disabled', false).html('♻️ Move to Recycle');
            if (resp.success) {
                appendLines('img-terminal', resp.data.lines);
                orphanUpdateRecycleBin( resp.data.recycle );
            } else {
                appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            $btn.prop('disabled', false).html('♻️ Move to Recycle');
            appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ' — ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
        });
    });

    // Restore
    $('#btn-restore-orphan').on('click', function () {
        if ( !confirm('Restore all files from the recycle bin to their original locations?') ) { return; }
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Restoring…');
        clearTerminal('img-terminal');

        $.post(CSC.ajax_url, { action: 'csc_restore_orphan_files', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('↩️ Restore All');
            if (resp.success) {
                appendLines('img-terminal', resp.data.lines);
                orphanUpdateRecycleBin( resp.data.recycle );
            } else {
                appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            $btn.prop('disabled', false).html('↩️ Restore All');
            appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ' — ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
        });
    });

    // Permanently delete
    $('#btn-purge-orphan').on('click', function () {
        if ( !confirm('PERMANENTLY DELETE all files in the recycle bin? This cannot be undone.') ) { return; }
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Deleting…');
        clearTerminal('img-terminal');

        $.post(CSC.ajax_url, { action: 'csc_purge_orphan_files', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('🗑 Permanently Delete');
            if (resp.success) {
                appendLines('img-terminal', resp.data.lines);
                orphanUpdateRecycleBin( resp.data.recycle );
            } else {
                appendLine('img-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            $btn.prop('disabled', false).html('🗑 Permanently Delete');
            appendLine('img-terminal', { type: 'error', text: 'AJAX failed: ' + textStatus + ' — ' + errorThrown + ' (HTTP ' + jqXHR.status + ')' });
        });
    });

    // ═════════════════════════════════════════════════════════════════════════
    // IMAGE OPTIMISATION
    // ═════════════════════════════════════════════════════════════════════════

    $('#btn-scan-optimise').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Scanning…');
        clearTerminal('optimise-terminal');
        appendLine('optimise-terminal', { type: 'section', text: '=== DRY RUN — Image Optimisation Preview ===' });

        $.post(CSC.ajax_url, { action: 'csc_scan_optimise', nonce: CSC.nonce }, function (resp) {
            $btn.prop('disabled', false).html('🔍 Dry Run — Preview Savings');
            if (resp.success) {
                appendLines('optimise-terminal', resp.data);
                appendLine('optimise-terminal', { type: 'info', text: '\nDry run complete. No files modified. Review the output log above before optimising.' });
            } else {
                appendLine('optimise-terminal', { type: 'error', text: 'Error: ' + (resp.data || 'Unknown') });
            }
        }).fail(function () {
            $btn.prop('disabled', false).html('🔍 Dry Run — Preview Savings');
            appendLine('optimise-terminal', { type: 'error', text: 'Network error.' });
        });
    });

    $('#btn-run-optimise').on('click', function () {
        runChunked({
            startAction:   'csc_optimise_start',
            chunkAction:   'csc_optimise_chunk',
            finishAction:  'csc_optimise_finish',
            startLabel:    'IMAGE OPTIMISATION RUNNING',
            termId:        'optimise-terminal',
            progressOuter: 'opt-progress-outer',
            progressFill:  'opt-progress-fill',
            progressLabel: 'opt-progress-label',
            confirmMsg:    'This will modify original image files on disk. Take a backup first. Proceed?',
            $btn:          $(this),
            restoreLabel:  '⚡ Optimise Images Now',
            // Show running disk savings in the progress label
            progressExtra: function (data) {
                if (data.total_saved && data.total_saved > 0) {
                    return formatBytes(data.total_saved) + ' saved so far';
                }
                return null;
            },
        });
    });

    // ── Byte formatter (mirrors PHP size_format) ───────────────────────────────

    function formatBytes(bytes) {
        if (bytes < 1024)           { return bytes + ' B'; }
        if (bytes < 1048576)        { return (bytes / 1024).toFixed(1) + ' KB'; }
        if (bytes < 1073741824)     { return (bytes / 1048576).toFixed(1) + ' MB'; }
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }



// ═════════════════════════════════════════════════════════════════════════════
// EXPLAIN MODALS
// ═════════════════════════════════════════════════════════════════════════════

    // Force explain button styles — WordPress admin CSS overrides inline styles
    document.querySelectorAll('[id^="csc-explain-btn-"]').forEach(function(btn) {
        var header = btn.parentElement;
        header.style.setProperty('display', 'flex', 'important');
        header.style.setProperty('align-items', 'center', 'important');
        header.style.setProperty('justify-content', 'space-between', 'important');
        btn.style.setProperty('margin-left', 'auto', 'important');
        btn.style.setProperty('flex-shrink', '0', 'important');
        // Restore color from data attribute set by PHP
        var color = btn.getAttribute('data-color');
        if (color) {
            btn.style.setProperty('background', color, 'important');
        }
    });

    // Force-hide native checkboxes inside slider labels
    // WordPress admin CSS can override our hidden-checkbox styles, so we apply
    // inline styles directly via JS after DOM ready — these always win.
    function initSliders() {
        document.querySelectorAll('.csc-slider-label input[type="checkbox"]').forEach(function (cb) {
            cb.style.cssText = [
                'position:absolute',
                'opacity:0',
                'width:1px',
                'height:1px',
                'margin:-1px',
                'padding:0',
                'border:0',
                'overflow:hidden',
                'clip:rect(0,0,0,0)',
                'white-space:nowrap',
                'pointer-events:none',
                '-webkit-appearance:none',
                'appearance:none',
                'z-index:-1'
            ].join('!important;') + '!important';
        });
    }
    initSliders();

    // ═══════════════════════════════════════════════════════════════════════════
    // BROKEN IMAGE LINK SCANNER
    // ═══════════════════════════════════════════════════════════════════════════

    $('#btn-scan-broken-images').on('click', function () {
        console.log('[CSC] Broken images scan clicked. AJAX URL:', CSC.ajax_url, 'Nonce:', CSC.nonce ? CSC.nonce.substring(0,4) + '…' : 'MISSING');
        var $btn  = $(this).prop('disabled', true).text('Scanning…');
        var $term = $('#img-terminal').empty();
        var allBroken = [];

        function termLine(type, text) {
            var color = type === 'error' ? '#ff5252' : type === 'success' ? '#69f0ae' : type === 'section' ? '#00e5ff' : '#b0bec5';
            $term.append('<div style="color:' + color + '">' + $('<span>').text(text).html() + '</div>');
            $term.scrollTop($term[0].scrollHeight);
        }

        termLine('section', '=== BROKEN IMAGE LINK SCAN ===');

        function scanBatch(offset) {
            $.post(CSC.ajax_url, {
                action: 'csc_scan_broken_images',
                nonce: CSC.nonce,
                offset: offset
            }, function (res) {
                if (!res || !res.success) {
                    termLine('error', 'Scan failed: ' + ((res && res.data) || 'Unknown error'));
                    $btn.prop('disabled', false).text('🔍 Scan for Broken Images');
                    return;
                }
                var d = res.data;
                allBroken = allBroken.concat(d.broken);
                var scanned = Math.min(d.offset, d.total);
                termLine('info', '  Scanned ' + scanned + ' / ' + d.total + ' posts… (' + allBroken.length + ' broken so far)');

                for (var i = 0; i < d.broken.length; i++) {
                    var b = d.broken[i];
                    var fname = b.image_url.split('/').pop();
                    termLine('error', '  [BROKEN] ' + fname);
                    termLine('info', '    Post: ' + b.post_title + ' (ID ' + b.post_id + ')');
                    termLine('info', '    ' + b.image_url);
                }

                if (d.has_more) {
                    scanBatch(d.offset);
                } else {
                    if (allBroken.length === 0) {
                        termLine('success', '  ✓ No broken image links found. All ' + d.total + ' posts with images checked.');
                    } else {
                        termLine('section', '  Found ' + allBroken.length + ' broken image link(s) across ' + d.total + ' posts.');
                        $('#btn-copy-broken-images').show().data('broken', allBroken);
                    }
                    $btn.prop('disabled', false).text('🔍 Scan for Broken Images');
                }
            }).fail(function () {
                termLine('error', 'Network error during scan.');
                $btn.prop('disabled', false).text('🔍 Scan for Broken Images');
            });
        }

        scanBatch(0);
    });

    // Copy broken images results to clipboard
    $('#btn-copy-broken-images').on('click', function () {
        var broken = $(this).data('broken') || [];
        var text = broken.map(function (b) {
            return '✗ ' + b.image_url.split('/').pop() + '\n' +
                   'Post: ' + b.post_title + ' (ID ' + b.post_id + ')\n' +
                   b.image_url;
        }).join('\n\n');
        navigator.clipboard.writeText(text).then(function () {
            var $b = $('#btn-copy-broken-images');
            $b.text('✓ Copied!');
            setTimeout(function () { $b.text('📋 Copy Results'); }, 2000);
        });
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // RECYCLE BIN BROWSER
    // ═══════════════════════════════════════════════════════════════════════════

    var recycleFiles = [];

    $('#btn-browse-recycle').on('click', function () {
        var $modal = $('#csc-recycle-modal').show();
        var $list  = $('#recycle-modal-list').html('<div style="padding:30px;text-align:center;color:#999">Loading recycle bin…</div>');
        $('#recycle-search').val('');

        $.post(CSC.ajax_url, { action: 'csc_recycle_browse', nonce: CSC.nonce }, function (res) {
            if (!res || !res.success) {
                $list.html('<div style="padding:30px;text-align:center;color:#d32f2f">Failed to load recycle bin.</div>');
                return;
            }
            recycleFiles = res.data.files;
            $('#recycle-modal-summary').text(res.data.total + ' file(s) · ' + res.data.total_size);
            renderRecycleList('');
        });
    });

    function renderRecycleList(filter) {
        var $list = $('#recycle-modal-list').empty();
        var lf = filter.toLowerCase();
        var shown = 0;
        for (var i = 0; i < recycleFiles.length; i++) {
            var f = recycleFiles[i];
            if (lf && f.name.toLowerCase().indexOf(lf) === -1 && f.rel.toLowerCase().indexOf(lf) === -1) continue;
            shown++;
            (function (file, idx) {
                var $row = $('<div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f0f0f0">' +
                    '<div style="flex:1;min-width:0">' +
                        '<div style="font-weight:600;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + $('<span>').text(file.name).html() + '">' + $('<span>').text(file.name).html() + '</div>' +
                        '<div style="font-size:11px;color:#999">' + $('<span>').text(file.date).html() + ' · ' + file.size_fmt + '</div>' +
                    '</div>' +
                    '<button class="csc-recycle-restore-btn" style="background:#43a047;color:#fff;border:none;border-radius:5px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap">↩️ Restore</button>' +
                '</div>');
                $row.find('.csc-recycle-restore-btn').on('click', function () {
                    var $b = $(this).prop('disabled', true).text('Restoring…');
                    $.post(CSC.ajax_url, { action: 'csc_recycle_restore_single', nonce: CSC.nonce, rel: file.rel }, function (res) {
                        if (res && res.success) {
                            $b.closest('div[style*="display:flex"]').css({ background: '#e8f5e9', borderRadius: '6px', padding: '10px' });
                            $b.text('✓ Restored').css({ background: '#a5d6a7', color: '#1b5e20' });
                            recycleFiles.splice(idx, 1);
                            $('#recycle-modal-summary').text(res.data.remaining + ' file(s) remaining');
                            // Update recycle count in main UI
                            $('#orphan-recycle-count').text(res.data.remaining > 0 ? res.data.remaining + ' file(s)' : 'Empty');
                        } else {
                            $b.prop('disabled', false).text('↩️ Restore').css('background', '#ef5350');
                            alert('Restore failed: ' + ((res && res.data) || 'Unknown error'));
                        }
                    });
                });
                $list.append($row);
            })(f, i);
        }
        if (shown === 0) {
            $list.html('<div style="padding:30px;text-align:center;color:#999">' + (lf ? 'No files matching "' + $('<span>').text(lf).html() + '"' : 'Recycle bin is empty.') + '</div>');
        }
    }

    $('#recycle-search').on('input', function () { renderRecycleList($(this).val()); });
    $('#btn-recycle-modal-close').on('click', function () { $('#csc-recycle-modal').hide(); });
    $('#csc-recycle-modal').on('click', function (e) { if (e.target === this) $(this).hide(); });

    // Copy all recycle bin contents to clipboard
    $('#btn-recycle-copy-all').on('click', function () {
        var text = recycleFiles.map(function (f) { return f.name + '\t' + f.size_fmt + '\t' + f.date; }).join('\n');
        navigator.clipboard.writeText(text).then(function () {
            var $b = $('#btn-recycle-copy-all');
            $b.text('✓ Copied!');
            setTimeout(function () { $b.text('📋 Copy All to Clipboard'); }, 2000);
        });
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // PNG TO JPEG CONVERTER
    // ═══════════════════════════════════════════════════════════════════════════

    if (typeof CSC === 'undefined') {
        window.CSC = {};
        console.error('[CSPJ] CSC localize object is missing. wp_localize_script may not have run. Check that the plugin directory is named "cloudscale-cleanup" and that no nested folders exist.');
    }

    var cspjFiles          = [];
    var cspjConvertedToday = 0;
    var cspjChunkMb        = parseFloat( CSC.cspj_chunk_mb ) || 1.5;
    var cspjServerMaxMb    = parseInt( CSC.cspj_server_max_mb, 10 ) || 0;
    var cspjMaxTotalMb     = parseInt( CSC.cspj_max_total_mb, 10 ) || 200;
    var cspjPendingLibrary = null;
    var cspjDebugLog       = [];

    function cspjDbg( tag, msg ) {
        var ts   = new Date();
        var time = cspjPad2(ts.getHours()) + ':' + cspjPad2(ts.getMinutes()) + ':' + cspjPad2(ts.getSeconds()) + '.' + cspjPad3(ts.getMilliseconds());
        cspjDebugLog.push({ time: time, tag: tag, msg: msg });

        var $log = $('#cspj-debug-log');
        if ( !$log.length ) return;

        var tagClass = 'cspj-tag-info';
        if (tag === 'OK')    tagClass = 'cspj-tag-ok';
        if (tag === 'WARN')  tagClass = 'cspj-tag-warn';
        if (tag === 'ERROR') tagClass = 'cspj-tag-error';
        if (tag === 'REQ')   tagClass = 'cspj-tag-req';
        if (tag === 'RESP')  tagClass = 'cspj-tag-resp';

        $log.append(
            '<div class="cspj-log-entry">' +
                '<span class="cspj-log-ts">' + time + '</span>' +
                '<span class="cspj-log-tag ' + tagClass + '">' + esc(tag) + '</span>' +
                '<span class="cspj-log-msg">' + msg + '</span>' +
            '</div>'
        );
        $log[0].scrollTop = $log[0].scrollHeight;
        console.log('[CSPJ][' + tag + '] ' + msg.replace(/<[^>]+>/g, ''));
    }

    function cspjPad2(n) { return n < 10 ? '0' + n : '' + n; }
    function cspjPad3(n) { return n < 10 ? '00' + n : (n < 100 ? '0' + n : '' + n); }

    // Init debug env
    (function() {
        var $env = $('#cspj-debug-env');
        if (!$env.length) return;
        var lines = [];
        lines.push('<strong>Plugin:</strong> CSC v' + (CSC.version || 'unknown') + ' (PNG to JPEG)');
        lines.push('<strong>AJAX URL:</strong> ' + (CSC.ajax_url || '<span style="color:var(--csc-red)">NOT SET</span>'));
        lines.push('<strong>Nonce:</strong> ' + (CSC.nonce ? CSC.nonce.substring(0, 4) + '…' : '<span style="color:var(--csc-red)">NOT SET</span>'));
        lines.push('<strong>Chunk size:</strong> ' + cspjChunkMb + ' MB');
        lines.push('<strong>Server max:</strong> ' + cspjServerMaxMb + ' MB');
        $env.html(lines.join(' &bull; '));
    })();

    cspjDbg('INFO', 'PNG to JPEG converter initialised');

    // Quality slider
    $('#cspj-quality').on('input', function () {
        $('#cspj-quality-val').text($(this).val());
    });

    // Size dropdown
    $('#cspj-size').on('change', function () {
        $('#cspj-custom-size').toggle($(this).val() === 'custom');
    });

    // Chunk size save
    $('#cspj-save-chunkmb').on('click', function () {
        var mb = parseFloat($('#cspj-chunk-mb').val());
        if (!mb || mb < 0.25) { $('#cspj-save-status').text('Enter a valid number.').css('color', 'var(--csc-red)'); return; }
        var $btn = $(this).prop('disabled', true).text('Saving…');
        $.post(CSC.ajax_url, { action: 'csc_pj_save_settings', nonce: CSC.nonce, chunk_mb: mb }, function (res) {
            $btn.prop('disabled', false).text('Save');
            if (res.success) {
                cspjChunkMb = res.data.chunk_mb;
                $('#cspj-save-status').text('Saved — ' + cspjChunkMb + ' MB').css('color', 'var(--csc-green)');
                cspjDbg('OK', 'Chunk size saved: <span class="cspj-log-val">' + cspjChunkMb + ' MB</span>');
            } else {
                $('#cspj-save-status').text('Error').css('color', 'var(--csc-red)');
                cspjDbg('ERROR', 'Save failed: <span class="cspj-log-err">' + esc(res.data) + '</span>');
            }
            setTimeout(function () { $('#cspj-save-status').text(''); }, 4000);
        }).fail(function () {
            $btn.prop('disabled', false).text('Save');
            $('#cspj-save-status').text('Request failed.').css('color', 'var(--csc-red)');
        });
    });

    // Browse button
    var cspjBrowse = document.getElementById('cspj-browse-btn');
    var cspjInput  = document.getElementById('cspj-file-input');
    if (cspjBrowse && cspjInput) {
        cspjBrowse.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            cspjInput.click();
        });
    }

    // Drop zone
    $('#cspj-drop-zone').on('click', function (e) {
        if (e.target.id === 'cspj-browse-btn') return;
        cspjInput.click();
    }).on('dragover dragenter', function (e) {
        e.preventDefault();
        $(this).addClass('dragover');
    }).on('dragleave drop', function (e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        if (e.type === 'drop') cspjAddFiles(e.originalEvent.dataTransfer.files);
    });

    $('#cspj-file-input').on('change', function () {
        cspjAddFiles(this.files);
        this.value = '';
    });

    function cspjUpdateConvertBtn() {
        var hasPending = cspjFiles.some(function (f) { return f.status === 'pending'; });
        $('#cspj-convert-all').prop('disabled', !hasPending);
    }

    function cspjAddFiles(fileList) {
        $.each(fileList, function (i, f) {
            if (f.type !== 'image/png') {
                cspjDbg('WARN', 'Rejected non PNG: <span class="cspj-log-err">' + esc(f.name) + '</span>');
                alert(f.name + ' is not a PNG file and was skipped.');
                return;
            }
            var id = 'cspj-' + Date.now() + '-' + i;
            cspjFiles.push({ id: id, file: f, status: 'pending' });
            cspjRenderFileItem(id, f);
            cspjDbg('OK', 'Queued: <span class="cspj-log-val">' + esc(f.name) + '</span> (' + cspjFmtSize(f.size) + ')');
        });
        if (cspjFiles.length > 0) {
            $('#cspj-queue-card').show();
            cspjUpdateConvertBtn();
        }
    }

    function cspjRenderFileItem(id, f) {
        $('#cspj-file-list').append(
            '<div class="cspj-file-row" id="row-' + id + '">' +
                '<div class="cspj-file-item" id="item-' + id + '">' +
                    '<div class="cspj-file-thumb-wrap"><img class="cspj-file-thumb" src="" alt=""></div>' +
                    '<div class="cspj-file-info">' +
                        '<div class="cspj-file-name">' + esc(f.name) + '</div>' +
                        '<div class="cspj-file-meta">' + cspjFmtSize(f.size) + '</div>' +
                    '</div>' +
                    '<span class="cspj-badge cspj-badge-pending">Pending</span>' +
                    '<button class="cspj-file-remove" data-id="' + id + '" title="Remove">&times;</button>' +
                '</div>' +
                '<div class="cspj-error-detail" id="err-' + id + '">' +
                    '<div style="font-size:16px">⚠</div>' +
                    '<div id="errtxt-' + id + '"></div>' +
                '</div>' +
            '</div>'
        );
        var reader = new FileReader();
        reader.onload = function (e) {
            $('#item-' + id + ' .cspj-file-thumb').attr('src', e.target.result);
        };
        reader.readAsDataURL(f);
    }

    $(document).on('click', '.cspj-file-remove', function () {
        var id = $(this).data('id');
        cspjFiles = cspjFiles.filter(function (f) { return f.id !== id; });
        $('#row-' + id).remove();
        if (cspjFiles.length === 0) $('#cspj-queue-card').hide();
        cspjUpdateConvertBtn();
    });

    // Convert all
    $('#cspj-convert-all').on('click', function () {
        if (!CSC.ajax_url || !CSC.nonce) {
            alert('Configuration error: AJAX URL or security nonce is not set. This usually means the plugin assets are not loading correctly. Please deactivate and reactivate the plugin, clear your browser cache, and ensure the plugin folder is named "cloudscale-cleanup" with no nested subfolders.');
            cspjDbg('ERROR', '<span class="cspj-log-err">Cannot start: CSC.ajax_url=' + (CSC.ajax_url || 'EMPTY') + ' CSC.nonce=' + (CSC.nonce || 'EMPTY') + '</span>');
            return;
        }
        var pending = cspjFiles.filter(function (f) { return f.status === 'pending'; });
        if (!pending.length) { alert('No pending files to convert.'); return; }
        cspjDbg('INFO', 'Starting batch: <span class="cspj-log-val">' + pending.length + ' file(s)</span>');
        $('#cspj-results').show();
        cspjConvertNext(pending, 0);
    });

    function cspjConvertNext(pending, index) {
        if (index >= pending.length) {
            cspjDbg('OK', '<span class="cspj-log-grn">Batch complete.</span>');
            return;
        }
        var item = pending[index];
        item.status = 'converting';
        var file = item.file;
        var chunkBytes  = Math.floor(cspjChunkMb * 1048576);
        var totalChunks = Math.max(1, Math.ceil(file.size / chunkBytes));

        cspjDbg('INFO', 'Processing [' + (index+1) + '/' + pending.length + ']: <span class="cspj-log-val">' + esc(file.name) + '</span>');
        cspjDbg('INFO', 'File size: ' + cspjFmtSize(file.size) + ' | Chunk size: ' + cspjChunkMb + ' MB (' + chunkBytes + ' bytes) | Total chunks: ' + totalChunks);
        cspjDbg('INFO', 'AJAX URL: <span class="cspj-log-val">' + esc(CSC.ajax_url || 'EMPTY') + '</span>');
        cspjDbg('INFO', 'Nonce: <span class="cspj-log-val">' + (CSC.nonce ? CSC.nonce.substring(0, 6) + '...' : 'EMPTY') + '</span>');
        cspjDbg('INFO', 'Action: <span class="cspj-log-val">csc_pj_chunk_start</span>');

        cspjSetBadge(item.id, 'loading', 'Uploading…');

        if (!CSC.ajax_url) {
            cspjDbg('ERROR', '<span class="cspj-log-err">FATAL: CSC.ajax_url is empty. wp_localize_script did not fire and inline fallback is missing. The plugin PHP file is outdated.</span>');
            cspjSetError(item.id, 'Config error: AJAX URL not set. See debug console.');
            item.status = 'error';
            return cspjConvertNext(pending, index + 1);
        }
        if (!CSC.nonce) {
            cspjDbg('ERROR', '<span class="cspj-log-err">FATAL: CSC.nonce is empty. Security token not available.</span>');
            cspjSetError(item.id, 'Config error: Nonce not set. See debug console.');
            item.status = 'error';
            return cspjConvertNext(pending, index + 1);
        }

        var startPayload = {
            action: 'csc_pj_chunk_start',
            nonce: CSC.nonce,
            filename: file.name,
            total_size: file.size,
            total_chunks: totalChunks
        };
        cspjDbg('REQ', 'POST ' + esc(CSC.ajax_url) + ' payload=' + esc(JSON.stringify(startPayload)).substring(0, 300));

        $.post(CSC.ajax_url, startPayload, function (startRes) {
            cspjDbg('RESP', 'HTTP OK | success=' + (startRes && startRes.success) + ' | data=' + esc(JSON.stringify(startRes && startRes.data)).substring(0, 300));
            if (!startRes || !startRes.success) {
                item.status = 'error';
                var errMsg = 'Server returned no data.';
                if (startRes && startRes.data) {
                    errMsg = typeof startRes.data === 'string' ? startRes.data : JSON.stringify(startRes.data);
                }
                cspjSetError(item.id, errMsg);
                cspjDbg('ERROR', 'chunk_start rejected: <span class="cspj-log-err">' + esc(errMsg) + '</span>');
                return cspjConvertNext(pending, index + 1);
            }
            var uploadId = startRes.data.upload_id;
            cspjDbg('OK', 'Upload session: <span class="cspj-log-val">' + esc(uploadId) + '</span>');
            var i = 0;

            function uploadOne() {
                if (i >= totalChunks) {
                    cspjSetBadge(item.id, 'loading', 'Converting…');
                    cspjDbg('INFO', 'All chunks uploaded. Calling csc_pj_chunk_finish...');
                    return finish();
                }
                var startByte = i * chunkBytes;
                var endByte   = Math.min(file.size, startByte + chunkBytes);
                var blob      = file.slice(startByte, endByte);
                if (totalChunks > 1) {
                    cspjSetBadge(item.id, 'loading', 'Uploading… ' + Math.round(((i+1)/totalChunks)*100) + '%');
                }

                cspjDbg('REQ', 'Chunk ' + (i+1) + '/' + totalChunks + ': bytes ' + startByte + '-' + endByte + ' (' + cspjFmtSize(blob.size) + ')');

                var fd = new FormData();
                fd.append('action', 'csc_pj_chunk_upload');
                fd.append('nonce', CSC.nonce);
                fd.append('upload_id', uploadId);
                fd.append('chunk_index', String(i));
                fd.append('total_chunks', String(totalChunks));
                fd.append('chunk', blob, file.name + '.part');

                $.ajax({
                    url: CSC.ajax_url, type: 'POST', data: fd,
                    processData: false, contentType: false,
                    success: function (upRes) {
                        cspjDbg('RESP', 'chunk_upload [' + (i+1) + '/' + totalChunks + ']: success=' + (upRes && upRes.success));
                        if (upRes && upRes.success) { i++; uploadOne(); }
                        else {
                            item.status = 'error';
                            var errMsg = (upRes && upRes.data) ? String(upRes.data) : 'Chunk upload rejected by server.';
                            cspjSetError(item.id, errMsg);
                            cspjDbg('ERROR', 'chunk_upload rejected: <span class="cspj-log-err">' + esc(errMsg) + '</span>');
                            return cspjConvertNext(pending, index + 1);
                        }
                    },
                    error: function (xhr) {
                        item.status = 'error';
                        var body = '';
                        try { body = xhr.responseText || ''; } catch(e) {}
                        var msg = xhr.status === 0
                            ? 'Network error or request blocked (status 0). Possible causes: server timeout, mod_security, or file too large for server.'
                            : ('HTTP ' + xhr.status + ' ' + (xhr.statusText || ''));
                        cspjDbg('ERROR', 'chunk_upload FAILED: <span class="cspj-log-err">' + esc(msg) + '</span>');
                        if (body) { cspjDbg('ERROR', 'Response body: <span class="cspj-log-err">' + esc(body.substring(0, 500)) + '</span>'); }
                        cspjSetError(item.id, msg);
                        return cspjConvertNext(pending, index + 1);
                    }
                });
            }

            function finish() {
                var finPayload = {
                    action: 'csc_pj_chunk_finish', nonce: CSC.nonce, upload_id: uploadId,
                    quality: $('#cspj-quality').val(), size: $('#cspj-size').val(),
                    custom_w: $('#cspj-custom-w').val() || '0', custom_h: $('#cspj-custom-h').val() || '0',
                    constrain: $('#cspj-constrain').is(':checked') ? '1' : '0'
                };
                cspjDbg('REQ', 'POST csc_pj_chunk_finish: quality=' + finPayload.quality + ' size=' + esc(finPayload.size));

                $.post(CSC.ajax_url, finPayload, function (finRes) {
                    cspjDbg('RESP', 'chunk_finish: success=' + (finRes && finRes.success) + ' data=' + esc(JSON.stringify(finRes && finRes.data)).substring(0, 300));
                    if (finRes && finRes.success) {
                        item.status = 'done';
                        item.result = finRes.data;
                        cspjSetBadge(item.id, 'done', 'Done');
                        cspjRenderResult(item);
                        cspjConvertedToday++;
                        cspjDbg('OK', '<span class="cspj-log-grn">Converted:</span> <span class="cspj-log-val">' + esc(finRes.data.name) + '</span> (' + finRes.data.width + 'x' + finRes.data.height + ', ' + esc(finRes.data.size) + ')');
                    } else {
                        item.status = 'error';
                        var errMsg = (finRes && finRes.data) ? String(finRes.data) : 'Conversion failed on server.';
                        cspjSetError(item.id, errMsg);
                        cspjDbg('ERROR', 'chunk_finish rejected: <span class="cspj-log-err">' + esc(errMsg) + '</span>');
                    }
                    return cspjConvertNext(pending, index + 1);
                }).fail(function (xhr) {
                    item.status = 'error';
                    var body = '';
                    try { body = xhr.responseText || ''; } catch(e) {}
                    var msg = 'HTTP ' + xhr.status + ' ' + (xhr.statusText || '');
                    cspjDbg('ERROR', 'chunk_finish FAILED: <span class="cspj-log-err">' + esc(msg) + '</span>');
                    if (body) { cspjDbg('ERROR', 'Response body: <span class="cspj-log-err">' + esc(body.substring(0, 500)) + '</span>'); }
                    cspjSetError(item.id, msg);
                    return cspjConvertNext(pending, index + 1);
                });
            }

            uploadOne();
        }).fail(function (xhr) {
            item.status = 'error';
            var body = '';
            try { body = xhr.responseText || ''; } catch(e) {}
            var msg = xhr.status === 0
                ? 'Network error (status 0). AJAX URL may be wrong or server unreachable.'
                : ('HTTP ' + xhr.status + ' ' + (xhr.statusText || ''));
            cspjDbg('ERROR', 'chunk_start FAILED: <span class="cspj-log-err">' + esc(msg) + '</span>');
            if (body) { cspjDbg('ERROR', 'Response body: <span class="cspj-log-err">' + esc(body.substring(0, 500)) + '</span>'); }
            cspjSetError(item.id, msg);
            return cspjConvertNext(pending, index + 1);
        });
    }

    function cspjSetBadge(id, type, label) {
        $('#item-' + id).find('.cspj-badge')
            .removeClass('cspj-badge-pending cspj-badge-loading cspj-badge-done cspj-badge-error')
            .addClass('cspj-badge-' + type)
            .html(label);
    }

    function cspjSetError(id, msg) {
        cspjSetBadge(id, 'error', '⚠ Error');
        var $err = $('#err-' + id);
        $('#errtxt-' + id).html('<strong style="color:#991b1b;display:block;margin-bottom:4px">Error:</strong>' + esc(msg));
        $err.css('display', 'flex');
    }

    function cspjRenderResult(item) {
        var r = item.result;
        $('#cspj-results-list').append(
            '<div class="cspj-result-item" id="result-' + item.id + '">' +
                '<img class="cspj-result-thumb" src="' + r.url + '" alt="">' +
                '<div class="cspj-result-info">' +
                    '<div class="cspj-result-name" id="rname-' + item.id + '">' + esc(r.name) + '</div>' +
                    '<div class="cspj-result-meta">' + r.width + ' × ' + r.height + ' · ' + r.size + '</div>' +
                '</div>' +
                '<div class="cspj-result-btns">' +
                    '<a href="' + r.url + '" download="' + esc(r.name) + '" class="cspj-btn-download">↓ Download</a>' +
                    '<button class="cspj-btn-library" data-path="' + esc(r.path) + '" data-url="' + r.url + '" data-orig="' + esc(r.name.replace(/\.jpe?g$/i, '')) + '" data-rid="' + item.id + '">💾 Add to Media</button>' +
                    '<button class="cspj-btn-remove-result" data-rid="' + item.id + '" data-path="' + esc(r.path) + '" title="Remove from list" style="background:none;border:none;font-size:18px;color:var(--csc-muted);cursor:pointer;padding:0 4px;line-height:1">&times;</button>' +
                '</div>' +
            '</div>'
        );
    }

    // Rename + add to library
    $(document).on('click', '.cspj-btn-library', function () {
        var $btn = $(this);
        cspjPendingLibrary = {
            path: $btn.data('path'), url: $btn.data('url'),
            orig: $btn.data('orig'), resultId: $btn.data('rid'), $btn: $btn
        };
        $('#cspj-rename-input').val(cspjPendingLibrary.orig);
        $('#cspj-rename-error').hide().text('');
        $('#cspj-rename-confirm').prop('disabled', false).html('💾 Add to Library');
        $('#cspj-popup-rename').css('display', 'flex');
        setTimeout(function () { $('#cspj-rename-input').select(); }, 150);
    });

    $(document).on('click', '#cspj-rename-cancel, #cspj-rename-cancel-2', function () {
        $('#cspj-popup-rename').hide();
        cspjPendingLibrary = null;
    });

    $(document).on('click', '#cspj-popup-rename', function (e) {
        if ($(e.target).is('#cspj-popup-rename')) { $(this).hide(); cspjPendingLibrary = null; }
    });

    $(document).on('click', '#cspj-rename-confirm', function () {
        if (!cspjPendingLibrary) return;
        var rawName = $('#cspj-rename-input').val().trim();
        if (!rawName) { $('#cspj-rename-error').text('Please enter a filename.').show(); return; }

        $('#cspj-rename-confirm').prop('disabled', true).text('Saving…');
        $.post(CSC.ajax_url, {
            action: 'csc_pj_add_to_library', nonce: CSC.nonce,
            path: cspjPendingLibrary.path, url: cspjPendingLibrary.url, new_name: rawName
        }, function (res) {
            if (res.success) {
                $('#cspj-popup-rename').hide();
                var $btn = cspjPendingLibrary.$btn;
                var rid  = cspjPendingLibrary.resultId;
                cspjPendingLibrary = null;
                $('#rname-' + rid).text(res.data.final_name);
                $btn.replaceWith('<a href="' + res.data.edit_url + '" class="cspj-library-link" target="_blank">✓ View in Library</a>');
                cspjDbg('OK', 'Added to library: <span class="cspj-log-val">' + esc(res.data.final_name) + '</span>');
            } else {
                $('#cspj-rename-error').text(res.data || 'Unknown error.').show();
                $('#cspj-rename-confirm').prop('disabled', false).html('💾 Add to Library');
            }
        }).fail(function () {
            $('#cspj-rename-error').text('Request failed.').show();
            $('#cspj-rename-confirm').prop('disabled', false).html('💾 Add to Library');
        });
    });

    $(document).on('keydown', '#cspj-rename-input', function (e) {
        if (e.key === 'Enter') $('#cspj-rename-confirm').trigger('click');
    });

    // Clear queue
    $('#cspj-clear-all').on('click', function () {
        cspjFiles = [];
        $('#cspj-file-list').empty();
        $('#cspj-queue-card').hide();
        $('#cspj-results').hide();
        $('#cspj-results-list').empty();
        cspjDbg('INFO', 'Queue cleared');
    });

    // Delete individual result from converted files list
    $(document).on('click', '.cspj-btn-remove-result', function () {
        var rid  = $(this).data('rid');
        var path = $(this).data('path');
        var $row = $('#result-' + rid);
        // Delete the converted file from server
        $.post(CSC.ajax_url, {
            action: 'csc_pj_delete_converted', nonce: CSC.nonce, path: path
        }, function (res) {
            if (res.success) {
                cspjDbg('OK', 'Deleted converted file from disk');
            } else {
                cspjDbg('WARN', 'Could not delete file from disk: <span class="cspj-log-err">' + esc(res.data) + '</span>');
            }
        });
        // Remove from DOM immediately
        $row.fadeOut(200, function () {
            $(this).remove();
            if ($('#cspj-results-list').children().length === 0) {
                $('#cspj-results').hide();
            }
        });
        // Remove from files array
        cspjFiles = cspjFiles.filter(function (f) { return f.id !== rid; });
    });

    // Debug console controls
    $(document).on('click', '#cspj-debug-toggle', function () {
        var $log = $('#cspj-debug-log');
        var $env = $('#cspj-debug-env');
        if ($log.is(':visible')) { $log.hide(); $env.hide(); $(this).text('▶'); }
        else { $log.show(); $env.show(); $(this).text('▼'); }
    });
    $(document).on('click', '#cspj-debug-clear', function () {
        cspjDebugLog = [];
        $('#cspj-debug-log').empty();
        cspjDbg('INFO', 'Console cleared');
    });
    $(document).on('click', '#cspj-debug-copy', function () {
        var text = cspjDebugLog.map(function (e) {
            return e.time + ' [' + e.tag + '] ' + e.msg.replace(/<[^>]+>/g, '');
        }).join('\n');
        text = '=== CSPJ Debug Log ===\n' + $('#cspj-debug-env').text() + '\n\n' + text;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
                cspjDbg('OK', 'Log copied (' + cspjDebugLog.length + ' entries)');
            });
        }
    });

    function cspjFmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        return (b / 1048576).toFixed(1) + ' MB';
    }

    // ═════════════════════════════════════════════════════════════════════════
    // SITE HEALTH
    // ═════════════════════════════════════════════════════════════════════════

    function healthFormatBytes(b) {
        if (b < 0) return '—';
        if (b === 0) return '0 B';
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1073741824).toFixed(2) + ' GB';
    }

    function healthRenderData(d) {
        // RAG bar
        var ragColors = { red: '#c62828', amber: '#e65100', green: '#2e7d32', grey: '#78909c' };
        var ragBgs    = { red: '#ffebee', amber: '#fff3e0', green: '#e8f5e9', grey: '#eceff1' };
        var ragLabels = {
            red:   'Critical — Less than 3 months of storage remaining',
            amber: 'Warning — Less than 6 months of storage remaining',
            green: 'Healthy — More than 6 months of storage remaining',
            grey:  'Collecting data — need at least 2 weekly snapshots'
        };

        var rag = d.disk_rag || 'grey';
        $('#csc-health-rag-bar').css('background', ragBgs[rag]);
        $('#csc-health-rag-dot').css('background', ragColors[rag]);
        $('#csc-health-rag-label').text(rag === 'grey' ? 'Collecting Data' : rag.charAt(0).toUpperCase() + rag.slice(1)).css('color', ragColors[rag]);
        $('#csc-health-rag-detail').text(ragLabels[rag]).css('color', ragColors[rag]);

        // Disk metrics
        $('#hm-disk-used').text(healthFormatBytes(d.disk_used));
        $('#hm-disk-free').text(healthFormatBytes(d.disk_free));
        $('#hm-disk-total').text(healthFormatBytes(d.disk_total));
        $('#hm-db-size').text(healthFormatBytes(d.db_size));
        $('#hm-growth').text(d.growth_per_week > 0 ? healthFormatBytes(d.growth_per_week) + '/wk' : (d.weekly_count >= 2 ? 'Stable / shrinking' : 'Collecting…'));
        if (d.weeks_remaining > 0) {
            var wk = Math.round(d.weeks_remaining);
            $('#hm-weeks-left').text(wk + ' wk' + (wk !== 1 ? 's' : '') + ' (~' + Math.round(wk / 4.3) + ' mo)').css('color', ragColors[rag]);
        } else if (d.weekly_count >= 2) {
            $('#hm-weeks-left').text('∞ (stable)').css('color', '#2e7d32');
        } else {
            $('#hm-weeks-left').text('Collecting…').css('color', '#78909c');
        }

        // CPU — show percentage with load average in parentheses
        var cpuNow = d.cpu_pct_now >= 0 ? d.cpu_pct_now + '%' : '—';
        if (d.cpu_load_now >= 0) { cpuNow += ' (load ' + d.cpu_load_now.toFixed(2) + ')'; }
        $('#hm-cpu-now').text(cpuNow);
        $('#hm-cpu-24h').text(d.cpu_pct_max_24h >= 0 ? d.cpu_pct_max_24h + '%' : (d.cpu_max_24h >= 0 ? d.cpu_max_24h.toFixed(2) + ' load' : '—'));
        $('#hm-cpu-7d').text(d.cpu_pct_max_7d >= 0 ? d.cpu_pct_max_7d + '%' : (d.cpu_max_7d >= 0 ? d.cpu_max_7d.toFixed(2) + ' load' : '—'));

        // Memory — show percentage with bytes in parentheses
        var memNow = d.mem_pct_now >= 0 ? d.mem_pct_now + '%' : '—';
        if (d.mem_used_now >= 0 && d.mem_total > 0) { memNow += ' (' + healthFormatBytes(d.mem_used_now) + ' / ' + healthFormatBytes(d.mem_total) + ')'; }
        $('#hm-mem-now').text(memNow);
        $('#hm-mem-24h').text(d.mem_pct_max_24h >= 0 ? d.mem_pct_max_24h + '%' : (d.mem_max_24h >= 0 ? healthFormatBytes(d.mem_max_24h) : '—'));
        $('#hm-mem-7d').text(d.mem_pct_max_7d >= 0 ? d.mem_pct_max_7d + '%' : (d.mem_max_7d >= 0 ? healthFormatBytes(d.mem_max_7d) : '—'));

        // Max resource
        if (d.max_resource_now !== undefined) {
            var resText = d.max_resource_now >= 0 ? d.max_resource_now + '% now' : '';
            if (d.max_resource_24h >= 0) { resText += (resText ? ' | ' : '') + d.max_resource_24h + '% peak 24h'; }
            if (d.max_resource_7d >= 0) { resText += (resText ? ' | ' : '') + d.max_resource_7d + '% peak 7d'; }
            if (resText) { $('#hm-cpu-7d').closest('.csc-health-metric').after('<div class="csc-health-metric" style="grid-column:1/-1"><div class="csc-health-metric-label">Max Resource (higher of CPU/Mem)</div><div class="csc-health-metric-value">' + resText + '</div></div>'); }
        }

        // Data status
        $('#hm-hourly-count').text(d.hourly_count);
        $('#hm-weekly-count').text(d.weekly_count);
        $('#hm-last-hourly').text(d.last_hourly || 'Never');
        $('#hm-last-weekly').text(d.last_weekly || 'Never');
        $('#hm-data-span').text(d.weeks_of_data > 0 ? d.weeks_of_data : '0');

        $('#csc-health-loading').hide();
        $('#csc-health-content').show();
    }

    function healthLoad() {
        $.post(CSC.ajax_url, { action: 'csc_health_get', nonce: CSC.nonce }, function(resp) {
            if (resp.success) { healthRenderData(resp.data); }
            else { $('#csc-health-loading').text('Failed to load health data.'); }
        }).fail(function() { $('#csc-health-loading').text('Network error loading health data.'); });
    }

    // Load health data when tab is shown
    var healthLoaded = false;
    $(document).on('click', '.csc-tab[data-tab="site-health"]', function() {
        if (!healthLoaded) { healthLoad(); healthLoaded = true; }
    });

    // Load on page load: site-health is now the default tab, or if URL specifies it
    if (window.location.search.indexOf('tab=site-health') !== -1 || !window.location.search.match(/tab=/)) {
        healthLoad(); healthLoaded = true;
    }

    $('#btn-health-refresh').on('click', function() {
        $(this).prop('disabled', true).html('⏳ Loading…');
        $.post(CSC.ajax_url, { action: 'csc_health_get', nonce: CSC.nonce }, function(resp) {
            $('#btn-health-refresh').prop('disabled', false).html('🔄 Refresh');
            if (resp.success) { healthRenderData(resp.data); }
        }).fail(function() { $('#btn-health-refresh').prop('disabled', false).html('🔄 Refresh'); });
    });

    $('#btn-health-collect').on('click', function() {
        $(this).prop('disabled', true).html('⏳ Collecting…');
        $.post(CSC.ajax_url, { action: 'csc_health_collect_now', nonce: CSC.nonce }, function(resp) {
            $('#btn-health-collect').prop('disabled', false).html('📊 Collect Now');
            if (resp.success && resp.data.health) { healthRenderData(resp.data.health); }
        }).fail(function() { $('#btn-health-collect').prop('disabled', false).html('📊 Collect Now'); });
    });


    // Sysstat test
    $('#btn-sysstat-test').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('⏳ Testing...');
        var $box = $('#csc-sysstat-status');
        $box.show();
        $('#csc-sysstat-label').text('Testing sysstat...');
        $('#csc-sysstat-icon').text('⏳');
        $('#csc-sysstat-detail').text('');
        $('#csc-sysstat-instructions').hide();

        $.post(CSC.ajax_url, { action: 'csc_health_sysstat_test', nonce: CSC.nonce }, function(resp) {
            $btn.prop('disabled', false).html('🔧 Test Sysstat');
            if (!resp.success) {
                $('#csc-sysstat-icon').text('❌');
                $('#csc-sysstat-label').text('Test failed');
                $box.css({ background: '#fef2f2', borderColor: '#fecaca' });
                return;
            }
            var d = resp.data;
            if (!d.exec_available) {
                $('#csc-sysstat-icon').text('❌');
                $('#csc-sysstat-label').text('exec() disabled');
                $box.css({ background: '#fef2f2', borderColor: '#fecaca' });
            } else if (!d.sar_installed) {
                $('#csc-sysstat-icon').text('❌');
                $('#csc-sysstat-label').text('sysstat not installed');
                $box.css({ background: '#fef2f2', borderColor: '#fecaca' });
            } else if (!d.sysstat_active) {
                $('#csc-sysstat-icon').text('⚠️');
                $('#csc-sysstat-label').text('sysstat installed but service inactive');
                $('#csc-sysstat-detail').text(d.sar_version + ' at ' + d.sar_path);
                $box.css({ background: '#fffbeb', borderColor: '#fde68a' });
            } else if (!d.sar_has_data) {
                $('#csc-sysstat-icon').text('⚠️');
                $('#csc-sysstat-label').text('sysstat active, no data yet');
                $('#csc-sysstat-detail').text(d.sar_version + ' — wait 10 minutes for first samples');
                $box.css({ background: '#fffbeb', borderColor: '#fde68a' });
            } else {
                $('#csc-sysstat-icon').text('✅');
                $('#csc-sysstat-label').text('sysstat working');
                $('#csc-sysstat-detail').text(d.sar_version + ' | ' + d.sar_samples + ' samples/hr | CPU ' + d.cpu_pct_now + '% | Mem ' + d.mem_pct_now + '%');
                $box.css({ background: '#f0fdf4', borderColor: '#bbf7d0' });
            }
            if (d.instructions) {
                $('#csc-sysstat-instructions').text(d.instructions).show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).html('🔧 Test Sysstat');
            $('#csc-sysstat-icon').text('❌');
            $('#csc-sysstat-label').text('Network error');
            $box.css({ background: '#fef2f2', borderColor: '#fecaca' });
        });
    });

    }); // document.ready

}(jQuery));
