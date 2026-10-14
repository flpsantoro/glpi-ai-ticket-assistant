<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use Glpi\Toolbox\Sanitizer;

/**
 * Every difference between GLPI 10.0, 11 and 12 handled by the plugin is
 * isolated here, so that one code base runs on all of them.
 *
 * Verified against the GLPI 10.0.28, 11.0.11 and 12.0.0 sources:
 *  - GLPI 10 sanitizes $_POST/$_GET (raw copies in $_UPOST/$_UGET) and stores
 *    text HTML-encoded; GLPI 11+ handles raw values everywhere and decodes
 *    legacy rows when they are fetched. Glpi\Toolbox\Sanitizer is deprecated
 *    in 11 and removed in 12, so it is only called on GLPI 10.
 *  - $DB->insert()/update() do not escape on GLPI 10 and do escape on 11+.
 *  - \QueryExpression (10) became Glpi\DBAL\QueryExpression (11+).
 *  - Toolbox::logError() was removed in 11: logInFile() exists everywhere.
 *  - Plugin::getWebDir() is deprecated in 11 and removed in 12.
 *  - Static files are served from the plugin public/ directory on 11+.
 */
final class Compat
{
    public const PLUGIN_KEY = 'ticketassistant';

    /** @var bool|null */
    private static $modern = null;

    /**
     * GLPI 11 or later.
     */
    public static function isGlpi11(): bool
    {
        if (self::$modern === null) {
            self::$modern = defined('GLPI_VERSION') && version_compare(GLPI_VERSION, '11.0.0-dev', '>=');
        }
        return self::$modern;
    }

    // ------------------------------------------------------------------
    // Data encoding
    // ------------------------------------------------------------------

    /**
     * Value read from a GLPI core table -> raw value.
     * GLPI 10 stores "<p>" as "&#60;p&#62;"; GLPI 11+ already returns raw values.
     */
    public static function decodeStoredValue(string $value): string
    {
        if (self::isGlpi11() || strpos($value, '&#') === false) {
            return $value;
        }
        if (class_exists('\\Glpi\\Toolbox\\Sanitizer')) {
            return (string) Sanitizer::decodeHtmlSpecialChars($value);
        }
        return str_replace(['&#60;', '&#62;', '&#38;'], ['<', '>', '&'], $value);
    }

    /**
     * Raw request values: GLPI 10 sanitizes $_POST, keeping a raw copy in
     * $_UPOST; GLPI 11+ does not sanitize.
     *
     * @return array<string, mixed>
     */
    public static function rawPost(): array
    {
        if (!self::isGlpi11()) {
            if (isset($GLOBALS['_UPOST']) && is_array($GLOBALS['_UPOST'])) {
                return $GLOBALS['_UPOST'];
            }
            if (class_exists('\\Glpi\\Toolbox\\Sanitizer')) {
                return (array) Sanitizer::unsanitize($_POST);
            }
        }
        return $_POST;
    }

    /**
     * Value for $DB->insert() / $DB->update() / $DB->updateOrInsert():
     * GLPI 10 expects escaped strings, GLPI 11+ escapes by itself.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function dbValue($value)
    {
        if (!is_string($value) || self::isGlpi11()) {
            return $value;
        }
        /** @var \DBmysql $DB */
        global $DB;
        return $DB->escape($value);
    }

    /**
     * Input for CommonDBTM::add()/update() of GLPI items: GLPI 10 expects it
     * sanitized like a POSTed form, GLPI 11+ expects raw values.
     */
    public static function itemInput(array $input): array
    {
        if (!self::isGlpi11() && class_exists('\\Glpi\\Toolbox\\Sanitizer')) {
            return (array) Sanitizer::sanitize($input);
        }
        return $input;
    }

    /**
     * Values for Config::setConfigurationValues(). Encrypted values are kept
     * raw (GLPI encrypts them; the cipher text is SQL safe).
     *
     * @param string[] $secrets
     */
    public static function configInput(array $values, array $secrets = []): array
    {
        if (self::isGlpi11()) {
            return $values;
        }
        foreach ($values as $key => $value) {
            if (is_string($value) && !in_array($key, $secrets, true)) {
                $values[$key] = self::dbValue($value);
            }
        }
        return $values;
    }

    // ------------------------------------------------------------------
    // SQL
    // ------------------------------------------------------------------

    /**
     * Quoted string literal for hand-written SQL (every version).
     */
    public static function quote(string $value): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        return "'" . $DB->escape($value) . "'";
    }

    /**
     * Raw SQL expression for the query builder.
     *
     * @return object \QueryExpression on GLPI 10, \Glpi\DBAL\QueryExpression on 11+
     */
    public static function expr(string $sql)
    {
        if (class_exists('\\Glpi\\DBAL\\QueryExpression')) {
            return new \Glpi\DBAL\QueryExpression($sql);
        }
        return new \QueryExpression($sql);
    }

    /**
     * Runs a hand-written SELECT (FULLTEXT search, joins) and returns all rows.
     * doQuery() returns false on error on GLPI 10 and throws on 11+.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rawSelect(string $sql): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        try {
            $result = $DB->doQuery($sql);
        } catch (\Throwable $e) {
            self::log('SQL error: ' . $e->getMessage());
            return [];
        }
        if ($result === false || $result === true) {
            return [];
        }
        $rows = [];
        while ($row = $DB->fetchAssoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Runs a hand-written statement returning no rows (DDL during install).
     */
    public static function rawExecute(string $sql): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        try {
            return $DB->doQuery($sql) !== false;
        } catch (\Throwable $e) {
            self::log('SQL error: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Rights constants that moved between versions
    // ------------------------------------------------------------------

    /**
     * ITILFollowup "add followup to group/all tickets" rights
     * (ADDGROUPTICKET|ADDALLTICKET on 10, ADD_AS_GROUP|ADDALLITEM on 11+).
     */
    public static function followupAddOthersMask(): int
    {
        $group = defined('ITILFollowup::ADD_AS_GROUP') ? (int) constant('ITILFollowup::ADD_AS_GROUP') : 2048;
        $all   = defined('ITILFollowup::ADDALLITEM') ? (int) constant('ITILFollowup::ADDALLITEM') : 4096;
        return $group | $all;
    }

    // ------------------------------------------------------------------
    // Logging
    // ------------------------------------------------------------------

    /**
     * Writes to files/_log/ticketassistant.log (every version).
     */
    public static function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        if (class_exists('\\Toolbox') && method_exists('\\Toolbox', 'logInFile')) {
            \Toolbox::logInFile(self::PLUGIN_KEY, $line, true);
            return;
        }
        error_log($line);
    }

    // ------------------------------------------------------------------
    // URLs and assets
    // ------------------------------------------------------------------

    /**
     * Web path of the plugin root, e.g. "/glpi/plugins/ticketassistant".
     * On GLPI 10 a plugin installed in marketplace/ is served from there.
     */
    public static function pluginWebDir(): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!self::isGlpi11() && method_exists('\\Plugin', 'getWebDir')) {
            return rtrim((string) \Plugin::getWebDir(self::PLUGIN_KEY, true), '/');
        }
        return rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/plugins/' . self::PLUGIN_KEY;
    }

    /**
     * URL of a PHP script of the plugin (front/…, ajax/…).
     */
    public static function url(string $path): string
    {
        return self::pluginWebDir() . '/' . ltrim($path, '/');
    }

    /**
     * URL of a static file located in public/.
     */
    public static function assetUrl(string $path): string
    {
        $path = ltrim($path, '/');
        $url  = self::pluginWebDir() . '/' . (self::isGlpi11() ? '' : 'public/') . $path;
        return $url . '?v=' . rawurlencode(defined('PLUGIN_TICKETASSISTANT_VERSION') ? PLUGIN_TICKETASSISTANT_VERSION : '0');
    }

    /**
     * Path of a static file for the add_javascript / add_css hooks: relative
     * to the plugin root on GLPI 10, to the public/ directory on 11+.
     */
    public static function assetHookPath(string $path): string
    {
        return (self::isGlpi11() ? '' : 'public/') . ltrim($path, '/');
    }

    /**
     * Current request targets a GLPI front script, e.g. "front/ticket.form.php".
     */
    public static function isCurrentScript(string $script): bool
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $tail = '/' . ltrim($script, '/');
        return $path !== '' && substr($path, -strlen($tail)) === $tail;
    }

    // ------------------------------------------------------------------
    // Network
    // ------------------------------------------------------------------

    /**
     * Proxy configured in GLPI (Setup > General > Proxy), in the same format
     * GLPI itself uses (Toolbox::getGuzzleClient()), or null.
     */
    public static function glpiProxyUrl(): ?string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $host = trim((string) ($CFG_GLPI['proxy_name'] ?? ''));
        if ($host === '') {
            return null;
        }
        $credentials = '';
        if (!empty($CFG_GLPI['proxy_user'])) {
            $password = '';
            if (!empty($CFG_GLPI['proxy_passwd'])) {
                $decrypted = (new \GLPIKey())->decrypt((string) $CFG_GLPI['proxy_passwd']);
                $password  = is_string($decrypted) ? $decrypted : '';
            }
            $credentials = rawurlencode((string) $CFG_GLPI['proxy_user']) . ':' . rawurlencode($password) . '@';
        }
        $scheme = 'http://';
        if (preg_match('#^(https?://)(.*)$#i', $host, $m)) {
            $scheme = strtolower($m[1]);
            $host   = $m[2];
        }
        $port = (int) ($CFG_GLPI['proxy_port'] ?? 0);
        return $scheme . $credentials . rtrim($host, '/') . ($port > 0 ? ':' . $port : '');
    }

    /**
     * Server-side request guard for the AI provider URLs (set by a
     * super-administrator): http(s) only, never the cloud metadata endpoints
     * (link-local 169.254.0.0/16, fd00:ec2::254, metadata.google.internal).
     *
     * Hardened installations can restrict the allowed URLs further with an
     * array of regular expressions in config/local_define.php:
     *   define('TICKETASSISTANT_URL_ALLOWLIST', ['#^https://api\.openai\.com/#']);
     *
     * GLPI_SERVERSIDE_URL_ALLOWLIST is NOT used: its default expression does
     * not accept explicit ports, which every local model server needs
     * (Ollama :11434, LM Studio :1234, vLLM :8000).
     */
    public static function isOutboundUrlAllowed(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if (
            $host === '' || $host === 'fd00:ec2::254' || $host === 'metadata.google.internal'
            || strpos($host, '169.254.') === 0
        ) {
            return false;
        }
        if (defined('TICKETASSISTANT_URL_ALLOWLIST') && is_array(constant('TICKETASSISTANT_URL_ALLOWLIST'))) {
            foreach (constant('TICKETASSISTANT_URL_ALLOWLIST') as $pattern) {
                if (is_string($pattern) && @preg_match($pattern, $url) === 1) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }
}
