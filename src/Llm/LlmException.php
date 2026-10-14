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
 * Error raised while talking to an AI provider, classified so that the
 * caller can decide on retries, the log status and the message shown to
 * the technician.
 */
class LlmException extends \RuntimeException
{
    public const KIND_CONTENT_FILTER = 'content_filter';
    public const KIND_TIMEOUT        = 'timeout';
    public const KIND_NETWORK        = 'network';
    public const KIND_AUTH           = 'auth';
    public const KIND_NOT_FOUND      = 'notfound';
    public const KIND_RATE_LIMIT     = 'rate_limit';
    public const KIND_QUOTA          = 'quota';
    public const KIND_HTTP           = 'http';
    public const KIND_BAD_RESPONSE   = 'badresponse';
    public const KIND_CONFIG         = 'config';
    public const KIND_UNSUPPORTED    = 'unsupported';

    /** @var string */
    private $kind;

    /** @var int|null */
    private $httpCode;

    /** @var int|null seconds suggested by the provider (Retry-After) */
    private $retryAfter;

    public function __construct(string $kind, string $message, ?int $httpCode = null, ?int $retryAfter = null)
    {
        parent::__construct($message);
        $this->kind       = $kind;
        $this->httpCode   = $httpCode;
        $this->retryAfter = $retryAfter;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getHttpCode(): ?int
    {
        return $this->httpCode;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Transient failures worth an automatic new attempt.
     */
    public function isRetryable(): bool
    {
        if (in_array($this->kind, [self::KIND_NETWORK, self::KIND_TIMEOUT, self::KIND_RATE_LIMIT], true)) {
            return true;
        }
        return $this->kind === self::KIND_HTTP && $this->httpCode !== null && $this->httpCode >= 500;
    }

    /**
     * Status stored in the audit log (e.g. "error_content_filter").
     */
    public function getLogStatus(): string
    {
        return 'error_' . $this->kind;
    }

    /**
     * Message that can be displayed to the technician (no technical detail).
     */
    public function getFriendlyMessage(): string
    {
        switch ($this->kind) {
            case self::KIND_CONTENT_FILTER:
                return __('The content filter of the AI service blocked this request. Rephrase the text or proceed manually.', 'ticketassistant');
            case self::KIND_TIMEOUT:
                return __('The AI service took too long to answer. Try again in a moment.', 'ticketassistant');
            case self::KIND_NETWORK:
                return __('Could not connect to the AI service. Try again in a moment.', 'ticketassistant');
            case self::KIND_AUTH:
                return __('The AI service refused the credentials or the network access. Contact the administrator.', 'ticketassistant');
            case self::KIND_NOT_FOUND:
                return __('The configured AI model or endpoint was not found. Contact the administrator.', 'ticketassistant');
            case self::KIND_RATE_LIMIT:
                return __('The AI service is receiving too many requests right now. Try again in a moment.', 'ticketassistant');
            case self::KIND_QUOTA:
                return __('The AI service quota or credit is exhausted. Contact the administrator.', 'ticketassistant');
            case self::KIND_BAD_RESPONSE:
                return __('The AI returned an invalid answer. Try generating it again.', 'ticketassistant');
            case self::KIND_CONFIG:
            case self::KIND_UNSUPPORTED:
                return __('The AI assistant is not correctly configured. Contact the administrator.', 'ticketassistant');
            default:
                return __('The AI service returned an error. If it persists, contact the administrator.', 'ticketassistant');
        }
    }
}
