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
 * Provider-neutral, single-turn chat request: one system prompt and one
 * user message made of text and image parts. Drivers translate it to the
 * wire format of each provider.
 */
final class ChatRequest
{
    /** @var string */
    public $system = '';

    /**
     * Ordered user message parts:
     *  ['type' => 'text',  'text' => string]
     *  ['type' => 'image', 'mime' => string, 'data' => base64 string, 'name' => string]
     *  ['type' => 'file',  'mime' => 'application/pdf', 'data' => base64 string, 'name' => string]
     *
     * @var array<int, array<string, string>>
     */
    public $parts = [];

    /** @var int output budget (includes reasoning tokens on reasoning models) */
    public $maxTokens = 2048;

    /** @var string|null '', 'none', 'minimal', 'low', 'medium', 'high' */
    public $reasoningEffort = null;

    /** @var float|null used only by non-reasoning models */
    public $temperature = null;

    /** @var bool the answer must be a JSON object */
    public $json = false;

    /**
     * JSON schema for providers supporting structured outputs.
     *
     * @var array{name: string, schema: array}|null
     */
    public $jsonSchema = null;

    /** @var int|null seconds; null = connection default */
    public $timeout = null;

    public function __construct(string $system = '')
    {
        $this->system = $system;
    }

    public function addText(string $text): self
    {
        if ($text !== '') {
            $this->parts[] = ['type' => 'text', 'text' => $text];
        }
        return $this;
    }

    public function addImage(string $mime, string $base64, string $name = ''): self
    {
        $this->parts[] = ['type' => 'image', 'mime' => $mime, 'data' => $base64, 'name' => $name];
        return $this;
    }

    /**
     * Whole document (PDF) for providers able to read it natively.
     */
    public function addFile(string $mime, string $base64, string $name = ''): self
    {
        $this->parts[] = ['type' => 'file', 'mime' => $mime, 'data' => $base64, 'name' => $name];
        return $this;
    }

    public function countImages(): int
    {
        return $this->countParts('image');
    }

    public function countParts(string $type): int
    {
        $count = 0;
        foreach ($this->parts as $part) {
            if ($part['type'] === $type) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Concatenated text parts (used by drivers that do not support images
     * and by the mock/test tooling).
     */
    public function getText(): string
    {
        $text = [];
        foreach ($this->parts as $part) {
            if ($part['type'] === 'text') {
                $text[] = $part['text'];
            }
        }
        return implode("\n\n", $text);
    }
}
