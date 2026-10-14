<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use Config as GlpiConfig;
use GLPIKey;
use GlpiPlugin\Ticketassistant\Llm\Client;
use GlpiPlugin\Ticketassistant\Llm\Providers;

/**
 * Plugin configuration, stored in glpi_configs (context "plugin:ticketassistant").
 *
 * Secrets (API keys) are encrypted by GLPI with the key of the installation
 * (hook "secured_configs") and decrypted here, in memory only.
 */
final class Config
{
    public const CONTEXT = 'plugin:ticketassistant';

    /** Encrypted values */
    public const SECRETS = ['api_key', 'emb_api_key'];

    /** @var array<string, mixed>|null request cache */
    private static $cache = null;

    public static function getTypeName($nb = 0)
    {
        return __('AI assistant', 'ticketassistant');
    }

    /**
     * Default values. Some depend on the default language of GLPI, so that
     * a Brazilian installation starts with CPF/CNPJ masking and Portuguese
     * greetings, an English one with English greetings, etc.
     *
     * @return array<string, mixed>
     */
    public static function getDefaults(?string $language = null): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $language = $language ?? (string) ($CFG_GLPI['language'] ?? 'en_GB');

        return [
            // General
            'enabled'                   => 0,
            'action_analyze'            => 1,
            'action_improve'            => 1,
            'action_classify'           => 1,
            'action_article'            => 1,

            // Connection
            'provider'                  => 'openai',
            'base_url'                  => '',
            'api_key'                   => '',
            'model'                     => '',
            'api_version'               => '',
            'model_type'                => 'reasoning',
            'token_param'               => 'auto',
            'json_mode'                 => 'auto',
            'vision'                    => 1,
            'image_detail'              => 'auto',
            'http_timeout'              => 90,
            'http_retries'              => 2,
            'use_glpi_proxy'            => 1,
            'price_input'               => '0.00',
            'price_output'              => '0.00',

            // Generation
            'analyze_max_tokens'        => 4096,
            'analyze_effort'            => 'medium',
            'improve_max_tokens'        => 2048,
            'improve_effort'            => 'low',
            'classify_max_tokens'       => 1024,
            'classify_effort'           => 'low',
            'learn_max_tokens'          => 1536,
            'learn_effort'              => 'low',
            'article_max_tokens'        => 3072,
            'article_effort'            => 'low',
            'temperature'               => '0.3',

            // Context and attachments
            'max_context_chars'         => 24000,
            'private_items'             => TicketContext::PRIVATE_RIGHTS,
            'context_linked_items'      => 1,
            'send_requester_name'       => 1,
            'images_enabled'            => 1,
            'images_max_count'          => 4,
            'images_max_mb'             => 4,
            'images_max_px'             => 2048,
            'pdf_enabled'               => 1,
            'pdf_max_count'             => 2,
            'pdf_max_chars'             => 4000,
            'pdf_scan_pages'            => 2,
            'pdf_native'                => 0,
            'docs_enabled'              => 1,
            'docs_max_count'            => 3,
            'docs_max_chars'            => 4000,
            'poppler_path'              => '',
            'solution_templates'        => 1,

            // Knowledge: solved tickets library
            'kb_max_results'            => 3,
            'kb_min_similarity'         => '0.10',
            'kb_same_entity'            => 1,
            'learn_enabled'             => 0,
            'learn_batch'               => 20,
            'background_budget_usd'     => '1.00',
            'embeddings_enabled'        => 0,
            'embedding_model'           => '',
            'embedding_dimensions'      => 0,
            'embeddings_scan_limit'     => 3000,
            'emb_separate'              => 0,
            'emb_provider'              => 'openai',
            'emb_base_url'              => '',
            'emb_api_key'               => '',
            'emb_api_version'           => '',

            // Classification
            'classify_use_llm'          => 1,
            'classify_neighbors'        => 25,
            'triage_enabled'            => 0,
            'triage_use_llm'            => 0,
            'triage_only_uncategorized' => 1,
            'triage_auto_apply'         => 0,
            'triage_threshold'          => '0.85',

            // Privacy
            'mask_detectors'            => implode(',', Privacy::defaultDetectors($language)),
            'mask_custom'               => '',

            // Costs and limits
            'daily_budget_usd'          => '5.00',
            'user_hourly_limit'         => 20,
            'ticket_cooldown_seconds'   => 60,
            'log_responses'             => 1,
            'response_retention_days'   => 90,
            'log_retention_days'        => 365,

            // Personalization
            'organization_name'         => '',
            'response_language'         => array_key_exists($language, Prompt::languages()) ? $language : 'auto',
            'style_guide'               => Prompt::defaultStyleGuide($language),
            'signature_template'        => self::defaultSignature($language),
            'extra_instructions'        => '',

            // Internal
            'last_evaluation'           => '',
        ];
    }

    public static function defaultSignature(string $language): string
    {
        switch (substr($language, 0, 2)) {
            case 'pt':
                return 'Atenciosamente,<br>{name}';
            case 'es':
                return 'Atentamente,<br>{name}';
            case 'fr':
                return 'Cordialement,<br>{name}';
            case 'de':
                return 'Mit freundlichen Grüßen<br>{name}';
            case 'it':
                return 'Cordiali saluti,<br>{name}';
            default:
                return 'Best regards,<br>{name}';
        }
    }

    /**
     * Effective configuration (defaults + database), secrets decrypted.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(bool $refresh = false): array
    {
        if (self::$cache !== null && !$refresh) {
            return self::$cache;
        }

        $values = GlpiConfig::getConfigurationValues(self::CONTEXT);
        $config = array_merge(self::getDefaults(), is_array($values) ? $values : []);

        // Values are stored raw on every GLPI version (see store())
        foreach (self::SECRETS as $key) {
            $config[$key] = self::decrypt((string) ($config[$key] ?? ''));
        }

        return self::$cache = $config;
    }

    private static function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            $decrypted = (new GLPIKey())->decrypt($value);
        } catch (\Throwable $e) {
            return '';
        }
        return is_string($decrypted) ? $decrypted : '';
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Ready to call the AI (enabled + usable connection). Fail-open: when
     * false, the buttons are simply not displayed.
     */
    public static function isReady(?array $config = null): bool
    {
        $config = $config ?? self::getConfig();
        return !empty($config['enabled']) && !count(Providers::missingSettings(self::connectionSettings($config)));
    }

    /**
     * @return string[]
     */
    public static function maskDetectors(array $config): array
    {
        $ids   = array_filter(array_map('trim', explode(',', (string) ($config['mask_detectors'] ?? ''))));
        $known = array_keys(Privacy::detectors());
        return array_values(array_intersect($ids, $known));
    }

    /**
     * Settings of the chat connection, for Llm\Client.
     *
     * @return array<string, mixed>
     */
    public static function connectionSettings(array $config): array
    {
        return [
            'provider'        => (string) $config['provider'],
            'base_url'        => (string) $config['base_url'],
            'api_key'         => (string) $config['api_key'],
            'model'           => (string) $config['model'],
            'api_version'     => (string) $config['api_version'],
            'model_type'      => (string) $config['model_type'],
            'token_param'     => (string) $config['token_param'],
            'json_mode'       => (string) $config['json_mode'],
            'vision'          => (int) $config['vision'],
            'native_pdf'      => (int) $config['pdf_native'],
            'image_detail'    => (string) $config['image_detail'],
            'embedding_model' => (string) $config['embedding_model'],
            'timeout'         => (int) $config['http_timeout'],
            'retries'         => (int) $config['http_retries'],
            'price_input'     => (float) $config['price_input'],
            'price_output'    => (float) $config['price_output'],
            'proxy'           => !empty($config['use_glpi_proxy']) ? Compat::glpiProxyUrl() : null,
            'url_guard'       => [Compat::class, 'isOutboundUrlAllowed'],
        ];
    }

    /**
     * Settings of the embeddings connection (the chat one, or a separate one
     * when the chat provider has no embeddings API, e.g. Anthropic).
     *
     * @return array<string, mixed>
     */
    public static function embeddingSettings(array $config): array
    {
        $settings = self::connectionSettings($config);
        if (!empty($config['emb_separate'])) {
            $settings['provider']    = (string) $config['emb_provider'];
            $settings['base_url']    = (string) $config['emb_base_url'];
            $settings['api_key']     = (string) $config['emb_api_key'];
            $settings['api_version'] = (string) $config['emb_api_version'];
        }
        // the chat model is irrelevant for embeddings but required by the checks
        $settings['model'] = (string) $config['embedding_model'];
        return $settings;
    }

    public static function chatClient(?array $config = null): Client
    {
        return new Client(self::connectionSettings($config ?? self::getConfig()));
    }

    public static function embeddingClient(?array $config = null): ?Client
    {
        $config = $config ?? self::getConfig();
        if (empty($config['embeddings_enabled']) || trim((string) $config['embedding_model']) === '') {
            return null;
        }
        $client = new Client(self::embeddingSettings($config));
        return $client->supportsEmbeddings() ? $client : null;
    }

    /**
     * Writes the missing keys only (a reinstallation keeps the configuration).
     */
    public static function installDefaults(): void
    {
        $existing = GlpiConfig::getConfigurationValues(self::CONTEXT);
        $missing  = array_diff_key(self::getDefaults(), is_array($existing) ? $existing : []);
        if (count($missing)) {
            GlpiConfig::setConfigurationValues(self::CONTEXT, Compat::configInput($missing, self::SECRETS));
        }
    }

    /**
     * Stores values (already validated, raw strings). They are kept raw in
     * the database on every GLPI version: escaped for the SQL layer of
     * GLPI 10, passed as is to GLPI 11+, never HTML-encoded.
     */
    public static function store(array $values): void
    {
        $values = array_intersect_key($values, self::getDefaults());
        if (count($values)) {
            GlpiConfig::setConfigurationValues(self::CONTEXT, Compat::configInput($values, self::SECRETS));
        }
        self::clearCache();
    }

    /**
     * Validates and stores the configuration form.
     *
     * @param array $post raw request values (Compat::rawPost())
     *
     * @return string[] warnings to display
     */
    public static function saveFromForm(array $post): array
    {
        $defaults = self::getDefaults();
        $values   = [];
        $warnings = [];

        $checkboxes = [
            'enabled', 'action_analyze', 'action_improve', 'action_classify', 'action_article',
            'vision', 'use_glpi_proxy', 'context_linked_items', 'send_requester_name',
            'images_enabled', 'pdf_enabled', 'pdf_native', 'docs_enabled', 'solution_templates',
            'kb_same_entity', 'learn_enabled', 'embeddings_enabled', 'emb_separate',
            'classify_use_llm', 'triage_enabled', 'triage_use_llm', 'triage_only_uncategorized', 'triage_auto_apply',
            'log_responses',
        ];
        // A checkbox is only updated when the form rendered it (hidden marker)
        foreach ($checkboxes as $key) {
            if (isset($post['_present_' . $key])) {
                $values[$key] = !empty($post[$key]) ? 1 : 0;
            }
        }

        $ints = [
            'http_timeout'            => [10, 600],
            'http_retries'            => [0, 5],
            'analyze_max_tokens'      => [256, 64000],
            'improve_max_tokens'      => [256, 64000],
            'classify_max_tokens'     => [128, 16000],
            'learn_max_tokens'        => [256, 16000],
            'article_max_tokens'      => [256, 64000],
            'max_context_chars'       => [4000, 400000],
            'images_max_count'        => [0, 20],
            'images_max_mb'           => [1, 20],
            'images_max_px'           => [512, 4096],
            'pdf_max_count'           => [0, 10],
            'pdf_max_chars'           => [500, 50000],
            'pdf_scan_pages'          => [0, 5],
            'docs_max_count'          => [0, 10],
            'docs_max_chars'          => [500, 50000],
            'kb_max_results'          => [0, 10],
            'learn_batch'             => [1, 500],
            'embedding_dimensions'    => [0, 8192],
            'embeddings_scan_limit'   => [0, 50000],
            'classify_neighbors'      => [5, 100],
            'user_hourly_limit'       => [0, 1000],
            'ticket_cooldown_seconds' => [0, 3600],
            'response_retention_days' => [0, 3650],
            'log_retention_days'      => [0, 3650],
        ];
        foreach ($ints as $key => [$min, $max]) {
            if (isset($post[$key]) && $post[$key] !== '') {
                $values[$key] = max($min, min($max, (int) $post[$key]));
            }
        }

        $decimals = [
            'price_input'           => [0, 1000, 4],
            'price_output'          => [0, 1000, 4],
            'daily_budget_usd'      => [0, 100000, 2],
            'background_budget_usd' => [0, 100000, 2],
            'temperature'           => [0, 2, 2],
            'triage_threshold'      => [0, 1, 2],
            'kb_min_similarity'     => [0, 1, 2],
        ];
        foreach ($decimals as $key => [$min, $max, $precision]) {
            if (isset($post[$key]) && $post[$key] !== '') {
                $number       = (float) str_replace(',', '.', (string) $post[$key]);
                $values[$key] = number_format(max($min, min($max, $number)), $precision, '.', '');
            }
        }

        $choices = [
            'provider'          => array_keys(Providers::all()),
            'emb_provider'      => array_keys(Providers::all()),
            'model_type'        => ['reasoning', 'standard'],
            'token_param'       => ['auto', 'max_completion_tokens', 'max_tokens'],
            'json_mode'         => ['auto', 'json_schema', 'json_object', 'prompt'],
            'image_detail'      => ['auto', 'low', 'high'],
            'private_items'     => [TicketContext::PRIVATE_RIGHTS, TicketContext::PRIVATE_ALL, TicketContext::PRIVATE_NONE],
            'response_language' => array_keys(Prompt::languages()),
        ];
        foreach (['analyze', 'improve', 'classify', 'learn', 'article'] as $action) {
            $choices[$action . '_effort'] = ['', 'none', 'minimal', 'low', 'medium', 'high'];
        }
        foreach ($choices as $key => $allowed) {
            if (isset($post[$key]) && in_array((string) $post[$key], $allowed, true)) {
                $values[$key] = (string) $post[$key];
            }
        }

        $texts = [
            'base_url', 'model', 'api_version', 'embedding_model', 'emb_base_url', 'emb_api_version',
            'poppler_path', 'organization_name', 'style_guide', 'signature_template', 'extra_instructions',
            'mask_custom',
        ];
        foreach ($texts as $key) {
            if (isset($post[$key])) {
                $values[$key] = trim((string) $post[$key]);
            }
        }
        foreach (['base_url', 'emb_base_url'] as $key) {
            if (isset($values[$key])) {
                $values[$key] = rtrim($values[$key], '/');
                if ($values[$key] !== '' && !preg_match('#^https?://#i', $values[$key])) {
                    $warnings[] = sprintf(__('The address "%s" must start with http:// or https://.', 'ticketassistant'), $values[$key]);
                    unset($values[$key]);
                }
            }
        }
        if (isset($values['poppler_path']) && $values['poppler_path'] !== '' && !is_dir($values['poppler_path'])) {
            $warnings[] = __('The poppler-utils directory does not exist on the server: PDF reading will use the system PATH.', 'ticketassistant');
        }

        if (isset($post['mask_detectors']) || isset($post['_present_mask_detectors'])) {
            $selected = array_intersect((array) ($post['mask_detectors'] ?? []), array_keys(Privacy::detectors()));
            $values['mask_detectors'] = implode(',', $selected);
        }
        if (isset($values['mask_custom'])) {
            foreach (Privacy::invalidCustomPatterns($values['mask_custom']) as $invalid) {
                $warnings[] = sprintf(__('Invalid masking pattern ignored: %s', 'ticketassistant'), $invalid);
            }
        }

        // A blank secret field keeps the stored secret; a checkbox clears it
        foreach (self::SECRETS as $key) {
            if (!empty($post['_clear_' . $key])) {
                $values[$key] = '';
            } elseif (isset($post[$key]) && trim((string) $post[$key]) !== '') {
                $values[$key] = trim((string) $post[$key]);
            }
        }

        self::store(array_intersect_key($values, $defaults));
        return $warnings;
    }

    /**
     * Configuration page (Setup > AI Ticket Assistant).
     */
    public static function showConfigForm(): void
    {
        ConfigForm::show(self::getConfig(true));
    }
}
