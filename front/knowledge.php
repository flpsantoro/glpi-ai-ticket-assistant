<?php

/**
 * GLPI AI Ticket Assistant — learning from solved tickets.
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

use GlpiPlugin\Ticketassistant\Compat;
use GlpiPlugin\Ticketassistant\Config;
use GlpiPlugin\Ticketassistant\Knowledge\CaseLibrary;
use GlpiPlugin\Ticketassistant\Knowledge\Classifier;
use GlpiPlugin\Ticketassistant\Knowledge\Learner;
use GlpiPlugin\Ticketassistant\KnowledgePage;
use GlpiPlugin\Ticketassistant\Menu;

if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

Session::checkRight('config', READ);

$config = Config::getConfig(true);
$post   = Compat::rawPost();

if (count($post) && Session::haveRight('config', UPDATE)) {
    @set_time_limit(0);
    if (isset($post['sync']) || isset($post['rebuild'])) {
        $stats = CaseLibrary::sync(isset($post['rebuild']), $config);
        Session::addMessageAfterRedirect(sprintf(
            __('Library synchronized: %1$d added, %2$d updated, %3$d unchanged, %4$d removed.', 'ticketassistant'),
            $stats['added'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['removed']
        ), true, INFO);
    } elseif (isset($post['learn'])) {
        $result = Learner::run($config, (int) $config['learn_batch']);
        Session::addMessageAfterRedirect(sprintf(
            __('%1$d tickets distilled, %2$d embedded, %3$d errors (US$ %4$s).', 'ticketassistant'),
            $result['distilled'],
            $result['embedded'],
            $result['errors'],
            number_format($result['cost_usd'], 4)
        ), true, $result['errors'] > 0 ? WARNING : INFO);
        if ($result['stopped'] === 'budget') {
            Session::addMessageAfterRedirect(__('Stopped: the daily budget of automatic actions is reached.', 'ticketassistant'), true, WARNING);
        }
    } elseif (isset($post['evaluate'])) {
        $evaluation = Classifier::evaluate((int) ($post['sample'] ?? 200), !empty($post['use_embeddings']), (int) $config['classify_neighbors']);
        Config::store(['last_evaluation' => (string) json_encode($evaluation, JSON_UNESCAPED_UNICODE)]);
        Session::addMessageAfterRedirect(sprintf(
            __('Evaluation done on %1$d tickets: %2$s%% right on the first suggestion.', 'ticketassistant'),
            $evaluation['sample'],
            number_format(100 * $evaluation['accuracy_top1'], 1)
        ), true, INFO);
    }
    Html::back();
}

Html::header(__('Learning from solved tickets', 'ticketassistant'), '', 'config', Menu::class, 'knowledge');
KnowledgePage::show($config);
Html::footer();
