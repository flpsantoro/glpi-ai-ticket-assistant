<?php

/**
 * GLPI AI Ticket Assistant — configuration page (Setup > Plugins).
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

use GlpiPlugin\Ticketassistant\Compat;
use GlpiPlugin\Ticketassistant\Config;
use GlpiPlugin\Ticketassistant\Menu;

// GLPI 10 needs the bootstrap; GLPI 11+ already booted (and deprecates it in 12)
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

Session::checkRight('config', UPDATE);

if (isset($_POST['update_config'])) {
    $warnings = Config::saveFromForm(Compat::rawPost());
    Session::addMessageAfterRedirect(__('Configuration saved.', 'ticketassistant'), true, INFO);
    foreach ($warnings as $warning) {
        Session::addMessageAfterRedirect($warning, true, WARNING);
    }
    Html::back();
}

Html::header(__('AI Ticket Assistant', 'ticketassistant'), '', 'config', Menu::class);
Config::showConfigForm();
Html::footer();
