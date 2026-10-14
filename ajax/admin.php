<?php

/**
 * GLPI AI Ticket Assistant — administration tools of the configuration page.
 *
 * POST action = test | models
 * Uses the SAVED configuration. Requires the right to update the setup.
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

use GlpiPlugin\Ticketassistant\Compat;
use GlpiPlugin\Ticketassistant\Config;
use GlpiPlugin\Ticketassistant\Diagnostics;

if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

$reply = static function (array $payload, int $status = 200): void {
    if ($status !== 200) {
        http_response_code($status);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
};

if (!Session::getLoginUserID() || !Session::haveRight('config', UPDATE)) {
    $reply(['success' => false, 'message' => __('You are not allowed to change the setup.', 'ticketassistant')], 403);
    return;
}

$post   = Compat::rawPost();
$action = (string) ($post['action'] ?? '');
$config = Config::getConfig(true);

@set_time_limit((int) $config['http_timeout'] + 30);
session_write_close();

if ($action === 'models') {
    $result = Diagnostics::listModels($config);
    $reply($result['success'] ? ['success' => true, 'models' => $result['models']] : ['success' => false, 'message' => $result['message']]);
    return;
}

if ($action === 'test') {
    $reply(Diagnostics::testConnection($config));
    return;
}

$reply(['success' => false, 'message' => 'Unknown action'], 400);
