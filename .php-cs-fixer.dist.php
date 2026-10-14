<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

/*
 * Coding style: the baseline GLPI gives its plugins (PluginsPhpCsFixer.php in
 * the GLPI repository: PER Coding Style 3.0, imports, PHPDoc types), here
 * standalone so that it runs outside a GLPI checkout, with one change for
 * PHP 7.4 (GLPI 10): no trailing comma after the last parameter of a
 * declaration, a PHP 8.0 syntax.
 *
 *     php-cs-fixer check --diff   # what would change
 *     php-cs-fixer fix            # apply
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__)
    ->ignoreVCSIgnored(true)
    ->name('*.php');

return (new Config())
    ->setRules([
        '@PER-CS3x0' => true,
        'fully_qualified_strict_types' => ['import_symbols' => true],
        'ordered_imports' => ['imports_order' => ['class', 'const', 'function']],
        'no_unused_imports' => true,
        'heredoc_indentation' => false, // xgettext bug: https://savannah.gnu.org/bugs/?func=detailitem&item_id=62158
        'phpdoc_scalar' => true,
        'phpdoc_types' => true,
        'trailing_comma_in_multiline' => ['after_heredoc' => true, 'elements' => ['arguments', 'array_destructuring', 'arrays']],
    ])
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder);
