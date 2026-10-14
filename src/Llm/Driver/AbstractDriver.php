<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Llm\Driver;

use GlpiPlugin\Ticketassistant\Llm\LlmException;

/**
 * Helpers shared by the drivers.
 *
 * Expected settings (already resolved against the provider preset, see
 * Providers::resolve()): driver, base_url, api_key, model, api_version,
 * model_type (reasoning|standard), token_param, json_mode, vision,
 * image_detail, embedding_model.
 */
abstract class AbstractDriver implements DriverInterface
{
    /** @var array<string, mixed> */
    protected $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    protected function setting(string $key, $default = '')
    {
        return $this->settings[$key] ?? $default;
    }

    protected function isReasoningModel(): bool
    {
        return ($this->settings['model_type'] ?? 'reasoning') === 'reasoning';
    }

    /**
     * Reasoning models (OpenAI o-series, GPT-5, DeepSeek R1, Qwen3...) may
     * leak their chain of thought inside <think> tags on OpenAI-compatible
     * servers such as Ollama or vLLM. Remove it before parsing.
     */
    protected static function stripReasoning(string $content): string
    {
        $content = preg_replace('#<think\b[^>]*>.*?</think>#is', '', $content) ?? $content;
        // Unclosed block: everything after it is reasoning (truncated answer)
        $content = preg_replace('#<think\b[^>]*>.*$#is', '', $content) ?? $content;
        return trim($content);
    }

    /**
     * Seconds to wait suggested by a 429/503 answer, if any.
     *
     * @param array<string, string> $headers lower-cased
     */
    protected static function retryAfter(array $headers): ?int
    {
        if (isset($headers['retry-after-ms']) && is_numeric($headers['retry-after-ms'])) {
            return max(1, (int) ceil(((float) $headers['retry-after-ms']) / 1000));
        }
        if (isset($headers['retry-after'])) {
            $value = trim($headers['retry-after']);
            if (is_numeric($value)) {
                return max(1, (int) ceil((float) $value));
            }
            $time = strtotime($value);
            if ($time !== false) {
                return max(1, $time - time());
            }
        }
        return null;
    }

    /**
     * Short, single-line technical detail for logs.
     */
    protected static function errorDetail(int $http_code, string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        if (mb_strlen($message) > 300) {
            $message = mb_substr($message, 0, 300) . '…';
        }
        return 'HTTP ' . $http_code . ($message !== '' ? ': ' . $message : '');
    }

    /**
     * Generic mapping of HTTP status codes to error kinds.
     */
    protected static function kindForStatus(int $http_code): string
    {
        if ($http_code === 401 || $http_code === 403) {
            return LlmException::KIND_AUTH;
        }
        if ($http_code === 404) {
            return LlmException::KIND_NOT_FOUND;
        }
        if ($http_code === 402) {
            return LlmException::KIND_QUOTA;
        }
        if ($http_code === 408) {
            return LlmException::KIND_TIMEOUT;
        }
        if ($http_code === 429) {
            return LlmException::KIND_RATE_LIMIT;
        }
        return LlmException::KIND_HTTP;
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }
}
