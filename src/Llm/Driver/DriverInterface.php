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
 * Translates provider-neutral requests to one provider wire format and back.
 *
 * Drivers never perform I/O: they describe the HTTP request as
 * ['method' => ..., 'url' => ..., 'headers' => [...], 'body' => array|null]
 * and parse decoded JSON bodies. The Client does the transport, retries,
 * timing and cost estimation. This keeps drivers unit-testable.
 */
interface DriverInterface
{
    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: array|null}
     */
    public function buildChat(ChatRequest $request): array;

    /**
     * @throws LlmException when the body is not a usable answer
     */
    public function parseChat(array $data, int $http_code): ChatResponse;

    public function supportsEmbeddings(): bool;

    /**
     * @param string[] $texts
     *
     * @return array{method: string, url: string, headers: array<string, string>, body: array|null}
     */
    public function buildEmbeddings(array $texts, int $dimensions = 0): array;

    /**
     * @return array{vectors: array<int, float[]>, tokens: int}
     */
    public function parseEmbeddings(array $data): array;

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: array|null}|null
     *         null when the provider has no model listing endpoint
     */
    public function buildListModels(): ?array;

    /**
     * @return string[]
     */
    public function parseListModels(array $data): array;

    /**
     * Classifies an HTTP error answer.
     *
     * @param array<string, string> $headers lower-cased response headers
     */
    public function classifyError(int $http_code, string $body, ?array $data, array $headers): LlmException;
}
