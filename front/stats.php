<?php

/**
 * GLPI AI Ticket Assistant — usage statistics.
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

use GlpiPlugin\Ticketassistant\Menu;
use GlpiPlugin\Ticketassistant\Stats;

if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

Session::checkRight('config', READ);

Html::header(__('AI assistant — statistics', 'ticketassistant'), '', 'config', Menu::class, 'stats');
Stats::show();
Html::footer();
