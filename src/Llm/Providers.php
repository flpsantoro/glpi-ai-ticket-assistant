<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Llm;

/**
 * Provider presets: they only pre-fill sensible defaults, every value can
 * be overridden in the configuration ("Advanced" section).
 */
final class Providers
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'openai' => [
                'label'           => 'OpenAI',
                'driver'          => 'openai',
                'base_url'        => 'https://api.openai.com/v1',
                'key_required'    => true,
                'token_param'     => 'max_completion_tokens',
                'json_mode'       => 'json_schema',
                'model_hint'      => 'gpt-5-mini, gpt-5.4, gpt-4.1-mini',
                'embeddings'      => true,
                'embedding_hint'  => 'text-embedding-3-small',
                'list_models'     => true,
                'native_pdf'      => true,
                'docs'            => 'https://platform.openai.com/docs/models',
            ],
            'azure' => [
                'label'           => 'Azure OpenAI',
                'driver'          => 'azure',
                'base_url'        => '',
                'base_url_hint'   => 'https://<resource>.openai.azure.com',
                'key_required'    => true,
                'token_param'     => 'max_completion_tokens',
                'json_mode'       => 'json_object',
                'api_version'     => '2024-10-21',
                'model_hint'      => __('deployment name', 'ticketassistant'),
                'embeddings'      => true,
                'embedding_hint'  => __('embeddings deployment name', 'ticketassistant'),
                'list_models'     => false,
                'docs'            => 'https://learn.microsoft.com/azure/ai-services/openai/',
            ],
            'anthropic' => [
                'label'           => 'Anthropic (Claude)',
                'driver'          => 'anthropic',
                'base_url'        => 'https://api.anthropic.com',
                'key_required'    => true,
                'token_param'     => 'max_tokens',
                'json_mode'       => 'prompt',
                'model_hint'      => 'claude-sonnet-5-5, claude-haiku-5-5',
                'embeddings'      => false,
                'list_models'     => true,
                'native_pdf'      => true,
                'docs'            => 'https://docs.anthropic.com/',
            ],
            'gemini' => [
                'label'           => 'Google Gemini',
                'driver'          => 'openai',
                'base_url'        => 'https://generativelanguage.googleapis.com/v1beta/openai',
                'key_required'    => true,
                'token_param'     => 'max_tokens',
                'json_mode'       => 'json_object',
                'model_hint'      => 'gemini-2.5-flash, gemini-2.5-pro',
                'embeddings'      => true,
                'embedding_hint'  => 'gemini-embedding-001',
                'list_models'     => true,
                'docs'            => 'https://ai.google.dev/gemini-api/docs/openai',
            ],
            'ollama' => [
                'label'           => 'Ollama (' . __('local models', 'ticketassistant') . ')',
                'driver'          => 'openai',
                'base_url'        => 'http://localhost:11434/v1',
                'key_required'    => false,
                'token_param'     => 'max_tokens',
                'json_mode'       => 'json_object',
                'model_hint'      => 'qwen2.5vl, gemma3, llama3.2-vision',
                'embeddings'      => true,
                'embedding_hint'  => 'nomic-embed-text, bge-m3',
                'list_models'     => true,
                'docs'            => 'https://github.com/ollama/ollama/blob/main/docs/openai.md',
            ],
            'openrouter' => [
                'label'           => 'OpenRouter',
                'driver'          => 'openai',
                'base_url'        => 'https://openrouter.ai/api/v1',
                'key_required'    => true,
                'token_param'     => 'max_tokens',
                'json_mode'       => 'json_object',
                'model_hint'      => 'openai/gpt-5-mini, anthropic/claude-sonnet-5-5',
                'embeddings'      => false,
                'list_models'     => true,
                'docs'            => 'https://openrouter.ai/docs',
            ],
            'mistral' => [
                'label'           => 'Mistral AI',
                'driver'          => 'openai',
                'base_url'        => 'https://api.mistral.ai/v1',
                'key_required'    => true,
                'token_param'     => 'max_tokens',
                'json_mode'       => 'json_object',
                'model_hint'      => 'mistral-medium-latest, pixtral-large-latest',
                'embeddings'      => true,
                'embedding_hint'  => 'mistral-embed',
                'list_models'     => true,
                'docs'            => 'https://docs.mistral.ai/',
            ],
            'compatible' => [
                'label'           => __('Other OpenAI-compatible API', 'ticketassistant')
                    . ' (LM Studio, vLLM, LiteLLM, Groq, DeepSeek…)',
                'driver'          => 'openai',
                'base_url'        => '',
                'base_url_hint'   => 'http://localhost:1234/v1',
                'key_required'    => false,
                'token_param'     => 'max_tokens',
                'json_mode'       => 'json_object',
                'model_hint'      => '',
                'embeddings'      => true,
                'list_models'     => true,
                'docs'            => '',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $provider): array
    {
        $all = self::all();
        return $all[$provider] ?? $all['openai'];
    }

    public static function exists(string $provider): bool
    {
        return array_key_exists($provider, self::all());
    }

    /**
     * Completes connection settings with the defaults of their preset.
     *
     * Input keys (all optional): provider, base_url, api_key, model,
     * api_version, model_type, token_param, json_mode, vision, native_pdf,
     * image_detail, embedding_model, timeout, retries, price_input,
     * price_output, proxy, url_guard (callable: string $url => bool).
     *
     * @return array<string, mixed>
     */
    public static function resolve(array $settings): array
    {
        $provider = (string) ($settings['provider'] ?? 'openai');
        if (!self::exists($provider)) {
            $provider = 'openai';
        }
        $preset = self::get($provider);

        $resolved = [
            'provider'        => $provider,
            'driver'          => $preset['driver'],
            'base_url'        => trim((string) ($settings['base_url'] ?? '')),
            'api_key'         => (string) ($settings['api_key'] ?? ''),
            'model'           => trim((string) ($settings['model'] ?? '')),
            'api_version'     => trim((string) ($settings['api_version'] ?? '')),
            'model_type'      => ($settings['model_type'] ?? 'reasoning') === 'standard' ? 'standard' : 'reasoning',
            'token_param'     => (string) ($settings['token_param'] ?? 'auto'),
            'json_mode'       => (string) ($settings['json_mode'] ?? 'auto'),
            'vision'          => !empty($settings['vision']),
            'native_pdf'      => !empty($settings['native_pdf']) && !empty($preset['native_pdf']),
            'image_detail'    => (string) ($settings['image_detail'] ?? 'auto'),
            'embedding_model' => trim((string) ($settings['embedding_model'] ?? '')),
            'timeout'         => max(5, (int) ($settings['timeout'] ?? 90)),
            'retries'         => max(0, min(5, (int) ($settings['retries'] ?? 2))),
            'price_input'     => max(0.0, (float) ($settings['price_input'] ?? 0)),
            'price_output'    => max(0.0, (float) ($settings['price_output'] ?? 0)),
            'proxy'           => isset($settings['proxy']) && is_string($settings['proxy']) ? $settings['proxy'] : null,
            'url_guard'       => isset($settings['url_guard']) && is_callable($settings['url_guard']) ? $settings['url_guard'] : null,
        ];

        if ($resolved['base_url'] === '') {
            $resolved['base_url'] = (string) $preset['base_url'];
        }
        if ($resolved['api_version'] === '' && isset($preset['api_version'])) {
            $resolved['api_version'] = (string) $preset['api_version'];
        }
        if (!in_array($resolved['token_param'], ['max_completion_tokens', 'max_tokens'], true)) {
            $resolved['token_param'] = (string) $preset['token_param'];
        }
        if (!in_array($resolved['json_mode'], ['json_schema', 'json_object', 'prompt'], true)) {
            $resolved['json_mode'] = (string) $preset['json_mode'];
        }

        return $resolved;
    }

    /**
     * Missing pieces that prevent any call (empty = usable).
     *
     * @return string[]
     */
    public static function missingSettings(array $settings): array
    {
        $resolved = self::resolve($settings);
        $preset   = self::get($resolved['provider']);
        $missing  = [];
        if ($resolved['base_url'] === '') {
            $missing[] = 'base_url';
        }
        if ($resolved['model'] === '') {
            $missing[] = 'model';
        }
        if (!empty($preset['key_required']) && $resolved['api_key'] === '') {
            $missing[] = 'api_key';
        }
        return $missing;
    }
}
