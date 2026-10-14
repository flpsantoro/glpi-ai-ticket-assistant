<?php

/**
 * Unit tests bootstrap: runs WITHOUT a GLPI installation.
 *
 * Only classes that do not touch the database are tested here; the GLPI
 * integration is covered by the end-to-end procedure in docs/DEVELOPMENT.md.
 */

if (!defined('GLPI_VERSION')) {
    define('GLPI_VERSION', getenv('GLPI_VERSION_FOR_TESTS') ?: '11.0.0');
}
if (!defined('PLUGIN_TICKETASSISTANT_VERSION')) {
    define('PLUGIN_TICKETASSISTANT_VERSION', 'test');
}

if (!function_exists('__')) {
    function __($str, $domain = 'glpi')
    {
        return $str;
    }
}
if (!function_exists('_n')) {
    function _n($singular, $plural, $nb, $domain = 'glpi')
    {
        return $nb > 1 ? $plural : $singular;
    }
}
if (!function_exists('_x')) {
    function _x($context, $str, $domain = 'glpi')
    {
        return $str;
    }
}

$composer = __DIR__ . '/../vendor/autoload.php';
if (is_file($composer)) {
    require_once $composer;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Ticketassistant\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
