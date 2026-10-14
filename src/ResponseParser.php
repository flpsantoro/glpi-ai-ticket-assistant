<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use GlpiPlugin\Ticketassistant\Llm\LlmException;
use League\CommonMark\CommonMarkConverter;
use Michelf\Markdown;

/**
 * Treats everything the model returns as untrusted input: JSON is decoded
 * defensively, HTML goes through an allowlist with no attributes at all,
 * Markdown (frequent with local models) is converted first.
 */
final class ResponseParser
{
    /** Tags kept in generated HTML; every attribute is removed */
    public const ALLOWED_TAGS = ['p', 'br', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'u', 'code', 'pre', 'blockquote', 'h4', 'h5'];

    /**
     * Decodes the JSON object of an answer.
     *
     * @param string               $content model output
     * @param array<string,string> $schema  key => 'html' | 'text' | 'int' | 'float' | 'bool' | 'array' | '?int' (nullable)
     *                                      keys prefixed with '?' are optional
     *
     * @return array<string, mixed>
     *
     * @throws LlmException KIND_BAD_RESPONSE
     */
    public static function parse(string $content, array $schema): array
    {
        $data = self::decodeObject($content);
        if ($data === null) {
            throw new LlmException(LlmException::KIND_BAD_RESPONSE, 'Invalid JSON in the AI answer');
        }

        $result = [];
        foreach ($schema as $key => $type) {
            $optional = false;
            if ($key[0] === '?') {
                $optional = true;
                $key      = substr($key, 1);
            }
            $nullable = false;
            if ($type[0] === '?') {
                $nullable = true;
                $type     = substr($type, 1);
            }

            if (!array_key_exists($key, $data) || ($data[$key] === null && !$nullable)) {
                if ($optional) {
                    $result[$key] = self::emptyValue($type);
                    continue;
                }
                throw new LlmException(LlmException::KIND_BAD_RESPONSE, "Key '{$key}' missing in the AI answer");
            }
            $value = $data[$key];
            if ($value === null) {
                $result[$key] = null;
                continue;
            }

            switch ($type) {
                case 'html':
                    if (!is_string($value) || trim($value) === '') {
                        if ($optional) {
                            $result[$key] = '';
                            break;
                        }
                        throw new LlmException(LlmException::KIND_BAD_RESPONSE, "Key '{$key}' empty in the AI answer");
                    }
                    $result[$key] = self::toSafeHtml($value);
                    break;
                case 'text':
                    $result[$key] = is_scalar($value) ? trim(strip_tags((string) $value)) : '';
                    break;
                case 'int':
                    $result[$key] = is_numeric($value) ? (int) $value : null;
                    break;
                case 'float':
                    $result[$key] = is_numeric($value) ? (float) $value : null;
                    break;
                case 'bool':
                    $result[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
                    break;
                case 'array':
                    $result[$key] = is_array($value) ? array_values(array_filter(array_map(
                        static function ($item) {
                            return is_scalar($item) ? trim(strip_tags((string) $item)) : '';
                        },
                        $value,
                    ), 'strlen')) : [];
                    break;
                default:
                    $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return mixed
     */
    private static function emptyValue(string $type)
    {
        switch ($type) {
            case 'array':
                return [];
            case 'int':
            case 'float':
                return null;
            case 'bool':
                return false;
            default:
                return '';
        }
    }

    /**
     * JSON object found in a model output: raw, wrapped in a Markdown fence
     * or surrounded by prose.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeObject(string $content): ?array
    {
        $raw = trim($content);
        if (preg_match('/^```[a-z0-9]*\s*(.*?)\s*```$/is', $raw, $m)) {
            $raw = trim($m[1]);
        }

        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }

        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }

    /**
     * Untrusted HTML or Markdown -> HTML restricted to ALLOWED_TAGS without
     * any attribute (no links, images, styles or event handlers survive).
     */
    public static function toSafeHtml(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('#<(p|br|ul|ol|li|strong|em|b|i|div)\b#i', $value)) {
            $value = self::markdownToHtml($value);
        }
        return self::sanitizeHtml($value);
    }

    public static function sanitizeHtml(string $html): string
    {
        // Drop dangerous elements together with their content
        $html = preg_replace('#<(script|style|iframe|object|embed|svg|math|template|noscript)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#<(script|style|iframe|object|embed|svg|math|template|noscript)\b[^>]*/?>#is', '', $html) ?? '';
        // Headings become h4 (the modal has its own titles)
        $html = preg_replace('#<(/?)h[1-3]\b[^>]*>#i', '<$1h4>', $html) ?? '';
        $html = preg_replace('#<(/?)h6\b[^>]*>#i', '<$1h5>', $html) ?? '';
        // Divs become paragraphs, the rest of the markup is stripped
        $html = preg_replace('#<(/?)div\b[^>]*>#i', '<$1p>', $html) ?? '';
        $html = strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');
        // Remove every attribute of the remaining tags
        $html = preg_replace('#<([a-z0-9]+)(?:\s[^>]*)?(/?)>#i', '<$1$2>', $html) ?? '';
        $html = preg_replace('#<p>\s*</p>#i', '', $html) ?? '';
        return trim($html);
    }

    /**
     * Markdown -> HTML with the library bundled with GLPI (league/commonmark
     * on GLPI 11, michelf/php-markdown on GLPI 10), or a minimal fallback.
     */
    public static function markdownToHtml(string $markdown): string
    {
        try {
            if (class_exists('\\League\\CommonMark\\CommonMarkConverter')) {
                $converter = new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
                return (string) $converter->convert($markdown);
            }
            if (class_exists('\\Michelf\\Markdown')) {
                $parser = new Markdown();
                $parser->no_markup = true;
                $parser->no_entities = true;
                return (string) $parser->transform($markdown);
            }
        } catch (\Throwable $e) {
            // fall through to the minimal converter
        }

        $html   = '';
        $list   = null;
        $escape = static function (string $text): string {
            $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
            $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
            $text = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/s', '<em>$1</em>', $text) ?? $text;
            return preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        };
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(?:[-*+]|\d+[.)])\s+(.*)$/', $trimmed, $m)) {
                $type = ctype_digit($trimmed[0]) ? 'ol' : 'ul';
                if ($list !== $type) {
                    $html .= $list !== null ? "</{$list}>" : '';
                    $html .= "<{$type}>";
                    $list  = $type;
                }
                $html .= '<li>' . $escape($m[1]) . '</li>';
                continue;
            }
            if ($list !== null) {
                $html .= "</{$list}>";
                $list  = null;
            }
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^#{1,6}\s+(.*)$/', $trimmed, $m)) {
                $html .= '<h4>' . $escape($m[1]) . '</h4>';
                continue;
            }
            $html .= '<p>' . $escape($trimmed) . '</p>';
        }
        if ($list !== null) {
            $html .= "</{$list}>";
        }
        return $html;
    }

    /**
     * Removes the closing formula the model may add despite the instructions
     * (the server appends the configured signature).
     */
    public static function stripClosing(string $html): string
    {
        $closings = 'atenciosamente|att\.?|cordialmente|respeitosamente|abra(?:ç|c)os?|obrigad[oa]|grat[oa]|'
            . 'best regards|kind regards|regards|sincerely|thanks|thank you|cheers|'
            . 'saludos(?: cordiales)?|atentamente|un saludo|gracias|'
            . 'cordialement|bien (?:à|a) vous|salutations|merci';

        $patterns = [
            // <p>Regards,</p> or <p>Regards,<br>Name</p> (short) at the end
            '#<p>\s*(?:' . $closings . ')\s*[,.!]?\s*(?:<br\s*/?>[^<]{0,80})?\s*</p>\s*$#iu',
            // trailing empty paragraphs and line breaks
            '#(?:<p>\s*(?:&nbsp;|\s)*</p>\s*|<br\s*/?>\s*)+$#iu',
        ];
        $html = trim($html);
        for ($i = 0; $i < 3; $i++) {
            $before = $html;
            foreach ($patterns as $pattern) {
                $html = trim(preg_replace($pattern, '', $html) ?? $html);
            }
            if ($html === $before) {
                break;
            }
        }
        return $html;
    }
}
