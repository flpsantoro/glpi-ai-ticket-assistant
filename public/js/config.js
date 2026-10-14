/**
 * GLPI AI Ticket Assistant — configuration page helpers: provider presets,
 * connection test and model listing (both use the SAVED configuration).
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 */

(function ($) {
    'use strict';

    var node = document.getElementById('ticketassistant-config-settings');
    if (!node) {
        return;
    }
    var settings = {};
    try {
        settings = JSON.parse(node.textContent || '{}');
    } catch (e) {
        return;
    }

    function t(key) {
        return (settings.i18n && settings.i18n[key]) || key;
    }

    function esc(text) {
        return $('<div>').text(text === null || text === undefined ? '' : String(text)).html();
    }

    function format(pattern, value) {
        return String(pattern).replace(/%[sd]/, value);
    }

    function post(data) {
        return $.ajax({ url: settings.adminUrl, method: 'POST', data: data, dataType: 'json', timeout: 180000 })
            .then(null, function (xhr) {
                var payload = xhr.responseJSON || {};
                return $.Deferred().resolve({
                    success: false,
                    message: payload.message || format(t('http_error'), xhr.status || 0)
                }).promise();
            });
    }

    // ---- Provider presets ----------------------------------------------
    function applyPreset() {
        var option = $('#ticketassistant-provider option:selected');
        var azure = option.attr('data-azure') === '1';
        $('#ticketassistant-base-url').attr('placeholder', option.attr('data-base-url-hint') || option.attr('data-base-url') || '');
        $('#ticketassistant-model').attr('placeholder', option.attr('data-model-hint') || '');
        $('input[name="embedding_model"]').attr('placeholder', option.attr('data-embedding-hint') || '');
        $('.ticketassistant-azure-only').toggle(azure);
        $('.ticketassistant-not-azure').toggle(!azure);
        $('#ticketassistant-list-models').toggle(option.attr('data-list-models') === '1');
        var docs = option.attr('data-docs');
        $('#ticketassistant-provider-docs').html(docs
            ? '<a href="' + esc(docs) + '" target="_blank" rel="noopener noreferrer"><i class="ti ti-external-link me-1"></i>' + esc(t('docs')) + '</a>'
            : '');
    }

    $(document).on('change', '#ticketassistant-provider', applyPreset);
    $(applyPreset);

    // ---- Connection test -------------------------------------------------
    $(document).on('click', '#ticketassistant-test', function () {
        var button = $(this);
        var output = $('#ticketassistant-test-result');
        button.prop('disabled', true);
        output.html('<span class="spinner-border spinner-border-sm me-1"></span>' + esc(t('testing')));
        post({ action: 'test' }).then(function (data) {
            button.prop('disabled', false);
            output.html(data.success
                ? '<span class="text-success"><i class="ti ti-circle-check me-1"></i>' + esc(data.message) + '</span>'
                : '<span class="text-danger"><i class="ti ti-circle-x me-1"></i>' + esc(data.message) + '</span>');
        });
    });

    // ---- Model listing ----------------------------------------------------
    $(document).on('click', '#ticketassistant-list-models', function () {
        var button = $(this);
        var output = $('#ticketassistant-test-result');
        button.prop('disabled', true);
        output.html('<span class="spinner-border spinner-border-sm me-1"></span>' + esc(t('listing')));
        post({ action: 'models' }).then(function (data) {
            button.prop('disabled', false);
            if (!data.success) {
                output.html('<span class="text-danger"><i class="ti ti-circle-x me-1"></i>' + esc(data.message) + '</span>');
                return;
            }
            var list = $('#ticketassistant-models').empty();
            (data.models || []).forEach(function (model) {
                list.append($('<option>').attr('value', model));
            });
            output.html(data.models && data.models.length
                ? '<span class="text-success"><i class="ti ti-list-check me-1"></i>' + esc(format(t('models_found'), data.models.length)) + '</span>'
                : '<span class="text-muted">' + esc(t('no_models')) + '</span>');
            $('#ticketassistant-model').trigger('focus');
        });
    });
})(jQuery);
