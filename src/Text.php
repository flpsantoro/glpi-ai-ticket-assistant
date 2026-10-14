<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

/**
 * Text helpers: GLPI rich text -> compact plain text, UTF-8 repair and
 * FULLTEXT query cleaning.
 *
 * The HTML -> text conversion is implemented here instead of relying on
 * Glpi\RichText\RichText, whose signature and behaviour differ between
 * GLPI 10 and 11.
 */
final class Text
{
    /**
     * Removes invalid UTF-8 byte sequences (legacy content pasted from word
     * processors). Without it json_encode() of the provider payload fails.
     */
    public static function scrubUtf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $previous = mb_substitute_character();
        mb_substitute_character('none');
        $clean = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        mb_substitute_character($previous);
        return $clean;
    }

    /**
     * Value of a GLPI rich text or text field -> compact plain text.
     *
     * @param string|null $content   value as read from the database
     * @param int         $max_chars 0 = no truncation
     */
    public static function toPlainText(?string $content, int $max_chars = 0): string
    {
        if ($content === null || $content === '') {
            return '';
        }
        $text = self::htmlToText(Compat::decodeStoredValue(self::scrubUtf8($content)));
        // Entity decoding can PRODUCE invalid bytes from legacy content
        $text = self::scrubUtf8($text);
        if ($max_chars > 0 && mb_strlen($text) > $max_chars) {
            $text = rtrim(mb_substr($text, 0, $max_chars)) . '…';
        }
        return $text;
    }

    /**
     * HTML -> text preserving paragraphs, line breaks and lists.
     */
    public static function htmlToText(string $html): string
    {
        // Content migrated from very old GLPI versions can be encoded twice
        if (preg_match('/&lt;\/?(?:p|br|div|span|ul|ol|li|table|img|strong|b|i|a)\b/i', $html) && strpos($html, '<') === false) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $html = preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<img\b[^>]*>#i', ' [image] ', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|h[1-6]|blockquote|pre|table|ul|ol)\s*>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<(p|div|tr|h[1-6]|blockquote|pre|table)\b[^>]*>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</t[dh]\s*>#i', "\t", $html) ?? $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\u{00A0}", "\r\n", "\r"], [' ', "\n", "\n"], $text);

        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n[ \t]+/", "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Free text -> safe query for MATCH ... AGAINST in natural language mode.
     */
    public static function cleanFulltextQuery(string $text, int $max_chars = 600): string
    {
        $text = str_replace(['+', '-', '<', '>', '(', ')', '~', '*', '"', '@', "'", '\\'], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        if ($max_chars > 0 && mb_strlen($text) > $max_chars) {
            $text = mb_substr($text, 0, $max_chars);
        }
        return $text;
    }

    /**
     * First word of a first name ("Maria Clara" -> "Maria").
     */
    public static function firstWord(string $text): string
    {
        $text  = trim($text);
        $parts = preg_split('/\s+/u', $text);
        return $parts !== false && isset($parts[0]) ? $parts[0] : $text;
    }

    public static function truncate(string $text, int $max_chars): string
    {
        if ($max_chars > 0 && mb_strlen($text) > $max_chars) {
            return rtrim(mb_substr($text, 0, $max_chars)) . '…';
        }
        return $text;
    }

    /**
     * Escapes a value for HTML output.
     */
    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
