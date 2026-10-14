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
 * Provider-neutral chat answer.
 */
final class ChatResponse
{
    /** @var string text produced by the model (reasoning traces removed) */
    public $content = '';

    /** @var string normalized: stop | length | content_filter | other */
    public $finishReason = '';

    /** @var int */
    public $promptTokens = 0;

    /** @var int includes reasoning tokens when the provider reports them */
    public $completionTokens = 0;

    /** @var int */
    public $reasoningTokens = 0;

    /** @var int */
    public $httpCode = 0;

    /** @var int total duration, retries included */
    public $durationMs = 0;

    /** @var string model reported by the provider */
    public $model = '';

    /** @var float estimated cost in USD */
    public $costUsd = 0.0;

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
