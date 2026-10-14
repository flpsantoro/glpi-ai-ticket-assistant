<?php

/**
 * GLPI AI Ticket Assistant — actions triggered from the ticket.
 *
 * POST action = analyze | improve | classify | apply | dismiss | article |
 *               create_article | feedback | used
 * Answer: JSON (see GlpiPlugin\Ticketassistant\Assistant::handle()).
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

use GlpiPlugin\Ticketassistant\Assistant;
use GlpiPlugin\Ticketassistant\Compat;
use GlpiPlugin\Ticketassistant\Config;
use GlpiPlugin\Ticketassistant\Profile;

// GLPI 10: bootstrap and CSRF check (X-Glpi-Csrf-Token sent by jQuery);
// GLPI 11+: done by the kernel before this script runs
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

if (!Session::getLoginUserID()) {
    $reply(Assistant::fail('session', __('Your session has expired: reload the page.', 'ticketassistant')), 401);
    return;
}
if (!Profile::canUse()) {
    $reply(Assistant::fail('forbidden', __('You are not allowed to use the assistant.', 'ticketassistant')), 403);
    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $reply(Assistant::fail('badrequest', 'POST only'), 405);
    return;
}

$post   = Compat::rawPost();
$action = (string) ($post['action'] ?? '');

@set_time_limit(max(120, (int) Config::getConfig()['http_timeout'] * 3 + 30));

// The AI call can take a minute: release the session lock so that the other
// GLPI tabs of the technician keep working meanwhile (GLPI core does the same
// for its own long requests). The session is only read from here on.
session_write_close();

$reply(Assistant::handle($action, $post));
