<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use CommonGLPI;
use Session;

/**
 * Entry in Setup > "AI Ticket Assistant" (configuration, knowledge, statistics).
 */
class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('AI Ticket Assistant', 'ticketassistant');
    }

    public static function getMenuName()
    {
        return __('AI Ticket Assistant', 'ticketassistant');
    }

    public static function getIcon()
    {
        return 'ti ti-sparkles';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight('config', READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Menu pages are relative to the GLPI root.
     */
    private static function page(string $script): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $root = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        $url  = Compat::url($script);
        return ($root !== '' && strpos($url, $root) === 0) ? substr($url, strlen($root)) : $url;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        return [
            'title'   => self::getMenuName(),
            'page'    => self::page('front/config.form.php'),
            'icon'    => self::getIcon(),
            'options' => [
                'knowledge' => [
                    'title' => __('Learning from solved tickets', 'ticketassistant'),
                    'page'  => self::page('front/knowledge.php'),
                    'icon'  => 'ti ti-school',
                ],
                'stats' => [
                    'title' => __('Statistics', 'ticketassistant'),
                    'page'  => self::page('front/stats.php'),
                    'icon'  => 'ti ti-chart-bar',
                ],
            ],
        ];
    }
}
