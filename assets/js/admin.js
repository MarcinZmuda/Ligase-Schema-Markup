(function($) {
    'use strict';

    // =========================================================================
    // i18n helper — delegates to wp.i18n.__ when available, falls back to identity.
    // Source strings are currently Polish (legacy); the wp.i18n integration is wired
    // so future English-source migration produces working translations without touching
    // call sites. Compile translations with: `wp i18n make-json languages/`.
    // =========================================================================

    var __ = (window.wp && window.wp.i18n && window.wp.i18n.__)
        ? function(text) { return window.wp.i18n.__(text, 'ligase'); }
        : function(text) { return text; };

    var sprintf = (window.wp && window.wp.i18n && window.wp.i18n.sprintf)
        ? window.wp.i18n.sprintf
        : function(fmt) {
            var args = Array.prototype.slice.call(arguments, 1);
            var i = 0;
            return fmt.replace(/%[sd]/g, function() { return args[i++]; });
        };

    // =========================================================================
    // API Layer
    // =========================================================================

    window.LigaseAPI = {
        request: function(action, data, callback) {
            var payload = $.extend({
                action: action,
                nonce: LIGASE.nonce
            }, data || {});

            $.ajax({
                url: LIGASE.ajaxUrl,
                type: 'POST',
                data: payload,
                success: function(response) {
                    if (response.success) {
                        if (callback) callback(null, response.data);
                    } else {
                        var msg = (response.data && response.data.message) || 'Unknown error';
                        if (callback) callback(msg, null);
                    }
                },
                error: function(xhr) {
                    if (callback) callback(xhr.statusText || 'Request failed', null);
                }
            });
        },

        getDashboardStats: function(cb) { this.request('ligase_dashboard_stats', {}, cb); },
        getReadinessScore: function(cb) { this.request('ligase_get_readiness_score', {}, cb); },
        scanPost: function(postId, cb) { this.request('ligase_scan_post', {post_id: postId}, cb); },
        scanAllPosts: function(cb) { this.request('ligase_scan_all_posts', {}, cb); },
        fixPost: function(postId, cb) { this.request('ligase_fix_post', {post_id: postId}, cb); },
        fixAllPosts: function(threshold, cb) { this.request('ligase_fix_all_posts', {threshold: threshold}, cb); },
        previewJson: function(postId, cb) { this.request('ligase_preview_json', {post_id: postId}, cb); },
        applyReplacements: function(postIds, mode, cb) { this.request('ligase_apply_audit_replacements', {post_ids: postIds, mode: mode}, cb); },
        searchWikidata: function(name, cb) { this.request('ligase_wikidata', {name: name}, cb); },
        getAuthorScores: function(cb) { this.request('ligase_get_author_scores', {}, cb); },
        getPluginConflicts: function(cb) { this.request('ligase_get_plugin_conflicts', {}, cb); },
        exportSettings: function(cb) { this.request('ligase_export_settings', {}, cb); },
        importSettings: function(jsonData, cb) { this.request('ligase_import_settings', {json_data: jsonData}, cb); },
        autoRepair: function(repairs, cb) { this.request('ligase_auto_repair', {repairs: repairs}, cb); },
        clearCache: function(cb) { this.request('ligase_clear_cache', {}, cb); },
        detectImportSources: function(cb) { this.request('ligase_detect_import_sources', {}, cb); },
        runImport: function(source, cb) { this.request('ligase_run_import', {source: source}, cb); },
        validatePost: function(postId, cb) { this.request('ligase_validate_post', {post_id: postId}, cb); },
        runHealthReport: function(cb) { this.request('ligase_run_health_report', {}, cb); },
        gscSaveCredentials: function(json, siteUrl, cb) { this.request('ligase_gsc_save_credentials', {service_account_json: json, site_url: siteUrl}, cb); },
        gscDisconnect: function(cb) { this.request('ligase_gsc_disconnect', {}, cb); },
        gscTestConnection: function(cb) { this.request('ligase_gsc_test_connection', {}, cb); },
        gscSync: function(cb) { this.request('ligase_gsc_sync', {}, cb); },
        gscRichResults: function(cb) { this.request('ligase_gsc_rich_results', {}, cb); },
    };

    // =========================================================================
    // Helpers
    // =========================================================================

    function showNotice($container, message, type) {
        type = type || 'success';
        $container.html(message).attr('class', 'ligase-notice ligase-notice-' + type).show();
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    function initDashboard() {
        var $loading = $('#ligase-stats-loading');

        LigaseAPI.getDashboardStats(function(err, data) {
            if (err || !data) {
                $loading.text(__('Nie udało się załadować statystyk.'));
                return;
            }
            $loading.hide();

            // Update stat values if they show placeholder
            $('.ligase-stat-green').each(function() {
                if ($(this).text() === '—') $(this).text(data.complete || 0);
            });
            $('.ligase-stat-yellow').each(function() {
                if ($(this).text() === '—') $(this).text(data.warnings || 0);
            });
            $('.ligase-stat-red').each(function() {
                if ($(this).text() === '—') $(this).text(data.missing || 0);
            });
        });

        // GSC Connect
        $('#ligase-gsc-connect').on('click', function() {
            var json = $('#ligase-gsc-json').val().trim();
            var siteUrl = $('#ligase-gsc-site-url').val().trim();
            if (!json) { alert(__('Wklej Service Account JSON.')); return; }

            var $btn = $(this).prop('disabled', true).text(__('Łączenie...'));
            LigaseAPI.gscSaveCredentials(json, siteUrl, function(err, data) {
                $btn.prop('disabled', false).text(__('Połącz'));
                if (err) {
                    alert(sprintf(__('Błąd: %s'), err));
                } else {
                    location.reload();
                }
            });
        });

        // GSC Sync
        $('#ligase-gsc-sync').on('click', function() {
            var $btn = $(this).prop('disabled', true).text(__('Synchronizacja...'));
            LigaseAPI.gscSync(function(err, data) {
                $btn.prop('disabled', false).text(__('Synchronizuj dane'));
                if (err) { alert(sprintf(__('Błąd: %s'), err)); }
                else { alert(data.message || __('Gotowe.')); }
            });
        });

        // GSC Rich Results
        $('#ligase-gsc-rich-results').on('click', function() {
            var $btn = $(this).prop('disabled', true).text('Ladowanie...');
            var $container = $('#ligase-gsc-data');

            LigaseAPI.gscRichResults(function(err, data) {
                $btn.prop('disabled', false).text('Pokaz rich results');
                if (err) { $container.html('<p class="ligase-notice ligase-notice-error">' + err + '</p>'); return; }

                var rows = data.rows || [];
                if (!rows.length) { $container.html('<p>Brak danych rich results w ostatnich 28 dniach.</p>'); return; }

                var html = '<table class="wp-list-table widefat fixed striped" style="margin-top:12px;">';
                html += '<thead><tr><th>Strona</th><th>Typ</th><th>Klikniecia</th><th>Wyswietlenia</th><th>CTR</th><th>Pozycja</th></tr></thead><tbody>';

                rows.forEach(function(row) {
                    var page = row.keys[0] || '';
                    var appearance = row.keys[1] || '';
                    var shortPage = page.replace(/^https?:\/\/[^\/]+/, '');
                    html += '<tr>';
                    html += '<td title="' + page + '">' + (shortPage.length > 50 ? shortPage.substr(0,50) + '...' : shortPage) + '</td>';
                    html += '<td><code>' + appearance + '</code></td>';
                    html += '<td>' + (row.clicks || 0) + '</td>';
                    html += '<td>' + (row.impressions || 0) + '</td>';
                    html += '<td>' + ((row.ctr || 0) * 100).toFixed(1) + '%</td>';
                    html += '<td>' + (row.position || 0).toFixed(1) + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                $container.html(html);
            });
        });

        $('#ligase-refresh-stats').on('click', function() {
            var $btn = $(this).prop('disabled', true).text('Odswiezanie...');
            LigaseAPI.getDashboardStats(function(err, data) {
                $btn.prop('disabled', false).text('Odswiez statystyki');
                if (!err && data) {
                    location.reload();
                }
            });
        });
    }

    // =========================================================================
    // Posts
    // =========================================================================

    function initPosts() {
        var $notice = $('#ligase-posts-notice');

        // Scan single post
        $(document).on('click', '.ligase-btn-scan', function() {
            var $btn = $(this);
            var postId = $btn.data('post-id');
            $btn.prop('disabled', true).text('...');

            LigaseAPI.scanPost(postId, function(err, data) {
                $btn.prop('disabled', false).text('Skanuj');
                if (err) {
                    showNotice($notice, 'Blad skanowania: ' + err, 'error');
                } else {
                    showNotice($notice, 'Post #' + postId + ' zeskanowany.', 'success');
                }
            });
        });

        // Fix single post
        $(document).on('click', '.ligase-btn-fix', function() {
            var $btn = $(this);
            var postId = $btn.data('post-id');
            $btn.prop('disabled', true).text('...');

            LigaseAPI.fixPost(postId, function(err, data) {
                $btn.prop('disabled', false).text('Napraw');
                if (err) {
                    showNotice($notice, 'Blad naprawy: ' + err, 'error');
                } else {
                    showNotice($notice, 'Post #' + postId + ' naprawiony.', 'success');
                }
            });
        });

        // Bulk checkboxes
        $('#ligase-posts-check-all').on('change', function() {
            $('.ligase-post-check').prop('checked', $(this).prop('checked'));
            toggleBulkBtn();
        });
        $(document).on('change', '.ligase-post-check', toggleBulkBtn);

        function toggleBulkBtn() {
            var count = $('.ligase-post-check:checked').length;
            $('#ligase-bulk-fix').toggle(count > 0).text('Napraw zaznaczone (' + count + ')');
        }

        // Bulk fix selected
        $('#ligase-bulk-fix').on('click', function() {
            var postIds = [];
            $('.ligase-post-check:checked').each(function() { postIds.push($(this).val()); });
            if (!postIds.length) return;

            var $btn = $(this).prop('disabled', true).text('Naprawiam...');
            var idx = 0;
            var fixed = 0;

            function fixNext() {
                if (idx >= postIds.length) {
                    $btn.prop('disabled', false);
                    toggleBulkBtn();
                    showNotice($notice, 'Naprawiono ' + fixed + '/' + postIds.length + ' postow.', 'success');
                    return;
                }
                LigaseAPI.fixPost(postIds[idx], function(err) {
                    if (!err) fixed++;
                    idx++;
                    fixNext();
                });
            }
            fixNext();
        });

        // Preview JSON-LD
        $(document).on('click', '.ligase-btn-preview', function() {
            var postId = $(this).data('post-id');
            var $modal = $('#ligase-json-modal');
            var $output = $('#ligase-json-output');

            $output.text('Ladowanie...');
            $modal.show();

            LigaseAPI.previewJson(postId, function(err, data) {
                if (err) {
                    $output.text('Blad: ' + err);
                } else {
                    $output.text(data.json || 'Brak danych');
                }
            });
        });

        // Close modal
        $(document).on('click', '.ligase-modal-close', function() {
            $(this).closest('.ligase-modal').hide();
        });
        $(document).on('click', '.ligase-modal', function(e) {
            if (e.target === this) $(this).hide();
        });

        // Scan all
        $('#ligase-scan-all').on('click', function() {
            var $btn = $(this).prop('disabled', true).text('Skanowanie...');
            LigaseAPI.scanAllPosts(function(err, data) {
                $btn.prop('disabled', false).text('Skanuj wszystkie');
                if (err) {
                    showNotice($notice, 'Blad: ' + err, 'error');
                } else {
                    var count = data ? Object.keys(data).length : 0;
                    showNotice($notice, 'Zeskanowano ' + count + ' postow. Odswiez strone, aby zobaczyc wyniki.', 'success');
                }
            });
        });

        // Fix all below threshold
        $('#ligase-fix-all').on('click', function() {
            var threshold = $(this).data('threshold') || 50;
            var $btn = $(this).prop('disabled', true).text('Naprawa...');
            LigaseAPI.fixAllPosts(threshold, function(err, data) {
                $btn.prop('disabled', false).text('Napraw ponizej 50 pkt');
                if (err) {
                    showNotice($notice, 'Blad: ' + err, 'error');
                } else {
                    showNotice($notice, 'Naprawiono: ' + (data.fixed || 0) + ', bledy: ' + (data.failed || 0), 'success');
                }
            });
        });
    }

    // =========================================================================
    // Auditor
    // =========================================================================

    function initAuditor() {
        $('#ligase-run-audit').on('click', function() {
            var $btn = $(this).prop('disabled', true);
            var $status = $('#ligase-audit-status').text('Skanowanie...');
            var $results = $('#ligase-audit-results');

            LigaseAPI.scanAllPosts(function(err, data) {
                $btn.prop('disabled', false);
                $status.text('');

                if (err) {
                    $status.text('Blad: ' + err);
                    return;
                }

                var threshold = parseInt($('#ligase-audit-threshold').val()) || 50;
                var $tbody = $('#ligase-audit-table tbody').empty();
                var $summary = $('#ligase-audit-summary');
                var below = 0, above = 0;

                $.each(data, function(postId, result) {
                    var score = result.score || 0;
                    if (score < threshold) below++;
                    else above++;

                    var scoreClass = score >= 70 ? 'ligase-score-good' : (score >= 40 ? 'ligase-score-warn' : 'ligase-score-bad');
                    var issues = (result.issues || []).join(', ') || '—';

                    $tbody.append(
                        '<tr>' +
                        '<td><input type="checkbox" class="ligase-audit-item" value="' + postId + '" ' + (score < threshold ? 'checked' : '') + ' /></td>' +
                        '<td>Post #' + postId + '</td>' +
                        '<td><span class="ligase-score-badge ' + scoreClass + '">' + score + '</span></td>' +
                        '<td>' + issues + '</td>' +
                        '<td>' + (result.source_plugin || '—') + '</td>' +
                        '</tr>'
                    );
                });

                $summary.html(
                    '<div class="ligase-stat"><span class="ligase-stat-value ligase-stat-red">' + below + '</span><span class="ligase-stat-label">Ponizej progu</span></div>' +
                    '<div class="ligase-stat"><span class="ligase-stat-value ligase-stat-green">' + above + '</span><span class="ligase-stat-label">Powyzej progu</span></div>'
                );

                $results.show();
            });
        });

        // Check all
        $('#ligase-audit-check-all').on('change', function() {
            var checked = $(this).prop('checked');
            $('.ligase-audit-item').prop('checked', checked);
        });

        // Apply audit fixes
        $('#ligase-apply-audit').on('click', function() {
            var postIds = [];
            $('.ligase-audit-item:checked').each(function() {
                postIds.push($(this).val());
            });

            if (!postIds.length) {
                alert('Zaznacz posty do naprawy.');
                return;
            }

            var mode = $('#ligase-audit-mode').val();
            var $btn = $(this).prop('disabled', true).text('Stosowanie...');

            LigaseAPI.applyReplacements(postIds, mode, function(err, data) {
                $btn.prop('disabled', false).text('Zastosuj naprawy dla zaznaczonych');
                if (err) {
                    alert('Blad: ' + err);
                } else {
                    var results = data.results || [];
                    var success = results.filter(function(r) { return r.success; }).length;
                    alert('Zastosowano: ' + success + '/' + results.length);
                }
            });
        });
    }

    // =========================================================================
    // Entities
    // =========================================================================

    function initEntities() {
        $('#ligase-wikidata-btn').on('click', function() {
            var query = $('#ligase-wikidata-query').val().trim();
            if (!query) return;

            var $results = $('#ligase-wikidata-results').html('<p class="ligase-loading">Szukanie...</p>');

            LigaseAPI.searchWikidata(query, function(err, data) {
                if (err) {
                    $results.html('<p class="ligase-notice ligase-notice-error">Blad: ' + err + '</p>');
                    return;
                }

                var matches = data.matches || [];
                if (!matches.length) {
                    $results.html('<p>Brak wynikow dla "' + $('<span>').text(query).html() + '".</p>');
                    return;
                }

                var html = '';
                $.each(matches, function(i, m) {
                    html += '<div class="ligase-wikidata-result">';
                    html += '<strong>' + $('<span>').text(m.label || m.id).html() + '</strong>';
                    if (m.description) {
                        html += '<div class="description">' + $('<span>').text(m.description).html() + '</div>';
                    }
                    html += '<div class="ligase-wikidata-url">' + $('<span>').text(m.url || '').html() + '</div>';
                    html += '</div>';
                });

                $results.html(html);
            });
        });

        // Allow Enter to trigger search
        $('#ligase-wikidata-query').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#ligase-wikidata-btn').click();
            }
        });
    }

    // =========================================================================
    // Tools
    // =========================================================================

    function initTools() {
        var $notice = $('#ligase-tools-notice');

        // Import from SEO plugins
        $(document).on('click', '.ligase-import-seo-btn', function() {
            var $btn = $(this).prop('disabled', true).text('Importowanie...');
            var source = $btn.data('source');
            var $result = $('#ligase-import-seo-result');

            LigaseAPI.runImport(source, function(err, data) {
                $btn.prop('disabled', false).text('Importuj');
                if (err) {
                    $result.html('<div class="ligase-notice ligase-notice-error">' + err + '</div>');
                } else {
                    var html = '<div class="ligase-notice ligase-notice-success">';
                    html += 'Zaimportowano: ' + (data.imported || 0) + ', pominieto: ' + (data.skipped || 0);
                    if (data.details && data.details.length) {
                        html += '<ul style="margin:8px 0 0;padding-left:16px;">';
                        data.details.forEach(function(d) { html += '<li>' + $('<span>').text(d).html() + '</li>'; });
                        html += '</ul>';
                    }
                    html += '</div>';
                    $result.html(html);
                }
            });
        });

        // Schema validator
        $('#ligase-validate-btn').on('click', function() {
            var postId = $('#ligase-validate-post-id').val();
            if (!postId) { showNotice($notice, 'Wpisz ID posta.', 'warning'); return; }

            var $btn = $(this).prop('disabled', true).text('Walidacja...');
            var $result = $('#ligase-validate-result');

            LigaseAPI.validatePost(postId, function(err, data) {
                $btn.prop('disabled', false).text('Waliduj');
                if (err) { showNotice($notice, 'Blad: ' + err, 'error'); return; }

                $result.show();

                // Summary
                var types = (data.types || []).join(', ');
                var status = data.valid
                    ? '<span class="ligase-badge ligase-badge-pass">Schema poprawna</span>'
                    : '<span class="ligase-badge ligase-badge-fail">Znaleziono bledy</span>';
                $('#ligase-validate-summary').html(status + ' &mdash; Typy: ' + types);

                // Errors
                var errHtml = '';
                (data.errors || []).forEach(function(e) {
                    errHtml += '<div class="ligase-notice ligase-notice-error" style="margin:4px 0;">' + $('<span>').text(e).html() + '</div>';
                });
                $('#ligase-validate-errors').html(errHtml);

                // Warnings
                var warnHtml = '';
                (data.warnings || []).forEach(function(w) {
                    warnHtml += '<div class="ligase-notice ligase-notice-warning" style="margin:4px 0;">' + $('<span>').text(w).html() + '</div>';
                });
                $('#ligase-validate-warnings').html(warnHtml);

                // JSON preview
                $('#ligase-validate-json').text(data.json || '');
            });
        });

        // Health report
        $('#ligase-send-health-report').on('click', function() {
            var $btn = $(this).prop('disabled', true).text('Wysylanie...');
            LigaseAPI.runHealthReport(function(err, data) {
                $btn.prop('disabled', false).text('Wyslij raport teraz');
                if (err) {
                    showNotice($notice, 'Blad: ' + err, 'error');
                } else {
                    showNotice($notice, data.message || 'Raport wyslany.', 'success');
                }
            });
        });

        // Auto-repair
        $('#ligase-run-repair').on('click', function() {
            var repairs = [];
            $('input[name="ligase_repair[]"]:checked').each(function() {
                repairs.push($(this).val());
            });

            if (!repairs.length) {
                showNotice($notice, 'Zaznacz przynajmniej jedna naprawe.', 'warning');
                return;
            }

            var $btn = $(this).prop('disabled', true);
            var $status = $('#ligase-repair-status').text('Naprawiam...');

            LigaseAPI.autoRepair(repairs, function(err, data) {
                $btn.prop('disabled', false);
                $status.text('');

                if (err) {
                    showNotice($notice, 'Blad: ' + err, 'error');
                } else {
                    showNotice($notice, 'Przetworzone: ' + (data.processed || 0) + ', naprawione: ' + (data.fixed || 0) + ', bledy: ' + (data.errors || 0), 'success');
                }
            });
        });

        // Clear cache
        $('#ligase-clear-cache').on('click', function() {
            var $btn = $(this).prop('disabled', true).text('Czyszczenie...');
            LigaseAPI.clearCache(function(err) {
                $btn.prop('disabled', false).text('Wyczysc cache schema');
                if (err) {
                    showNotice($notice, 'Blad: ' + err, 'error');
                } else {
                    showNotice($notice, 'Cache wyczyszczony.', 'success');
                }
            });
        });

        // Export
        $('#ligase-export-btn').on('click', function() {
            var $btn = $(this).prop('disabled', true).text('Eksportowanie...');
            LigaseAPI.exportSettings(function(err, data) {
                $btn.prop('disabled', false).text('Eksportuj ustawienia');
                if (err) {
                    showNotice($notice, 'Blad eksportu: ' + err, 'error');
                } else {
                    // Trigger download
                    var blob = new Blob([data.json], {type: 'application/json'});
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'ligase-settings-' + new Date().toISOString().slice(0, 10) + '.json';
                    a.click();
                    URL.revokeObjectURL(url);
                    showNotice($notice, 'Ustawienia wyeksportowane.', 'success');
                }
            });
        });

        // Import
        $('#ligase-import-btn').on('click', function() {
            var jsonData = $('#ligase-import-json').val().trim();
            if (!jsonData) {
                showNotice($notice, 'Wklej dane JSON przed importem.', 'warning');
                return;
            }

            if (!confirm('Czy na pewno chcesz nadpisac aktualne ustawienia?')) return;

            var $btn = $(this).prop('disabled', true).text('Importowanie...');
            LigaseAPI.importSettings(jsonData, function(err, data) {
                $btn.prop('disabled', false).text('Importuj ustawienia');
                if (err) {
                    showNotice($notice, 'Blad importu: ' + err, 'error');
                } else {
                    showNotice($notice, data.message || 'Import zakonczony.', 'success');
                }
            });
        });
    }

    // =========================================================================
    // Metabox
    // =========================================================================

    function initMetabox() {
        var $scoreEl = $('#ligase-meta-score');
        if (!$scoreEl.length) return;

        var postId = $('#post_ID').val();
        if (!postId) return;

        LigaseAPI.request('ligase_scan_post', { post_id: postId }, function(err, data) {
            if (err || !data) return;
            $scoreEl.html('<strong>' + (data.score || '—') + '/100</strong>');
        });
    }

    // =========================================================================
    // Router — initialize the right module based on page slug
    // =========================================================================

    $(document).ready(function() {
        // Detect current page from URL parameter
        var urlParams = new URLSearchParams(window.location.search);
        var page = urlParams.get('page') || '';

        switch (page) {
            case 'ligase':
                initDashboard();
                break;
            case 'ligase-posty':
                initPosts();
                break;
            case 'ligase-audytor':
                initAuditor();
                break;
            case 'ligase-encje':
                initEntities();
                break;
            case 'ligase-narzedzia':
                initTools();
                break;
        }

        // Always init metabox on post edit screens
        initMetabox();
    });

})(jQuery);
