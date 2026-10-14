<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use GlpiPlugin\Ticketassistant\Knowledge\CaseLibrary;
use GlpiPlugin\Ticketassistant\Llm\Providers;
use Html;

/**
 * Configuration page: a "quick setup" card (provider, address, model, key,
 * test, enable) followed by tabs with every other setting. Plain Bootstrap 5
 * markup, compatible with the Tabler versions of GLPI 10 and 11.
 */
final class ConfigForm
{
    /** @var array<string, mixed> */
    private static $config = [];

    public static function show(array $config): void
    {
        self::$config = $config;
        $ready        = Config::isReady($config);
        $missing      = Providers::missingSettings(Config::connectionSettings($config));
        $presets      = Providers::all();

        echo "<div class='container-xl my-3 ticketassistant-config'>";

        // ---- Header ---------------------------------------------------------
        echo "<div class='d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3'>";
        echo "<h2 class='mb-0'><i class='ti ti-sparkles me-2'></i>" . Text::e(__('GLPI AI Ticket Assistant', 'ticketassistant')) . '</h2>';
        echo "<div class='d-flex align-items-center gap-2'>";
        echo "<a class='btn btn-outline-secondary' href='" . Text::e(Compat::url('front/knowledge.php')) . "'><i class='ti ti-school me-1'></i>" . Text::e(__('Learning from solved tickets', 'ticketassistant')) . '</a>';
        echo "<a class='btn btn-outline-secondary' href='" . Text::e(Compat::url('front/stats.php')) . "'><i class='ti ti-chart-bar me-1'></i>" . Text::e(__('Statistics', 'ticketassistant')) . '</a>';
        echo $ready
            ? "<span class='badge bg-green-lt fs-5'>" . Text::e(__('ACTIVE', 'ticketassistant')) . '</span>'
            : "<span class='badge bg-red-lt fs-5'>" . Text::e(__('INACTIVE', 'ticketassistant')) . '</span>';
        echo '</div></div>';

        echo "<form method='post' action='" . Text::e(Compat::url('front/config.form.php')) . "' autocomplete='off'>";

        // ---- Quick setup ----------------------------------------------------
        echo "<div class='card mb-3'>";
        echo "<div class='card-header'><h3 class='card-title mb-0'><i class='ti ti-rocket me-1'></i>" . Text::e(__('Quick setup', 'ticketassistant')) . '</h3></div>';
        echo "<div class='card-body'>";
        echo "<ol class='text-muted mb-3' style='font-size: .875em;'>"
            . '<li>' . Text::e(__('Choose the AI provider and fill in the address, the model and the key.', 'ticketassistant')) . '</li>'
            . '<li>' . Text::e(__('Save, then test the connection.', 'ticketassistant')) . '</li>'
            . '<li>' . Text::e(__('Enable the assistant. Technicians see the buttons according to their profile (Administration > Profiles > AI assistant).', 'ticketassistant')) . '</li>'
            . '</ol>';

        echo "<div class='row g-3'>";
        // Provider
        echo "<div class='col-md-4'><label class='form-label'>" . Text::e(__('AI provider', 'ticketassistant')) . '</label>';
        echo "<select class='form-select' name='provider' id='ticketassistant-provider'>";
        foreach ($presets as $id => $preset) {
            $data = " data-base-url='" . Text::e((string) $preset['base_url']) . "'"
                . " data-base-url-hint='" . Text::e((string) ($preset['base_url_hint'] ?? $preset['base_url'])) . "'"
                . " data-model-hint='" . Text::e((string) ($preset['model_hint'] ?? '')) . "'"
                . " data-embedding-hint='" . Text::e((string) ($preset['embedding_hint'] ?? '')) . "'"
                . " data-key-required='" . (!empty($preset['key_required']) ? '1' : '0') . "'"
                . " data-azure='" . ($preset['driver'] === 'azure' ? '1' : '0') . "'"
                . " data-list-models='" . (!empty($preset['list_models']) ? '1' : '0') . "'"
                . " data-docs='" . Text::e((string) ($preset['docs'] ?? '')) . "'";
            echo "<option value='" . Text::e($id) . "'" . $data . ($config['provider'] === $id ? ' selected' : '') . '>' . Text::e((string) $preset['label']) . '</option>';
        }
        echo '</select>';
        echo "<div class='form-hint' id='ticketassistant-provider-docs'></div></div>";

        // Address
        $preset = Providers::get((string) $config['provider']);
        echo "<div class='col-md-8'><label class='form-label'>" . Text::e(__('API address (base URL)', 'ticketassistant')) . '</label>';
        echo "<input type='text' class='form-control' name='base_url' id='ticketassistant-base-url' value='" . Text::e($config['base_url']) . "'"
            . " placeholder='" . Text::e((string) ($preset['base_url_hint'] ?? $preset['base_url'])) . "'>";
        echo "<div class='form-hint'>" . Text::e(__('Leave empty to use the default address of the provider. Azure: https://<resource>.openai.azure.com', 'ticketassistant')) . '</div></div>';

        // Model
        echo "<div class='col-md-4'><label class='form-label'><span class='ticketassistant-azure-only'>" . Text::e(__('Deployment', 'ticketassistant')) . "</span><span class='ticketassistant-not-azure'>" . Text::e(__('Model', 'ticketassistant')) . '</span></label>';
        echo "<div class='input-group'>";
        echo "<input type='text' class='form-control' name='model' id='ticketassistant-model' list='ticketassistant-models' value='" . Text::e($config['model']) . "'"
            . " placeholder='" . Text::e((string) ($preset['model_hint'] ?? '')) . "'>";
        echo "<button type='button' class='btn btn-outline-secondary' id='ticketassistant-list-models' title='" . Text::e(__('List the models offered by the provider (uses the saved settings)', 'ticketassistant')) . "'><i class='ti ti-list-search'></i></button>";
        echo '</div>';
        echo "<datalist id='ticketassistant-models'></datalist>";
        echo "<div class='form-hint'>" . Text::e(__('A model accepting images is recommended (screenshots, scanned documents).', 'ticketassistant')) . '</div></div>';

        // API version (Azure)
        echo "<div class='col-md-2 ticketassistant-azure-only'><label class='form-label'>" . Text::e(__('API version', 'ticketassistant')) . '</label>';
        echo "<input type='text' class='form-control' name='api_version' value='" . Text::e($config['api_version']) . "' placeholder='2024-10-21'>";
        echo "<div class='form-hint'>" . Text::e(__('"v1" for the new Azure v1 API.', 'ticketassistant')) . '</div></div>';

        // Key
        echo "<div class='col-md-6'><label class='form-label'>" . Text::e(__('API key', 'ticketassistant')) . '</label>';
        $has_key = (string) $config['api_key'] !== '';
        echo "<input type='password' class='form-control' name='api_key' value='' autocomplete='new-password' placeholder='"
            . Text::e($has_key ? __('•••••••• (key saved: fill in to replace it)', 'ticketassistant') : __('Paste the key here', 'ticketassistant')) . "'>";
        echo "<div class='form-hint'>" . Text::e(__('Stored encrypted with the key of the GLPI installation and never displayed again. Leave empty to keep the current key.', 'ticketassistant'));
        if ($has_key) {
            echo " <label class='form-check form-check-inline mb-0 ms-2'><input class='form-check-input' type='checkbox' name='_clear_api_key' value='1'><span class='form-check-label'>" . Text::e(__('Remove the key', 'ticketassistant')) . '</span></label>';
        }
        echo '</div></div>';

        echo '</div>'; // row

        echo "<div class='d-flex flex-wrap align-items-center gap-2 mt-3'>";
        echo "<button type='submit' name='update_config' value='1' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . Text::e(__('Save', 'ticketassistant')) . '</button>';
        echo "<button type='button' class='btn btn-outline-primary' id='ticketassistant-test'><i class='ti ti-plug-connected me-1'></i>" . Text::e(__('Test the connection', 'ticketassistant')) . '</button>';
        echo self::switch('enabled', __('Enable the assistant', 'ticketassistant'), '', 'ms-md-3 mb-0');
        echo "<span id='ticketassistant-test-result' class='ms-2'></span>";
        echo '</div>';
        if (count($missing)) {
            $labels = ['base_url' => __('API address', 'ticketassistant'), 'model' => __('Model', 'ticketassistant'), 'api_key' => __('API key', 'ticketassistant')];
            echo "<div class='alert alert-warning mt-3 mb-0'>" . Text::e(sprintf(
                __('Still missing before the assistant can work: %s.', 'ticketassistant'),
                implode(', ', array_map(static function ($key) use ($labels) {
                    return $labels[$key] ?? $key;
                }, $missing))
            )) . '</div>';
        }
        echo '</div></div>';

        // ---- Tabs -----------------------------------------------------------
        $tabs = [
            'actions'   => [__('Actions', 'ticketassistant'), 'ti-hand-click'],
            'model'     => [__('Model and generation', 'ticketassistant'), 'ti-adjustments'],
            'context'   => [__('Context and attachments', 'ticketassistant'), 'ti-paperclip'],
            'knowledge' => [__('Learning and classification', 'ticketassistant'), 'ti-school'],
            'privacy'   => [__('Privacy', 'ticketassistant'), 'ti-shield-lock'],
            'costs'     => [__('Costs and limits', 'ticketassistant'), 'ti-coin'],
            'style'     => [__('Writing style', 'ticketassistant'), 'ti-writing'],
            'diagnosis' => [__('Diagnosis', 'ticketassistant'), 'ti-stethoscope'],
        ];
        echo "<ul class='nav nav-tabs' role='tablist'>";
        $first = true;
        foreach ($tabs as $id => [$label, $icon]) {
            echo "<li class='nav-item' role='presentation'><button type='button' class='nav-link" . ($first ? ' active' : '') . "' data-bs-toggle='tab' data-bs-target='#ticketassistant-tab-" . $id . "' role='tab'>"
                . "<i class='ti " . $icon . " me-1'></i>" . Text::e($label) . '</button></li>';
            $first = false;
        }
        echo '</ul>';
        echo "<div class='tab-content card card-body border-top-0 rounded-0 rounded-bottom mb-3'>";

        self::pane('actions', true, function () {
            echo "<div class='row g-3'>";
            echo "<div class='col-md-6'>" . self::switch('action_analyze', __('Analyze the ticket: summary, technical strategy and reply draft', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-6'>" . self::switch('action_improve', __('Improve my text: rewrite the draft of the technician (6 modes)', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-6'>" . self::switch('action_classify', __('Classification: suggest type, category and urgency', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-6'>" . self::switch('action_article', __('Knowledge base: write an article from a solved ticket', 'ticketassistant')) . '</div>';
            echo '</div>';
            echo "<p class='text-muted mt-3 mb-0'>" . Text::e(__('Nothing is ever sent to the requester automatically: drafts are inserted in the editor and the technician reviews and sends them.', 'ticketassistant')) . '</p>';
        });

        self::pane('model', false, function () {
            $config = self::$config;
            echo "<div class='row g-3'>";
            echo "<div class='col-md-4'><label class='form-label'>" . Text::e(__('Model type', 'ticketassistant')) . '</label>'
                . self::select('model_type', [
                    'reasoning' => __('Reasoning model (reasoning effort, no temperature)', 'ticketassistant'),
                    'standard'  => __('Standard model (temperature)', 'ticketassistant'),
                ]) . '</div>';
            echo "<div class='col-md-2'><label class='form-label'>" . Text::e(__('Temperature', 'ticketassistant')) . '</label>'
                . self::number('temperature', 0, 2, '0.1') . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('JSON answers', 'ticketassistant')) . '</label>'
                . self::select('json_mode', [
                    'auto'        => __('Automatic (provider default)', 'ticketassistant'),
                    'json_schema' => __('Strict JSON schema', 'ticketassistant'),
                    'json_object' => __('JSON mode', 'ticketassistant'),
                    'prompt'      => __('Instructions only', 'ticketassistant'),
                ]) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Output limit parameter', 'ticketassistant')) . '</label>'
                . self::select('token_param', [
                    'auto'                  => __('Automatic', 'ticketassistant'),
                    'max_completion_tokens' => 'max_completion_tokens',
                    'max_tokens'            => 'max_tokens',
                ]) . '</div>';
            echo "<div class='col-md-3'>" . self::switch('vision', __('The model reads images (vision)', 'ticketassistant'), '', 'mt-md-4') . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Image detail', 'ticketassistant')) . '</label>'
                . self::select('image_detail', ['auto' => __('Automatic', 'ticketassistant'), 'low' => __('Low (cheaper)', 'ticketassistant'), 'high' => __('High', 'ticketassistant')]) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Timeout (seconds)', 'ticketassistant')) . '</label>' . self::number('http_timeout', 10, 600) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('New attempts (network, 429, 5xx)', 'ticketassistant')) . '</label>' . self::number('http_retries', 0, 5) . '</div>';
            echo "<div class='col-md-6'>" . self::switch('use_glpi_proxy', __('Use the proxy configured in GLPI (local and private addresses are always reached directly)', 'ticketassistant')) . '</div>';
            echo '</div>';

            echo "<h4 class='mt-4'>" . Text::e(__('Per action', 'ticketassistant')) . '</h4>';
            echo "<p class='text-muted'>" . Text::e(__('On reasoning models the output limit includes the reasoning tokens: keep about twice the expected text.', 'ticketassistant')) . '</p>';
            echo "<div class='table-responsive'><table class='table table-sm align-middle'><thead><tr><th>" . Text::e(__('Action', 'ticketassistant')) . '</th><th>'
                . Text::e(__('Output limit (tokens)', 'ticketassistant')) . '</th><th>' . Text::e(__('Reasoning effort', 'ticketassistant')) . '</th></tr></thead><tbody>';
            $efforts = ['' => __('(not sent)', 'ticketassistant'), 'none' => 'none', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high'];
            foreach (
                [
                    'analyze'  => __('Analyze the ticket', 'ticketassistant'),
                    'improve'  => __('Improve my text', 'ticketassistant'),
                    'classify' => __('Classification', 'ticketassistant'),
                    'article'  => __('Knowledge base article', 'ticketassistant'),
                    'learn'    => __('Learning from solved tickets', 'ticketassistant'),
                ] as $action => $label
            ) {
                echo '<tr><td>' . Text::e($label) . '</td><td>' . self::number($action . '_max_tokens', 128, 64000) . '</td><td>' . self::select($action . '_effort', $efforts) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        });

        self::pane('context', false, function () {
            echo "<div class='row g-3'>";
            echo "<div class='col-md-4'><label class='form-label'>" . Text::e(__('Context size (characters)', 'ticketassistant')) . '</label>' . self::number('max_context_chars', 4000, 400000)
                . "<div class='form-hint'>" . Text::e(__('When the history does not fit, the oldest followups are left out.', 'ticketassistant')) . '</div></div>';
            echo "<div class='col-md-8'><label class='form-label'>" . Text::e(__('Private followups and tasks', 'ticketassistant')) . '</label>'
                . self::select('private_items', [
                    TicketContext::PRIVATE_RIGHTS => __('Only those the technician is allowed to see (recommended)', 'ticketassistant'),
                    TicketContext::PRIVATE_ALL    => __('All of them, flagged as internal notes', 'ticketassistant'),
                    TicketContext::PRIVATE_NONE   => __('None', 'ticketassistant'),
                ]) . '</div>';
            echo "<div class='col-md-6'>" . self::switch('context_linked_items', __('Include the assets linked to the ticket', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-6'>" . self::switch('solution_templates', __('Include the solution templates of the entity as style references', 'ticketassistant')) . '</div>';

            echo "<div class='col-12'><h4 class='mt-2 mb-0'>" . Text::e(__('Images', 'ticketassistant')) . '</h4></div>';
            echo "<div class='col-md-3'>" . self::switch('images_enabled', __('Send attached images', 'ticketassistant'), '', 'mt-md-4') . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Maximum images', 'ticketassistant')) . '</label>' . self::number('images_max_count', 0, 20) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Maximum size sent (MB)', 'ticketassistant')) . '</label>' . self::number('images_max_mb', 1, 20) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Longest side (pixels)', 'ticketassistant')) . '</label>' . self::number('images_max_px', 512, 4096)
                . "<div class='form-hint'>" . Text::e(__('Larger images are reduced instead of being skipped.', 'ticketassistant')) . '</div></div>';

            echo "<div class='col-12'><h4 class='mt-2 mb-0'>PDF</h4></div>";
            echo "<div class='col-md-3'>" . self::switch('pdf_enabled', __('Read attached PDFs', 'ticketassistant'), '', 'mt-md-4') . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Maximum PDFs', 'ticketassistant')) . '</label>' . self::number('pdf_max_count', 0, 10) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Characters per PDF', 'ticketassistant')) . '</label>' . self::number('pdf_max_chars', 500, 50000) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Scanned PDF: pages sent as images', 'ticketassistant')) . '</label>' . self::number('pdf_scan_pages', 0, 5) . '</div>';
            echo "<div class='col-md-6'>" . self::switch('pdf_native', __('Send the PDF file itself to providers that read PDF (OpenAI, Anthropic)', 'ticketassistant'), __('Reads tables and images inside the PDF without poppler-utils; costs more tokens.', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-6'><label class='form-label'>" . Text::e(__('poppler-utils directory (optional)', 'ticketassistant')) . '</label>' . self::text('poppler_path', '/usr/bin')
                . "<div class='form-hint'>" . Text::e(__('Empty: pdftotext and pdftoppm are searched in the PATH of the web server. Without them, a built-in reader extracts the text of digital PDFs; scanned PDFs need pdftoppm or the option above.', 'ticketassistant')) . '</div></div>';

            echo "<div class='col-12'><h4 class='mt-2 mb-0'>" . Text::e(__('Other documents', 'ticketassistant')) . '</h4></div>';
            echo "<div class='col-md-4'>" . self::switch('docs_enabled', __('Read Office/OpenDocument files and text files (logs, CSV, JSON…)', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-4'><label class='form-label'>" . Text::e(__('Maximum documents', 'ticketassistant')) . '</label>' . self::number('docs_max_count', 0, 10) . '</div>';
            echo "<div class='col-md-4'><label class='form-label'>" . Text::e(__('Characters per document', 'ticketassistant')) . '</label>' . self::number('docs_max_chars', 500, 50000) . '</div>';
            echo '</div>';
        });

        self::pane('knowledge', false, function () {
            $config = self::$config;
            echo "<p class='text-muted'>" . Text::e(__('Solved and closed tickets form a library that the assistant uses as references for new tickets and to suggest their classification. The library is synchronized daily by the automatic action "aisync".', 'ticketassistant')) . '</p>';
            echo "<div class='row g-3'>";
            echo "<div class='col-md-4'><label class='form-label'>" . Text::e(__('Similar tickets sent with an analysis', 'ticketassistant')) . '</label>' . self::number('kb_max_results', 0, 10) . '</div>';
            echo "<div class='col-md-4'><label class='form-label'>" . Text::e(__('Minimum similarity of a reference (0–1)', 'ticketassistant')) . '</label>' . self::text('kb_min_similarity')
                . "<div class='form-hint'>" . Text::e(__('Less similar tickets are not sent. Similarity of the words in full-text search (0.10 suits most libraries), of the embeddings in semantic search (0.3–0.5 depending on the model).', 'ticketassistant')) . '</div></div>';
            echo "<div class='col-md-4'>" . self::switch('kb_same_entity', __('Only use solved tickets of the entities of the technician', 'ticketassistant'), '', 'mt-md-4') . '</div>';

            echo "<div class='col-12'><h4 class='mt-2 mb-0'>" . Text::e(__('Learning with the AI (optional)', 'ticketassistant')) . '</h4></div>';
            echo "<div class='col-md-6'>" . self::switch('learn_enabled', __('Distill solved tickets into reusable, anonymized records', 'ticketassistant'), __('Generic summary, resolution steps, keywords and the classification the AI would give: it improves the references and points to misclassified tickets. Automatic action "ailearn".', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Tickets per run', 'ticketassistant')) . '</label>' . self::number('learn_batch', 1, 500) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Daily budget of automatic actions (US$)', 'ticketassistant')) . '</label>' . self::text('background_budget_usd') . '</div>';

            echo "<div class='col-12'><h4 class='mt-2 mb-0'>" . Text::e(__('Semantic search (embeddings, optional)', 'ticketassistant')) . '</h4></div>';
            echo "<div class='col-md-4'>" . self::switch('embeddings_enabled', __('Enable semantic search', 'ticketassistant'), __('Finds similar tickets written with different words. Needs an embeddings model.', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-4'><label class='form-label'>" . Text::e(__('Embeddings model', 'ticketassistant')) . '</label>' . self::text('embedding_model', (string) (Providers::get((string) $config['provider'])['embedding_hint'] ?? '')) . '</div>';
            echo "<div class='col-md-2'><label class='form-label'>" . Text::e(__('Dimensions', 'ticketassistant')) . '</label>' . self::number('embedding_dimensions', 0, 8192)
                . "<div class='form-hint'>" . Text::e(__('0 = model default', 'ticketassistant')) . '</div></div>';
            echo "<div class='col-md-2'><label class='form-label'>" . Text::e(__('Recent tickets scanned', 'ticketassistant')) . '</label>' . self::number('embeddings_scan_limit', 0, 50000) . '</div>';
            echo "<div class='col-md-12'>" . self::switch('emb_separate', __('Use another connection for embeddings (required with Anthropic, which has no embeddings API)', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Provider', 'ticketassistant')) . '</label>';
            echo "<select class='form-select' name='emb_provider'>";
            foreach (Providers::all() as $id => $preset) {
                if (!empty($preset['embeddings'])) {
                    echo "<option value='" . Text::e($id) . "'" . ($config['emb_provider'] === $id ? ' selected' : '') . '>' . Text::e((string) $preset['label']) . '</option>';
                }
            }
            echo '</select></div>';
            echo "<div class='col-md-4'><label class='form-label'>" . Text::e(__('API address', 'ticketassistant')) . '</label>' . self::text('emb_base_url') . '</div>';
            echo "<div class='col-md-2'><label class='form-label'>" . Text::e(__('API version', 'ticketassistant')) . '</label>' . self::text('emb_api_version') . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('API key', 'ticketassistant')) . '</label>';
            echo "<input type='password' class='form-control' name='emb_api_key' value='' autocomplete='new-password' placeholder='"
                . Text::e((string) $config['emb_api_key'] !== '' ? __('•••••••• (key saved)', 'ticketassistant') : '') . "'></div>";

            echo "<div class='col-12'><h4 class='mt-2 mb-0'>" . Text::e(__('Classification', 'ticketassistant')) . '</h4></div>';
            echo "<div class='col-md-6'>" . self::switch('classify_use_llm', __('Let the AI choose among the categories of similar tickets', 'ticketassistant'), __('Without it the suggestion only comes from the vote of similar solved tickets (free and instant).', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Neighbours considered', 'ticketassistant')) . '</label>' . self::number('classify_neighbors', 5, 100) . '</div>';

            echo "<div class='col-12'><h4 class='mt-2 mb-0'>" . Text::e(__('Automatic triage of new tickets', 'ticketassistant')) . '</h4></div>';
            echo "<div class='col-md-4'>" . self::switch('triage_enabled', __('Suggest a classification when a ticket is created', 'ticketassistant'), __('Automatic action "aitriage", every 5 minutes. The suggestion is shown on the ticket.', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-4'>" . self::switch('triage_only_uncategorized', __('Only tickets created without category', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-4'>" . self::switch('triage_use_llm', __('Use the AI for automatic triage (costs tokens)', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-4'>" . self::switch('triage_auto_apply', __('Apply automatically above the threshold', 'ticketassistant'), __('Measure the accuracy first (Learning page) before enabling it.', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Confidence threshold (0–1)', 'ticketassistant')) . '</label>' . self::text('triage_threshold') . '</div>';
            echo '</div>';
        });

        self::pane('privacy', false, function () {
            $config   = self::$config;
            $selected = Config::maskDetectors($config);
            echo "<p class='text-muted'>" . Text::e(__('Masking applies to the text sent to the AI (ticket, history, extracted documents, drafts) and to the library of solved tickets. Images are sent as they are: disable them if your policy requires it.', 'ticketassistant')) . '</p>';
            echo "<input type='hidden' name='_present_mask_detectors' value='1'>";
            echo "<div class='row g-2'>";
            foreach (Privacy::detectors() as $id => $label) {
                echo "<div class='col-md-6'><label class='form-check'>"
                    . "<input class='form-check-input' type='checkbox' name='mask_detectors[]' value='" . Text::e($id) . "'" . (in_array($id, $selected, true) ? ' checked' : '') . '>'
                    . "<span class='form-check-label'>" . Text::e($label) . '</span></label></div>';
            }
            echo '</div>';
            echo "<div class='mt-3'><label class='form-label'>" . Text::e(__('Custom patterns (one per line: LABEL=/regular expression/)', 'ticketassistant')) . '</label>';
            echo "<textarea class='form-control font-monospace' name='mask_custom' rows='4' placeholder='EMPLOYEE_ID=/\\bEMP-\\d{6}\\b/'>" . Text::e($config['mask_custom']) . '</textarea></div>';
            echo "<div class='row g-3 mt-1'>";
            echo "<div class='col-md-6'>" . self::switch('send_requester_name', __("Send the requester's name (greeting by first name)", 'ticketassistant')) . '</div>';
            echo "<div class='col-md-6'>" . self::switch('log_responses', __('Keep the generated texts in the log (audit and reuse of recent analyses)', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Keep generated texts (days)', 'ticketassistant')) . '</label>' . self::number('response_retention_days', 0, 3650)
                . "<div class='form-hint'>" . Text::e(__('0 = forever', 'ticketassistant')) . '</div></div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Keep log rows (days)', 'ticketassistant')) . '</label>' . self::number('log_retention_days', 0, 3650)
                . "<div class='form-hint'>" . Text::e(__('0 = forever. Automatic action "aipurge".', 'ticketassistant')) . '</div></div>';
            echo '</div>';
        });

        self::pane('costs', false, function () {
            echo "<div class='row g-3'>";
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Input price (US$ per million tokens)', 'ticketassistant')) . '</label>' . self::text('price_input') . '</div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Output price (US$ per million tokens)', 'ticketassistant')) . '</label>' . self::text('price_output') . '</div>';
            echo "<div class='col-md-6'><div class='alert alert-info mb-0'>" . Text::e(__('Costs are estimated with these prices: keep them up to date with the price list of your model, otherwise the budgets cannot work. Local models: 0.', 'ticketassistant')) . '</div></div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Daily budget of the technicians (US$)', 'ticketassistant')) . '</label>' . self::text('daily_budget_usd')
                . "<div class='form-hint'>" . Text::e(__('0 = no budget', 'ticketassistant')) . '</div></div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Uses per technician per hour', 'ticketassistant')) . '</label>' . self::number('user_hourly_limit', 0, 1000)
                . "<div class='form-hint'>" . Text::e(__('0 = no limit', 'ticketassistant')) . '</div></div>';
            echo "<div class='col-md-3'><label class='form-label'>" . Text::e(__('Interval between two analyses of a ticket (s)', 'ticketassistant')) . '</label>' . self::number('ticket_cooldown_seconds', 0, 3600)
                . "<div class='form-hint'>" . Text::e(__('During the interval the last analysis is shown again.', 'ticketassistant')) . '</div></div>';
            echo '</div>';
        });

        self::pane('style', false, function () {
            $config = self::$config;
            echo "<div class='row g-3'>";
            echo "<div class='col-md-6'><label class='form-label'>" . Text::e(__('Organization name (used in the prompts)', 'ticketassistant')) . '</label>' . self::text('organization_name', __('e.g. ACME IT service desk', 'ticketassistant')) . '</div>';
            echo "<div class='col-md-6'><label class='form-label'>" . Text::e(__('Language of the answers', 'ticketassistant')) . '</label>' . self::select('response_language', Prompt::languageLabels()) . '</div>';
            echo "<div class='col-12'><label class='form-label'>" . Text::e(__('Communication standard for the messages to the requester', 'ticketassistant')) . '</label>';
            echo "<textarea class='form-control' name='style_guide' rows='6'>" . Text::e($config['style_guide']) . '</textarea></div>';
            echo "<div class='col-12'><label class='form-label'>" . Text::e(__('Signature', 'ticketassistant')) . '</label>' . self::text('signature_template')
                . "<div class='form-hint'>" . Text::e(__('Added by the server to every reply. Placeholders: {name}, {firstname}, {entity}, {organization}. Allowed HTML: <br>, <strong>, <em>. Empty = no signature.', 'ticketassistant')) . '</div></div>';
            echo "<div class='col-12'><label class='form-label'>" . Text::e(__('Additional instructions for the AI', 'ticketassistant')) . '</label>';
            echo "<textarea class='form-control' name='extra_instructions' rows='5' placeholder='"
                . Text::e(__("- Never repeat passwords or credentials written in the ticket.\n- Ask for at most 4 pieces of information per message.\n- When the solution depends on a supplier, tell the requester that the case was forwarded.", 'ticketassistant'))
                . "'>" . Text::e($config['extra_instructions']) . '</textarea>';
            echo "<div class='form-hint'>" . Text::e(__('Instructions per category are set in Setup > Dropdowns > ITIL categories > "AI assistant" tab.', 'ticketassistant')) . '</div></div>';
            echo '</div>';
        });

        self::pane('diagnosis', false, function () {
            self::diagnosis();
        });

        echo '</div>'; // tab-content

        echo "<div class='mb-4'><button type='submit' name='update_config' value='1' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>"
            . Text::e(__('Save', 'ticketassistant')) . '</button></div>';
        Html::closeForm();

        echo '</div>';

        // Settings for public/js/config.js
        echo "<script type='application/json' id='ticketassistant-config-settings'>" . str_replace('</', '<\/', (string) json_encode([
            'adminUrl' => Compat::url('ajax/admin.php'),
            'i18n'     => [
                'testing'      => __('Testing…', 'ticketassistant'),
                'listing'      => __('Listing models…', 'ticketassistant'),
                'models_found' => __('%d models found: choose one in the Model field.', 'ticketassistant'),
                'no_models'    => __('The provider returned no model list.', 'ticketassistant'),
                'save_first'   => __('Save the settings before testing them.', 'ticketassistant'),
                'http_error'   => __('Request failed (HTTP %s).', 'ticketassistant'),
                'docs'         => __('Documentation of the provider', 'ticketassistant'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)) . '</script>';
        echo "<script src='" . Text::e(Compat::assetUrl('js/config.js')) . "'></script>";
    }

    private static function pane(string $id, bool $active, callable $content): void
    {
        echo "<div class='tab-pane fade" . ($active ? ' show active' : '') . "' id='ticketassistant-tab-" . $id . "' role='tabpanel'>";
        $content();
        echo '</div>';
    }

    private static function switch(string $key, string $label, string $hint = '', string $class = ''): string
    {
        $checked = !empty(self::$config[$key]) ? ' checked' : '';
        return "<input type='hidden' name='_present_" . Text::e($key) . "' value='1'>"
            . "<label class='form-check form-switch " . Text::e($class) . "'>"
            . "<input class='form-check-input' type='checkbox' name='" . Text::e($key) . "' value='1'" . $checked . '>'
            . "<span class='form-check-label'>" . Text::e($label) . '</span>'
            . ($hint !== '' ? "<span class='form-hint d-block'>" . Text::e($hint) . '</span>' : '')
            . '</label>';
    }

    /**
     * @param array<string, string> $options
     */
    private static function select(string $key, array $options): string
    {
        $out = "<select class='form-select' name='" . Text::e($key) . "'>";
        foreach ($options as $value => $label) {
            $selected = (string) (self::$config[$key] ?? '') === (string) $value ? ' selected' : '';
            $out     .= "<option value='" . Text::e($value) . "'" . $selected . '>' . Text::e($label) . '</option>';
        }
        return $out . '</select>';
    }

    private static function number(string $key, int $min, int $max, string $step = '1'): string
    {
        return "<input type='number' class='form-control' name='" . Text::e($key) . "' value='" . Text::e(self::$config[$key] ?? '')
            . "' min='" . $min . "' max='" . $max . "' step='" . Text::e($step) . "'>";
    }

    private static function text(string $key, string $placeholder = ''): string
    {
        return "<input type='text' class='form-control' name='" . Text::e($key) . "' value='" . Text::e(self::$config[$key] ?? '') . "'"
            . ($placeholder !== '' ? " placeholder='" . Text::e($placeholder) . "'" : '') . '>';
    }

    /**
     * Environment checks.
     */
    private static function diagnosis(): void
    {
        $config = self::$config;
        $checks = [];

        $checks[] = [__('GLPI version', 'ticketassistant'), defined('GLPI_VERSION') ? GLPI_VERSION : '?', true];
        $checks[] = [__('PHP version', 'ticketassistant'), PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>=')];
        $checks[] = ['curl', extension_loaded('curl') ? __('available', 'ticketassistant') : __('missing', 'ticketassistant'), extension_loaded('curl')];
        $checks[] = ['GD ' . __('(image reduction)', 'ticketassistant'), function_exists('imagecreatefromstring') ? __('available', 'ticketassistant') : __('missing', 'ticketassistant'), function_exists('imagecreatefromstring')];
        $checks[] = ['zip ' . __('(Office documents)', 'ticketassistant'), DocumentReader::officeSupported() ? __('available', 'ticketassistant') : __('missing: Office documents are listed by name only', 'ticketassistant'), DocumentReader::officeSupported()];
        $checks[] = ['shell_exec ' . __('(poppler-utils)', 'ticketassistant'), DocumentReader::shellAvailable() ? __('allowed', 'ticketassistant') : __('disabled in php.ini', 'ticketassistant'), true];
        $path     = DocumentReader::popplerBinary('pdftotext', (string) $config['poppler_path']);
        $checks[] = ['pdftotext', $path !== '' ? $path : __('not found: the built-in reader extracts the text of digital PDFs (poppler-utils handles complex layouts better)', 'ticketassistant'), true];
        $path     = DocumentReader::popplerBinary('pdftoppm', (string) $config['poppler_path']);
        $checks[] = ['pdftoppm', $path !== '' ? $path : __('not found: scanned PDFs are not read (install poppler-utils, or send the PDF itself to providers that read PDF)', 'ticketassistant'), $path !== ''];
        $proxy    = Compat::glpiProxyUrl();
        $checks[] = [__('GLPI proxy', 'ticketassistant'), $proxy !== null ? preg_replace('#//[^@/]*@#', '//***@', $proxy) : __('none', 'ticketassistant'), true];

        $stats    = CaseLibrary::stats();
        $checks[] = [__('Library of solved tickets', 'ticketassistant'), sprintf(
            __('%1$d tickets, %2$d distilled, %3$d with embeddings — last synchronization: %4$s', 'ticketassistant'),
            $stats['cases'],
            $stats['distilled'],
            $stats['embedded'],
            $stats['last_sync'] ?? '-'
        ), $stats['cases'] > 0];

        foreach (self::cronStates() as $name => $state) {
            $checks[] = [sprintf(__('Automatic action %s', 'ticketassistant'), $name), $state[0], $state[1]];
        }

        echo "<table class='table table-sm'><tbody>";
        foreach ($checks as [$label, $value, $ok]) {
            echo '<tr><td class="w-25">' . Text::e($label) . '</td><td>'
                . ($ok ? "<i class='ti ti-circle-check text-success me-1'></i>" : "<i class='ti ti-alert-triangle text-warning me-1'></i>")
                . Text::e($value) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo "<p class='text-muted mb-0'>" . Text::e(__('Automatic actions must run in CLI mode: the cron of the server (a scheduled task on Windows) runs php front/cron.php every minute.', 'ticketassistant')) . '</p>';
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    private static function cronStates(): array
    {
        $out = [];
        foreach (Diagnostics::cronTasks() as $name => $task) {
            $out[$name] = [
                ($task['active'] ? __('enabled', 'ticketassistant') : __('disabled', 'ticketassistant')) . ' — ' . ($task['cli'] ? 'CLI' : 'GLPI') . ' — '
                . __('last run', 'ticketassistant') . ': ' . ($task['lastrun'] ?? '-'),
                $task['active'],
            ];
        }
        return $out;
    }
}
