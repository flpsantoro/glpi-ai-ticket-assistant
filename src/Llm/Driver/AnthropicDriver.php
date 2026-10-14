<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Llm\Driver;

use GlpiPlugin\Ticketassistant\Llm\ChatRequest;
use GlpiPlugin\Ticketassistant\Llm\ChatResponse;
use GlpiPlugin\Ticketassistant\Llm\LlmException;

/**
 * Anthropic Messages API (Claude).
 *
 * - JSON answers are obtained through the prompt (the API has no JSON mode);
 *   the caller parses them defensively.
 * - On "reasoning" connections the effort is mapped to an extended thinking
 *   budget, added on top of the visible output budget.
 * - No embeddings endpoint.
 */
class AnthropicDriver extends AbstractDriver
{
    private const API_VERSION = '2023-06-01';

    /** reasoning effort => thinking budget (tokens) */
    private const THINKING_BUDGET = [
        'minimal' => 1024,
        'low'     => 2048,
        'medium'  => 6000,
        'high'    => 16000,
    ];

    protected function baseUrl(): string
    {
        $url = rtrim(trim((string) $this->setting('base_url')), '/');
        $url = $url !== '' ? $url : 'https://api.anthropic.com';
        // Accept both "https://api.anthropic.com" and ".../v1"
        return preg_replace('#/v1$#', '', $url) ?? $url;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json',
            'x-api-key'         => (string) $this->setting('api_key'),
            'anthropic-version' => self::API_VERSION,
        ];
    }

    public function buildChat(ChatRequest $request): array
    {
        $blocks = [];
        foreach ($request->parts as $part) {
            if ($part['type'] === 'text') {
                $blocks[] = ['type' => 'text', 'text' => $part['text']];
            } elseif ($part['type'] === 'image' && !empty($this->settings['vision'])) {
                $blocks[] = [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $part['mime'],
                        'data'       => $part['data'],
                    ],
                ];
            } elseif ($part['type'] === 'file' && !empty($this->settings['native_pdf'])) {
                $blocks[] = [
                    'type'   => 'document',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $part['mime'],
                        'data'       => $part['data'],
                    ],
                ];
            }
        }
        if (!count($blocks)) {
            $blocks[] = ['type' => 'text', 'text' => '.'];
        }

        $max_tokens = max(16, (int) $request->maxTokens);
        $body       = [
            'model'      => (string) $this->setting('model'),
            'max_tokens' => $max_tokens,
            'messages'   => [['role' => 'user', 'content' => $blocks]],
        ];

        $system = trim($request->system);
        if ($request->json) {
            $system .= "\n\nAnswer with one JSON object only: no markdown fence, no text before or after it.";
        }
        if ($system !== '') {
            $body['system'] = $system;
        }

        $effort = strtolower(trim((string) $request->reasoningEffort));
        if ($this->isReasoningModel() && isset(self::THINKING_BUDGET[$effort])) {
            $budget             = self::THINKING_BUDGET[$effort];
            $body['thinking']   = ['type' => 'enabled', 'budget_tokens' => $budget];
            $body['max_tokens'] = $max_tokens + $budget;
        } elseif (!$this->isReasoningModel() && $request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        return [
            'method'  => 'POST',
            'url'     => $this->baseUrl() . '/v1/messages',
            'headers' => $this->headers(),
            'body'    => $body,
        ];
    }

    public function parseChat(array $data, int $http_code): ChatResponse
    {
        if (!isset($data['content']) || !is_array($data['content'])) {
            throw new LlmException(LlmException::KIND_BAD_RESPONSE, 'Answer without content', $http_code);
        }

        $text = '';
        foreach ($data['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $text .= (string) $block['text'];
            }
        }
        $text = self::stripReasoning($text);

        $stop  = (string) ($data['stop_reason'] ?? '');
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        $response                   = new ChatResponse();
        $response->content          = $text;
        $response->finishReason     = $stop === 'max_tokens' ? 'length' : ($stop === 'refusal' ? 'content_filter' : 'stop');
        $response->promptTokens     = (int) ($usage['input_tokens'] ?? 0)
            + (int) ($usage['cache_creation_input_tokens'] ?? 0)
            + (int) ($usage['cache_read_input_tokens'] ?? 0);
        $response->completionTokens = (int) ($usage['output_tokens'] ?? 0);
        $response->model            = (string) ($data['model'] ?? $this->setting('model'));
        $response->httpCode         = $http_code;

        if ($text === '') {
            if ($stop === 'refusal') {
                throw new LlmException(LlmException::KIND_CONTENT_FILTER, 'The model refused to answer', $http_code);
            }
            if ($stop === 'max_tokens') {
                throw new LlmException(
                    LlmException::KIND_BAD_RESPONSE,
                    'Truncated answer: raise the maximum tokens',
                    $http_code
                );
            }
            throw new LlmException(LlmException::KIND_BAD_RESPONSE, 'Empty answer (stop_reason: ' . $stop . ')', $http_code);
        }

        return $response;
    }

    public function supportsEmbeddings(): bool
    {
        return false;
    }

    public function buildEmbeddings(array $texts, int $dimensions = 0): array
    {
        throw new LlmException(LlmException::KIND_UNSUPPORTED, 'Anthropic has no embeddings API: configure a separate embeddings connection');
    }

    public function parseEmbeddings(array $data): array
    {
        throw new LlmException(LlmException::KIND_UNSUPPORTED, 'Anthropic has no embeddings API');
    }

    public function buildListModels(): ?array
    {
        return [
            'method'  => 'GET',
            'url'     => $this->baseUrl() . '/v1/models?limit=100',
            'headers' => $this->headers(),
            'body'    => null,
        ];
    }

    public function parseListModels(array $data): array
    {
        $models = [];
        foreach (($data['data'] ?? []) as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
                $models[] = $row['id'];
            }
        }
        sort($models, SORT_NATURAL | SORT_FLAG_CASE);
        return $models;
    }

    public function classifyError(int $http_code, string $body, ?array $data, array $headers): LlmException
    {
        $type    = (string) ($data['error']['type'] ?? '');
        $message = (string) ($data['error']['message'] ?? trim(strip_tags($body)));
        $detail  = self::errorDetail($http_code, $message);

        if ($http_code === 400 && stripos($message, 'credit balance') !== false) {
            return new LlmException(LlmException::KIND_QUOTA, $detail, $http_code);
        }

        switch ($type) {
            case 'authentication_error':
            case 'permission_error':
                return new LlmException(LlmException::KIND_AUTH, $detail, $http_code);
            case 'not_found_error':
                return new LlmException(LlmException::KIND_NOT_FOUND, $detail, $http_code);
            case 'rate_limit_error':
                return new LlmException(LlmException::KIND_RATE_LIMIT, $detail, $http_code, self::retryAfter($headers));
            case 'overloaded_error':
            case 'api_error':
                // 529/500: transient, retried by the client
                return new LlmException(LlmException::KIND_HTTP, $detail, max(500, $http_code), self::retryAfter($headers));
        }

        $kind = self::kindForStatus($http_code);
        return new LlmException($kind, $detail, $http_code, $kind === LlmException::KIND_RATE_LIMIT ? self::retryAfter($headers) : null);
    }
}
