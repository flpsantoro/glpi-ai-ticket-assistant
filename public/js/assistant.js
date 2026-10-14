/**
 * GLPI AI Ticket Assistant — ticket form integration.
 *
 *  - "AI assistant" button (timeline footer) -> modal with the analysis
 *    (summary, technical strategy), the reply draft, the classification and
 *    the knowledge base article;
 *  - "Improve my text (AI)" buttons rendered by the server in the followup
 *    and solution forms.
 *
 * Works on GLPI 10, 11 and 12: no build step, only jQuery, Bootstrap 5 and
 * TinyMCE, which every version ships. Answer forms can be lazy loaded
 * (GLPI 11.0.9+): editors are awaited instead of assumed.
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 */
/* global bootstrap, tinymce, setRichTextEditorContent, glpi_toast_info, glpi_toast_error */

(function ($) {
    'use strict';

    var MODAL_ID = 'ticketassistant-modal';
    var BLOCKS = { followup: 'new-ITILFollowup-block', solution: 'new-ITILSolution-block' };
    var NO_RETRY = ['content_filter', 'forbidden', 'disabled', 'blocked_budget', 'blocked_rate', 'not_solved', 'session', 'quota', 'auth', 'config'];

    var settings = null;
    var state = { results: {}, pending: {} };

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    // The timeline can be rendered after document ready: read lazily
    function getSettings() {
        if (settings) {
            return settings;
        }
        var node = document.querySelector('script.ticketassistant-settings');
        if (!node) {
            return null;
        }
        try {
            settings = JSON.parse(node.textContent || '{}');
        } catch (e) {
            settings = null;
        }
        return settings;
    }

    function t(key) {
        var s = getSettings();
        return (s && s.i18n && s.i18n[key]) || key;
    }

    function esc(text) {
        return $('<div>').text(text === null || text === undefined ? '' : String(text)).html();
    }

    // sprintf subset: %s, %d, %1$s
    function format(pattern) {
        var args = Array.prototype.slice.call(arguments, 1);
        var next = 0;
        return String(pattern).replace(/%(?:(\d+)\$)?[sd]/g, function (match, position) {
            var index = position ? parseInt(position, 10) - 1 : next++;
            return args[index] !== undefined ? args[index] : match;
        });
    }

    // glpi_toast_* are global "const" declarations, not window properties
    function toast(message, isError) {
        if (isError && typeof glpi_toast_error === 'function') {
            glpi_toast_error(message, t('title'));
        } else if (typeof glpi_toast_info === 'function') {
            glpi_toast_info(message, t('title'));
        }
    }

    // GLPI adds the X-Glpi-Csrf-Token header to jQuery POST requests
    function post(url, data) {
        return $.ajax({ url: url, method: 'POST', data: data, dataType: 'json', timeout: 600000 })
            .then(
                function (response) {
                    return response || { success: false, message: t('unknown_error') };
                },
                function (xhr) {
                    var payload = xhr.responseJSON || {};
                    if (!payload.message) {
                        payload = {
                            success: false,
                            code: xhr.status === 403 ? 'forbidden' : 'http',
                            message: xhr.status === 403 ? t('forbidden') : format(t('http_error'), xhr.status || 0)
                        };
                    }
                    payload.success = false;
                    return $.Deferred().resolve(payload).promise();
                }
            );
    }

    function action(name, data) {
        var s = getSettings();
        return post(s.ajaxUrl, $.extend({ action: name, tickets_id: s.ticketsId }, data || {}));
    }

    function loading(message) {
        return '<div class="ticketassistant-loading text-center py-5">'
            + '<div class="spinner-border text-primary mb-3" role="status"></div>'
            + '<div class="fw-bold">' + esc(message) + '</div>'
            + '<div class="text-muted">' + esc(t('loading_hint')) + '</div>'
            + '</div>';
    }

    function errorBox(data, retry) {
        var blocked = data.code && data.code.indexOf('blocked') === 0;
        var html = '<div class="alert alert-warning d-flex align-items-center" role="alert">'
            + '<i class="ti ' + (blocked ? 'ti-hourglass' : 'ti-alert-triangle') + ' me-2 fs-2"></i>'
            + '<div>' + esc(data.message || t('unknown_error')) + '</div></div>';
        if (retry && NO_RETRY.indexOf(data.code) === -1) {
            html += '<div class="text-center"><button type="button" class="btn btn-outline-primary" data-ticketassistant-run="' + retry + '">'
                + '<i class="ti ti-refresh me-1"></i>' + esc(t('retry')) + '</button></div>';
        }
        return html;
    }

    function metaLine(meta) {
        if (!meta) {
            return '';
        }
        var parts = [];
        if (meta.model) {
            parts.push(esc(meta.model));
        }
        if (meta.tokens) {
            parts.push(esc(meta.tokens) + ' ' + esc(t('tokens')));
        }
        if (meta.cost_usd && meta.cost_usd !== '0.0000') {
            parts.push('US$ ' + esc(meta.cost_usd));
        }
        if (meta.duration_ms) {
            parts.push((meta.duration_ms / 1000).toFixed(1) + ' s');
        }
        if (meta.images_count) {
            parts.push(esc(meta.images_count) + ' ' + esc(t(Number(meta.images_count) === 1 ? 'image' : 'images')));
        }
        // Names of the documents read, or only their number for an analysis shown again
        var documents = meta.documents && meta.documents.length ? meta.documents.length : Number(meta.documents_count || 0);
        if (documents) {
            parts.push(esc(documents) + ' ' + esc(t(documents === 1 ? 'document' : 'documents')));
        }
        return '<small class="text-muted">' + parts.join(' · ') + '</small>';
    }

    function feedbackButtons(logId) {
        if (!logId) {
            return '';
        }
        return '<div class="btn-group btn-group-sm ticketassistant-feedback" data-log-id="' + esc(logId) + '">'
            + '<button type="button" class="btn btn-outline-success" data-value="1" title="' + esc(t('useful')) + '"><i class="ti ti-thumb-up"></i></button>'
            + '<button type="button" class="btn btn-outline-danger" data-value="-1" title="' + esc(t('not_useful')) + '"><i class="ti ti-thumb-down"></i></button>'
            + '</div>';
    }

    // ------------------------------------------------------------------
    // Editors (inline forms or forms loaded on demand)
    // ------------------------------------------------------------------

    function readyEditor(container) {
        if (!container || typeof tinymce === 'undefined') {
            return null;
        }
        var textarea = container.querySelector('textarea[name="content"]');
        if (!textarea || !textarea.id) {
            return null;
        }
        var editor = tinymce.get(textarea.id);
        return editor && editor.initialized ? editor : null;
    }

    function whenEditorReady(container, timeoutMs) {
        return new Promise(function (resolve, reject) {
            var editor = readyEditor(container);
            if (editor) {
                resolve(editor);
                return;
            }
            var done = false;
            var observer = null;
            var poll = null;
            var timer = null;

            function cleanup() {
                if (observer) {
                    observer.disconnect();
                }
                window.clearInterval(poll);
                window.clearTimeout(timer);
            }

            function finish(found) {
                if (!done && found) {
                    done = true;
                    cleanup();
                    resolve(found);
                }
            }

            if (window.MutationObserver) {
                observer = new MutationObserver(function () {
                    finish(readyEditor(container));
                });
                observer.observe(container, { childList: true, subtree: true });
            }
            poll = window.setInterval(function () {
                finish(readyEditor(container));
            }, 200);
            timer = window.setTimeout(function () {
                if (!done) {
                    done = true;
                    cleanup();
                    reject(new Error('editor timeout'));
                }
            }, timeoutMs || 15000);
        });
    }

    function setEditorContent(editor, html) {
        var apply = function () {
            if (typeof setRichTextEditorContent === 'function') {
                setRichTextEditorContent(editor.id, html);
            } else {
                editor.setContent(html);
            }
        };
        if (editor.undoManager && typeof editor.undoManager.transact === 'function') {
            editor.undoManager.transact(apply);
        } else {
            apply();
        }
        if (typeof editor.save === 'function') {
            editor.save();
        }
        if (typeof editor.dispatch === 'function') {
            editor.dispatch('change');
        } else if (typeof editor.fire === 'function') {
            editor.fire('change');
        }
    }

    // Opening the collapse block is what loads the form on GLPI 11.0.9+
    function openAnswerBlock(kind) {
        var block = document.getElementById(BLOCKS[kind]);
        if (!block) {
            return Promise.reject(new Error('no block'));
        }
        bootstrap.Collapse.getOrCreateInstance(block, { toggle: false }).show();
        return whenEditorReady(block, 15000).then(function (editor) {
            return { editor: editor, block: block };
        });
    }

    // ------------------------------------------------------------------
    // Modal
    // ------------------------------------------------------------------

    function panes() {
        var s = getSettings();
        var list = [];
        if (s.actions.analyze) {
            list.push(['analyze', 'ti-route', t('tab_analysis')]);
            list.push(['reply', 'ti-message-2', t('tab_reply')]);
        }
        if (s.actions.classify) {
            list.push(['classify', 'ti-tag', t('tab_classify')]);
        }
        if (s.actions.article) {
            list.push(['article', 'ti-book', t('tab_article')]);
        }
        return list;
    }

    function ensureModal() {
        var modal = document.getElementById(MODAL_ID);
        if (modal) {
            return modal;
        }
        var s = getSettings();
        var nav = '';
        var content = '';
        panes().forEach(function (pane, index) {
            nav += '<li class="nav-item" role="presentation"><button type="button" class="nav-link' + (index === 0 ? ' active' : '') + '"'
                + ' data-bs-toggle="tab" data-bs-target="#ticketassistant-pane-' + pane[0] + '" data-ticketassistant-pane="' + pane[0] + '" role="tab">'
                + '<i class="ti ' + pane[1] + ' me-1"></i>' + esc(pane[2]) + '</button></li>';
            content += '<div class="tab-pane fade' + (index === 0 ? ' show active' : '') + '" id="ticketassistant-pane-' + pane[0] + '" role="tabpanel"></div>';
        });
        document.body.insertAdjacentHTML('beforeend',
            '<div class="modal fade" id="' + MODAL_ID + '" tabindex="-1" aria-hidden="true">'
            + '<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">'
            + '<div class="modal-header"><h5 class="modal-title"><i class="ti ti-sparkles me-1"></i>'
            + esc(t('title')) + ' — ' + esc(t('ticket')) + ' #' + esc(s.ticketsId) + '</h5>'
            + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
            + '<div class="modal-body"><ul class="nav nav-tabs" role="tablist">' + nav + '</ul>'
            + '<div class="tab-content border border-top-0 rounded-bottom p-3 ticketassistant-tab-content">' + content + '</div></div>'
            + '</div></div></div>'
        );
        modal = document.getElementById(MODAL_ID);
        $(modal).on('shown.bs.tab', '[data-ticketassistant-pane]', function () {
            render(this.getAttribute('data-ticketassistant-pane'), false);
        });
        return modal;
    }

    function setPane(name, html) {
        $('#ticketassistant-pane-' + name).html(html);
    }

    function open(pane) {
        if (!getSettings()) {
            return;
        }
        var modal = ensureModal();
        bootstrap.Modal.getOrCreateInstance(modal).show();
        var button = modal.querySelector('[data-ticketassistant-pane="' + pane + '"]');
        if (button) {
            bootstrap.Tab.getOrCreateInstance(button).show();
        }
        render(pane, true);
    }

    function hideModal() {
        var modal = document.getElementById(MODAL_ID);
        var instance = modal ? bootstrap.Modal.getInstance(modal) : null;
        if (instance) {
            instance.hide();
        }
    }

    function render(pane, autoRun) {
        if (pane === 'analyze' || pane === 'reply') {
            if (state.results.analyze) {
                renderAnalysis(state.results.analyze);
            } else if (state.pending.analyze) {
                setPane(pane, loading(t('loading_analyze')));
            } else if (autoRun) {
                runAnalyze(false);
            } else {
                setPane(pane, startButton('analyze', t('start_analyze')));
            }
        } else if (pane === 'classify') {
            if (state.results.classify) {
                renderClassification(state.results.classify);
            } else if (!state.pending.classify) {
                runClassify();
            }
        } else if (pane === 'article') {
            if (state.results.article) {
                renderArticle(state.results.article);
            } else if (state.pending.article) {
                setPane('article', loading(t('loading_article')));
            } else if (autoRun) {
                runArticle();
            } else {
                setPane('article', '<p class="text-muted">' + esc(t('article_notice')) + '</p>' + startButton('article', t('start_article')));
            }
        }
    }

    function startButton(run, label) {
        return '<div class="text-center py-4"><button type="button" class="btn btn-primary" data-ticketassistant-run="' + run + '">'
            + '<i class="ti ti-sparkles me-1"></i>' + esc(label) + '</button></div>';
    }

    // ------------------------------------------------------------------
    // Analysis and reply
    // ------------------------------------------------------------------

    function runAnalyze(force) {
        if (state.pending.analyze) {
            return;
        }
        state.pending.analyze = true;
        setPane('analyze', loading(t('loading_analyze')));
        setPane('reply', loading(t('loading_analyze')));
        action('analyze', { force: force ? 1 : 0 }).then(function (data) {
            state.pending.analyze = false;
            if (data.success) {
                state.results.analyze = data;
                renderAnalysis(data);
            } else {
                setPane('analyze', errorBox(data, 'analyze'));
                setPane('reply', errorBox(data, 'analyze'));
            }
        });
    }

    function renderAnalysis(data) {
        var meta = data.meta || {};
        var html = '';

        if (meta.cached) {
            html += '<div class="alert alert-secondary d-flex align-items-center justify-content-between py-2">'
                + '<span><i class="ti ti-history me-1"></i>' + esc(format(t('cached'), meta.cached_at || '', meta.cached_by || '')) + '</span>'
                + '<button type="button" class="btn btn-sm btn-outline-primary" data-ticketassistant-run="analyze-force"><i class="ti ti-refresh me-1"></i>' + esc(t('regenerate')) + '</button></div>';
        }
        if (meta.notice) {
            html += '<div class="alert alert-warning py-2">' + esc(meta.notice) + '</div>';
        }
        html += '<div class="alert alert-info py-2 mb-3"><i class="ti ti-eye-check me-1"></i>' + esc(t('internal_notice')) + '</div>';
        html += '<h4 class="mb-2">' + esc(t('summary')) + '</h4><div class="ticketassistant-preview mb-3">' + (data.summary_html || '') + '</div>';
        html += '<h4 class="mb-2">' + esc(t('strategy')) + '</h4><div class="ticketassistant-preview mb-3">' + (data.strategy_html || '') + '</div>';

        if (data.missing_information && data.missing_information.length) {
            html += '<h4 class="mb-2">' + esc(t('missing')) + '</h4><ul class="mb-3">';
            data.missing_information.forEach(function (item) {
                html += '<li>' + esc(item) + '</li>';
            });
            html += '</ul>';
        }
        if (data.similar && data.similar.length) {
            html += '<div class="mb-3"><strong>' + esc(t('similar')) + ':</strong> ';
            html += data.similar.map(function (row) {
                // similarity 0: recent ticket of the same category, no text match
                return '<a href="' + esc(row.url) + '" target="_blank" rel="noopener">#' + esc(row.tickets_id) + '</a>'
                    + ' <span class="text-muted">(' + (row.similarity > 0 ? Math.round(row.similarity * 100) + '%' : esc(t('same_category'))) + ')</span>';
            }).join(', ');
            html += '</div>';
        }
        html += '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-top pt-2">' + metaLine(meta)
            + '<div class="d-flex gap-2">' + feedbackButtons(data.log_id)
            + (!meta.cached ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-ticketassistant-run="analyze-force"><i class="ti ti-refresh me-1"></i>' + esc(t('regenerate')) + '</button>' : '')
            + '</div></div>';
        setPane('analyze', html);

        var reply = '<div class="alert alert-warning py-2 mb-3"><i class="ti ti-pencil me-1"></i>' + esc(t('draft_notice')) + '</div>'
            + '<div class="ticketassistant-preview mb-3" id="ticketassistant-reply">' + (data.reply_html || '') + '</div>'
            + '<div class="d-flex flex-wrap justify-content-end gap-2">'
            + '<button type="button" class="btn btn-outline-secondary btn-sm" data-ticketassistant-copy="reply"><i class="ti ti-copy me-1"></i>' + esc(t('copy')) + '</button>'
            + (document.getElementById(BLOCKS.solution) ? '<button type="button" class="btn btn-outline-primary btn-sm" data-ticketassistant-insert="solution"><i class="ti ti-check me-1"></i>' + esc(t('insert_solution')) + '</button>' : '')
            + (document.getElementById(BLOCKS.followup) ? '<button type="button" class="btn btn-primary btn-sm" data-ticketassistant-insert="followup"><i class="ti ti-message-forward me-1"></i>' + esc(t('insert_followup')) + '</button>' : '')
            + '</div>';
        setPane('reply', reply);
    }

    function markUsed() {
        var data = state.results.analyze;
        if (data && data.log_id) {
            action('used', { log_id: data.log_id });
        }
    }

    function insertReply(kind) {
        var data = state.results.analyze;
        if (!data || !data.reply_html) {
            return;
        }
        hideModal();
        openAnswerBlock(kind).then(function (found) {
            var existing = found.editor.getContent({ format: 'text' }).trim();
            if (existing.length && !window.confirm(t('replace_confirm'))) {
                return;
            }
            setEditorContent(found.editor, data.reply_html);
            found.block.scrollIntoView({ behavior: 'smooth', block: 'center' });
            toast(t('inserted'));
            markUsed();
        }, function () {
            window.alert(t('no_editor'));
        });
    }

    function copyHtml(html, button) {
        var text = $('<div>').html(html).text();
        var done = function () {
            var original = button.html();
            button.html('<i class="ti ti-check me-1"></i>' + esc(t('copied')));
            window.setTimeout(function () {
                button.html(original);
            }, 1500);
            markUsed();
        };
        if (navigator.clipboard && window.ClipboardItem) {
            navigator.clipboard.write([new window.ClipboardItem({
                'text/html': new Blob([html], { type: 'text/html' }),
                'text/plain': new Blob([text], { type: 'text/plain' })
            })]).then(done, function () {
                navigator.clipboard.writeText(text).then(done);
            });
        } else if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done);
        } else {
            var area = $('<textarea class="visually-hidden">').val(text).appendTo(document.body);
            area.get(0).select();
            document.execCommand('copy');
            area.remove();
            done();
        }
    }

    // ------------------------------------------------------------------
    // Classification
    // ------------------------------------------------------------------

    function runClassify() {
        state.pending.classify = true;
        setPane('classify', loading(t('loading_classify')));
        action('classify').then(function (data) {
            state.pending.classify = false;
            if (data.success) {
                state.results.classify = data;
                renderClassification(data);
            } else {
                setPane('classify', errorBox(data, 'classify'));
            }
        });
    }

    function percent(value) {
        return Math.round((value || 0) * 100) + '%';
    }

    function percentLabel(value) {
        // A weak but real support reads "<1%", not "0%"
        return value > 0 && value < 0.005 ? '<1%' : percent(value);
    }

    function renderClassification(data) {
        var s = data.suggestion || {};
        var c = data.current || {};
        var canApply = !!data.can_apply;
        var html = '';

        if (!s.category_id && !data.neighbors) {
            setPane('classify', '<div class="alert alert-secondary">' + esc(t('no_suggestion')) + '</div>');
            return;
        }

        var row = function (label, current, suggested, field, value, differs) {
            return '<tr><th class="w-25">' + esc(label) + '</th><td>' + esc(current || '—') + '</td><td><strong>' + esc(suggested || '—') + '</strong></td>'
                + '<td class="text-end">' + (canApply && differs && value
                    ? '<button type="button" class="btn btn-sm btn-outline-primary" data-ticketassistant-apply="' + field + '" data-value="' + esc(value) + '">' + esc(t('apply')) + '</button>'
                    : '') + '</td></tr>';
        };

        html += '<table class="table table-sm align-middle"><thead><tr><th></th><th>' + esc(t('current')) + '</th><th>' + esc(t('suggested')) + '</th><th></th></tr></thead><tbody>';
        html += row(t('type'), c.type_label, s.type_label, 'type', s.type, s.type && s.type !== c.type);
        html += row(t('category'), c.category_name, s.category_name, 'category_id', s.category_id, s.category_id && s.category_id !== c.category_id);
        if (s.urgency) {
            html += row(t('urgency'), c.urgency_label, s.urgency_label, 'urgency', s.urgency, s.urgency !== c.urgency);
        }
        html += '</tbody></table>';

        html += '<div class="mb-2"><span class="text-muted">' + esc(t('confidence')) + ':</span> <strong>' + percent(s.confidence) + '</strong>'
            + '<div class="progress mt-1" style="height: 6px;"><div class="progress-bar" role="progressbar" style="width: ' + percent(s.confidence) + '"></div></div></div>';
        if (s.justification) {
            html += '<p class="mb-1">' + esc(s.justification) + '</p>';
        }
        html += '<p class="text-muted ticketassistant-fs-sm">' + esc(s.method === 'llm' ? t('method_llm') : t('method_knn')) + '</p>';

        var others = (data.candidates || []).filter(function (candidate) {
            return candidate.id !== s.category_id;
        });
        if (others.length) {
            html += '<h4 class="mt-3">' + esc(t('other_candidates')) + '</h4><ul class="list-unstyled">';
            others.forEach(function (candidate) {
                html += '<li class="d-flex justify-content-between align-items-center py-1 border-bottom"><span>' + esc(candidate.name)
                    + ' <span class="text-muted">(' + esc(percentLabel(candidate.score)) + ')</span></span>'
                    + (canApply ? '<button type="button" class="btn btn-sm btn-ghost-primary" data-ticketassistant-apply="category_id" data-value="' + esc(candidate.id) + '">' + esc(t('apply_category')) + '</button>' : '')
                    + '</li>';
            });
            html += '</ul>';
        }
        if (data.similar && data.similar.length) {
            html += '<div class="text-muted ticketassistant-fs-sm mt-2">' + esc(t('similar')) + ': ' + data.similar.map(function (r) {
                return '<a href="' + esc(r.url) + '" target="_blank" rel="noopener">#' + esc(r.tickets_id) + '</a>';
            }).join(', ') + '</div>';
        }
        html += '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-top pt-2 mt-3">' + metaLine(data.meta)
            + '<div class="d-flex gap-2">' + feedbackButtons(data.log_id)
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-ticketassistant-dismiss="1">' + esc(t('dismiss')) + '</button>'
            + (canApply && s.category_id && (s.category_id !== c.category_id || s.type !== c.type)
                ? '<button type="button" class="btn btn-sm btn-primary" data-ticketassistant-apply="all"><i class="ti ti-check me-1"></i>' + esc(t('apply')) + '</button>'
                : '')
            + '</div></div>';
        setPane('classify', html);
    }

    function applyClassification(field, value) {
        var s = (state.results.classify || {}).suggestion || {};
        var data = {};
        if (field === 'all') {
            data = { type: s.type, category_id: s.category_id };
            if (s.urgency) {
                data.urgency = s.urgency;
            }
        } else {
            data[field] = value;
            // A category must match the type: send the suggested type with it
            if (field === 'category_id' && s.type) {
                data.type = s.type;
            }
        }
        action('apply', data).then(function (response) {
            if (response.success) {
                toast(response.message || t('apply'));
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } else {
                toast(response.message || t('unknown_error'), true);
            }
        });
    }

    // ------------------------------------------------------------------
    // Knowledge base article
    // ------------------------------------------------------------------

    function runArticle() {
        state.pending.article = true;
        setPane('article', loading(t('loading_article')));
        action('article').then(function (data) {
            state.pending.article = false;
            if (data.success) {
                state.results.article = data;
                renderArticle(data);
            } else {
                setPane('article', errorBox(data, 'article'));
            }
        });
    }

    function renderArticle(data) {
        var html = '<div class="alert alert-info py-2">' + esc(t('article_notice')) + '</div>'
            + '<input type="text" class="form-control mb-2" id="ticketassistant-article-title" value="' + esc(data.title) + '">'
            + '<div class="ticketassistant-preview border rounded p-2 mb-3" id="ticketassistant-article">' + (data.content_html || '') + '</div>'
            + '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">' + metaLine(data.meta)
            + '<div class="d-flex gap-2">' + feedbackButtons(data.log_id)
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-ticketassistant-copy="article"><i class="ti ti-copy me-1"></i>' + esc(t('copy')) + '</button>'
            + (data.can_create ? '<button type="button" class="btn btn-sm btn-primary" data-ticketassistant-create-article="1"><i class="ti ti-book-upload me-1"></i>' + esc(t('create_article')) + '</button>' : '')
            + '</div></div><div id="ticketassistant-article-result" class="mt-2"></div>';
        setPane('article', html);
    }

    function createArticle(button) {
        var data = state.results.article;
        if (!data) {
            return;
        }
        button.prop('disabled', true);
        action('create_article', {
            title: $('#ticketassistant-article-title').val(),
            content_html: data.content_html
        }).then(function (response) {
            if (response.success) {
                $('#ticketassistant-article-result').html('<div class="alert alert-success py-2">' + esc(response.message)
                    + ' <a href="' + esc(response.url) + '" target="_blank" rel="noopener">' + esc(t('open_article')) + '</a></div>');
                if (data.log_id) {
                    action('used', { log_id: data.log_id });
                }
            } else {
                button.prop('disabled', false);
                $('#ticketassistant-article-result').html(errorBox(response, null));
            }
        });
    }

    // ------------------------------------------------------------------
    // Improve my text (buttons rendered inside the followup/solution forms)
    // ------------------------------------------------------------------

    function improve(trigger) {
        var group = trigger.closest('.ticketassistant-improve');
        var form = group.closest('form').get(0) || group.parent().get(0);
        var mainButton = group.find('button.ticketassistant-improve-btn').first();
        var mode = trigger.attr('data-ticketassistant-mode') || 'improve';
        var editor = readyEditor(form);
        if (!editor) {
            window.alert(group.attr('data-msg-noeditor'));
            return;
        }
        if (!editor.getContent({ format: 'text' }).trim().length) {
            window.alert(group.attr('data-msg-empty'));
            return;
        }
        if (mainButton.prop('disabled')) {
            return;
        }
        var original = mainButton.html();
        mainButton.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + esc(group.attr('data-msg-improving')));

        var itemsInput = form ? form.querySelector('input[name="items_id"]') : null;
        var s = getSettings();
        var ticketsId = (itemsInput && itemsInput.value) || group.attr('data-ticketassistant-tickets-id') || (s && s.ticketsId);

        post(group.attr('data-ticketassistant-url'), {
            action: 'improve',
            tickets_id: ticketsId,
            mode: mode,
            draft_html: editor.getContent()
        }).then(function (data) {
            mainButton.prop('disabled', false).html(original);
            if (data.success && data.reply_html) {
                setEditorContent(editor, data.reply_html);
                rememberGeneration(form, data.log_id);
                if (typeof glpi_toast_info === 'function') {
                    glpi_toast_info(group.attr('data-msg-improved'), group.attr('data-msg-title'));
                }
            } else {
                window.alert(data.message || 'Error');
            }
        });
    }

    // A rewritten text counts as used when the form is submitted with it
    // (item_add hook of followups and solutions, see TicketHooks::onAnswerAdd)
    function rememberGeneration(form, logId) {
        if (!form || form.tagName !== 'FORM' || !logId) {
            return;
        }
        var input = form.querySelector('input[name="_ticketassistant_log_id"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_ticketassistant_log_id';
            form.appendChild(input);
        }
        input.value = String(logId);
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    $(document).on('click', '.ticketassistant-open', function () {
        open(this.getAttribute('data-ticketassistant-tab') || 'analyze');
    });

    $(document).on('click', '[data-ticketassistant-run]', function () {
        var run = this.getAttribute('data-ticketassistant-run');
        if (run === 'analyze') {
            runAnalyze(false);
        } else if (run === 'analyze-force') {
            state.results.analyze = null;
            runAnalyze(true);
        } else if (run === 'classify') {
            runClassify();
        } else if (run === 'article') {
            runArticle();
        }
    });

    $(document).on('click', '[data-ticketassistant-insert]', function () {
        insertReply(this.getAttribute('data-ticketassistant-insert'));
    });

    $(document).on('click', '[data-ticketassistant-copy]', function () {
        var target = this.getAttribute('data-ticketassistant-copy');
        var html = target === 'article'
            ? '<h2>' + esc($('#ticketassistant-article-title').val()) + '</h2>' + ((state.results.article || {}).content_html || '')
            : ((state.results.analyze || {}).reply_html || '');
        copyHtml(html, $(this));
    });

    $(document).on('click', '[data-ticketassistant-apply]', function () {
        applyClassification(this.getAttribute('data-ticketassistant-apply'), this.getAttribute('data-value'));
    });

    $(document).on('click', '[data-ticketassistant-dismiss]', function () {
        action('dismiss').then(function () {
            hideModal();
            $('.ticketassistant-suggestion').remove();
        });
    });

    $(document).on('click', '[data-ticketassistant-create-article]', function () {
        createArticle($(this));
    });

    $(document).on('click', '.ticketassistant-feedback button', function () {
        var button = $(this);
        var group = button.closest('.ticketassistant-feedback');
        var value = parseInt(button.attr('data-value'), 10);
        var s = getSettings();
        post(s.ajaxUrl, { action: 'feedback', log_id: group.attr('data-log-id'), value: value }).then(function (data) {
            if (data.success) {
                group.find('button').removeClass('active');
                button.addClass('active');
                toast(t('thanks'));
            }
        });
    });

    $(document).on('click', '.ticketassistant-improve-btn', function (event) {
        event.preventDefault();
        improve($(this));
    });
})(jQuery);
