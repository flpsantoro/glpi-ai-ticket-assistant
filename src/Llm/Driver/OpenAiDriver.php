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
 * OpenAI Chat Completions API and every server that implements it:
 * OpenAI, Google Gemini (OpenAI compatibility endpoint), Ollama, OpenRouter,
 * Mistral, LM Studio, vLLM, LiteLLM, Groq, DeepSeek...
 */
class OpenAiDriver extends AbstractDriver
{
    protected function baseUrl(): string
    {
        $url = rtrim(trim((string) $this->setting('base_url')), '/');
        return $url !== '' ? $url : 'https://api.openai.com/v1';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
        $key = (string) $this->setting('api_key');
        if ($key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        return $headers;
    }

    protected function chatUrl(): string
    {
        return $this->baseUrl() . '/chat/completions';
    }

    protected function embeddingsUrl(): string
    {
        return $this->baseUrl() . '/embeddings';
    }

    /**
     * The model name travels in the body (false for Azure deployments,
     * where it is part of the URL).
     */
    protected function modelInBody(): bool
    {
        return true;
    }

    protected function tokenParam(): string
    {
        $param = (string) $this->setting('token_param', 'max_tokens');
        return in_array($param, ['max_completion_tokens', 'max_tokens'], true) ? $param : 'max_tokens';
    }

    protected function jsonMode(): string
    {
        $mode = (string) $this->setting('json_mode', 'json_object');
        return in_array($mode, ['json_schema', 'json_object', 'prompt'], true) ? $mode : 'json_object';
    }

    /**
     * User message content: plain string when there is only text (widest
     * compatibility), array of parts when images are present.
     *
     * @return string|array
     */
    protected function userContent(ChatRequest $request)
    {
        $images = !empty($this->settings['vision']) && $request->countImages() > 0;
        $files  = !empty($this->settings['native_pdf']) && $request->countParts('file') > 0;
        if (!$images && !$files) {
            return $request->getText();
        }

        $detail = (string) $this->setting('image_detail', 'auto');
        $parts  = [];
        foreach ($request->parts as $part) {
            if ($part['type'] === 'text') {
                $parts[] = ['type' => 'text', 'text' => $part['text']];
            } elseif ($part['type'] === 'image' && $images) {
                $image = ['url' => 'data:' . $part['mime'] . ';base64,' . $part['data']];
                if (in_array($detail, ['low', 'high'], true)) {
                    $image['detail'] = $detail;
                }
                $parts[] = ['type' => 'image_url', 'image_url' => $image];
            } elseif ($part['type'] === 'file' && $files) {
                $parts[] = [
                    'type' => 'file',
                    'file' => [
                        'filename'  => $part['name'] !== '' ? $part['name'] : 'document.pdf',
                        'file_data' => 'data:' . $part['mime'] . ';base64,' . $part['data'],
                    ],
                ];
            }
        }
        return $parts;
    }

    public function buildChat(ChatRequest $request): array
    {
        $messages = [];
        if (trim($request->system) !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }
        $messages[] = ['role' => 'user', 'content' => $this->userContent($request)];

        $body = [];
        if ($this->modelInBody()) {
            $body['model'] = (string) $this->setting('model');
        }
        $body['messages']          = $messages;
        $body[$this->tokenParam()] = max(16, (int) $request->maxTokens);

        if ($this->isReasoningModel()) {
            // Reasoning models reject "temperature"; the effort drives cost and latency
            $effort = trim((string) $request->reasoningEffort);
            if ($effort !== '') {
                $body['reasoning_effort'] = $effort;
            }
        } elseif ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        if ($request->json) {
            $mode = $this->jsonMode();
            if ($mode === 'json_schema' && $request->jsonSchema !== null) {
                $body['response_format'] = [
                    'type'        => 'json_schema',
                    'json_schema' => [
                        'name'   => $request->jsonSchema['name'],
                        'strict' => true,
                        'schema' => $request->jsonSchema['schema'],
                    ],
                ];
            } elseif ($mode !== 'prompt') {
                $body['response_format'] = ['type' => 'json_object'];
            }
        }

        return [
            'method'  => 'POST',
            'url'     => $this->chatUrl(),
            'headers' => $this->headers(),
            'body'    => $body,
        ];
    }

    public function parseChat(array $data, int $http_code): ChatResponse
    {
        if (!isset($data['choices'][0]) || !is_array($data['choices'][0])) {
            throw new LlmException(LlmException::KIND_BAD_RESPONSE, 'Answer without choices', $http_code);
        }
        $choice  = $data['choices'][0];
        $content = $choice['message']['content'] ?? '';
        if (is_array($content)) {
            // Some servers return the content as a list of parts
            $buffer = '';
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $buffer .= $part['text'];
                } elseif (is_string($part)) {
                    $buffer .= $part;
                }
            }
            $content = $buffer;
        }
        $content = self::stripReasoning((string) $content);

        $finish = (string) ($choice['finish_reason'] ?? '');
        $usage  = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        $response                   = new ChatResponse();
        $response->content          = $content;
        $response->finishReason     = in_array($finish, ['stop', 'length', 'content_filter'], true) ? $finish : ($finish === '' ? 'stop' : 'other');
        $response->promptTokens     = (int) ($usage['prompt_tokens'] ?? 0);
        $response->completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $response->reasoningTokens  = (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0);
        $response->model            = (string) ($data['model'] ?? $this->setting('model'));
        $response->httpCode         = $http_code;

        if ($content === '') {
            if ($finish === 'content_filter') {
                throw new LlmException(LlmException::KIND_CONTENT_FILTER, 'Answer blocked by the content filter', $http_code);
            }
            if ($finish === 'length') {
                throw new LlmException(
                    LlmException::KIND_BAD_RESPONSE,
                    'Truncated answer: raise the maximum tokens (on reasoning models the budget includes reasoning tokens)',
                    $http_code,
                );
            }
            throw new LlmException(LlmException::KIND_BAD_RESPONSE, 'Empty answer (finish_reason: ' . $finish . ')', $http_code);
        }

        return $response;
    }

    public function buildEmbeddings(array $texts, int $dimensions = 0): array
    {
        $body = ['input' => array_values($texts)];
        if ($this->modelInBody()) {
            $body['model'] = (string) $this->setting('embedding_model');
        }
        if ($dimensions > 0) {
            $body['dimensions'] = $dimensions;
        }
        return [
            'method'  => 'POST',
            'url'     => $this->embeddingsUrl(),
            'headers' => $this->headers(),
            'body'    => $body,
        ];
    }

    public function parseEmbeddings(array $data): array
    {
        $vectors = [];
        foreach (($data['data'] ?? []) as $i => $row) {
            if (!is_array($row) || !isset($row['embedding']) || !is_array($row['embedding'])) {
                continue;
            }
            $index           = isset($row['index']) ? (int) $row['index'] : (int) $i;
            $vectors[$index] = array_map('floatval', $row['embedding']);
        }
        if (!count($vectors)) {
            throw new LlmException(LlmException::KIND_BAD_RESPONSE, 'Embeddings answer without vectors');
        }
        ksort($vectors);
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        return [
            'vectors' => array_values($vectors),
            'tokens'  => (int) ($usage['prompt_tokens'] ?? ($usage['total_tokens'] ?? 0)),
        ];
    }

    public function buildListModels(): ?array
    {
        return [
            'method'  => 'GET',
            'url'     => $this->baseUrl() . '/models',
            'headers' => $this->headers(),
            'body'    => null,
        ];
    }

    public function parseListModels(array $data): array
    {
        $models = [];
        foreach (($data['data'] ?? $data['models'] ?? []) as $row) {
            if (is_array($row)) {
                $id = $row['id'] ?? ($row['name'] ?? null);
                if (is_string($id) && $id !== '') {
                    $models[] = $id;
                }
            } elseif (is_string($row)) {
                $models[] = $row;
            }
        }
        $models = array_values(array_unique($models));
        sort($models, SORT_NATURAL | SORT_FLAG_CASE);
        return $models;
    }

    public function classifyError(int $http_code, string $body, ?array $data, array $headers): LlmException
    {
        $error    = $data['error'] ?? null;
        $message  = '';
        $code     = '';
        if (is_array($error)) {
            $message = (string) ($error['message'] ?? '');
            $code    = (string) ($error['code'] ?? ($error['type'] ?? ''));
        } elseif (is_string($error)) {
            // Ollama: {"error": "model 'x' not found"}
            $message = $error;
        } elseif (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            $message = $data['message'];
        }
        if ($message === '') {
            $message = trim(strip_tags($body));
        }
        $detail = self::errorDetail($http_code, $message);

        if ($http_code === 400 && self::isContentFilter($body, $code)) {
            return new LlmException(LlmException::KIND_CONTENT_FILTER, $detail, $http_code);
        }
        if ($http_code === 429 && ($code === 'insufficient_quota' || stripos($message, 'exceeded your current quota') !== false)) {
            // Billing problem, not a transient rate limit: do not retry
            return new LlmException(LlmException::KIND_QUOTA, $detail, $http_code);
        }
        // Ollama answers 404 for unknown models, OpenAI 404 for unknown models too
        $kind = self::kindForStatus($http_code);
        return new LlmException(
            $kind,
            $detail,
            $http_code,
            in_array($kind, [LlmException::KIND_RATE_LIMIT, LlmException::KIND_HTTP], true) ? self::retryAfter($headers) : null,
        );
    }

    /**
     * Azure OpenAI / OpenAI moderation blocks.
     */
    protected static function isContentFilter(string $body, string $code): bool
    {
        if (in_array($code, ['content_filter', 'content_policy_violation', 'ResponsibleAIPolicyViolation'], true)) {
            return true;
        }
        foreach (['content_filter', 'ResponsibleAIPolicy', 'content management policy', 'jailbreak'] as $marker) {
            if (stripos($body, $marker) !== false) {
                return true;
            }
        }
        return false;
    }
}
