<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Llm\Driver;

/**
 * Azure OpenAI.
 *
 * Two URL styles are supported:
 *  - classic deployments API (default):
 *    {endpoint}/openai/deployments/{deployment}/chat/completions?api-version={version}
 *  - v1 API (api_version = "v1"):
 *    {endpoint}/openai/v1/chat/completions, deployment sent as "model"
 *
 * Authentication uses the "api-key" header.
 */
class AzureDriver extends OpenAiDriver
{
    protected function endpoint(): string
    {
        $url = rtrim(trim((string) $this->setting('base_url')), '/');
        // Tolerate endpoints pasted with the API path
        $url = preg_replace('#/openai(/.*)?$#i', '', $url) ?? $url;
        return $url;
    }

    protected function isV1(): bool
    {
        return strtolower(trim((string) $this->setting('api_version'))) === 'v1';
    }

    protected function baseUrl(): string
    {
        return $this->endpoint() . '/openai/v1';
    }

    protected function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'api-key'      => (string) $this->setting('api_key'),
        ];
    }

    protected function modelInBody(): bool
    {
        return $this->isV1();
    }

    protected function deploymentUrl(string $deployment, string $operation): string
    {
        $version = trim((string) $this->setting('api_version'));
        if ($version === '') {
            $version = '2024-10-21';
        }
        return $this->endpoint() . '/openai/deployments/' . rawurlencode($deployment)
            . '/' . $operation . '?api-version=' . rawurlencode($version);
    }

    protected function chatUrl(): string
    {
        if ($this->isV1()) {
            return $this->baseUrl() . '/chat/completions';
        }
        return $this->deploymentUrl((string) $this->setting('model'), 'chat/completions');
    }

    protected function embeddingsUrl(): string
    {
        if ($this->isV1()) {
            return $this->baseUrl() . '/embeddings';
        }
        return $this->deploymentUrl((string) $this->setting('embedding_model'), 'embeddings');
    }

    public function buildListModels(): ?array
    {
        // Deployments can only be listed through the management API
        return null;
    }
}
