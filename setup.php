<?php

/**
 * -------------------------------------------------------------------------
 * GLPI AI Ticket Assistant
 * -------------------------------------------------------------------------
 * AI assistant for GLPI tickets: reads the whole ticket (text, history,
 * images and documents), suggests a technical strategy and a reply draft,
 * rewrites the technician's text, classifies tickets by learning from the
 * solved ones and drafts knowledge base articles. Human in the loop: nothing
 * is sent to the requester without the technician.
 * -------------------------------------------------------------------------
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Ticketassistant\CategoryGuideline;
use GlpiPlugin\Ticketassistant\Compat;
use GlpiPlugin\Ticketassistant\Knowledge\Triage;
use GlpiPlugin\Ticketassistant\Menu;
use GlpiPlugin\Ticketassistant\Profile;
use GlpiPlugin\Ticketassistant\TicketHooks;

define('PLUGIN_TICKETASSISTANT_VERSION', '1.0.0');

// GLPI versions (min inclusive, max exclusive). 10.0.11 brought DBmysql::doQuery().
define('PLUGIN_TICKETASSISTANT_MIN_GLPI', '10.0.11');
define('PLUGIN_TICKETASSISTANT_MAX_GLPI', '12.0.99');

/**
 * Init the hooks of the plugin - REQUIRED.
 *
 * Must never throw: GLPI 11 deactivates a plugin whose init fails.
 */
function plugin_init_ticketassistant()
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    // Required by GLPI 10 (string key: the constant was removed in GLPI 12)
    $PLUGIN_HOOKS['csrf_compliant']['ticketassistant'] = true;

    // API keys are encrypted with the key of the installation (glpicrypt.key).
    // Literal list: must be available even before the plugin is activated.
    $PLUGIN_HOOKS['secured_configs']['ticketassistant'] = ['api_key', 'emb_api_key'];

    if (!Plugin::isPluginActive('ticketassistant')) {
        return;
    }

    try {
        $PLUGIN_HOOKS['change_profile']['ticketassistant'] = [Profile::class, 'initProfile'];
        Plugin::registerClass(Profile::class, ['addtabon' => [\Profile::class]]);
        Plugin::registerClass(CategoryGuideline::class, ['addtabon' => [\ITILCategory::class]]);

        // Automatic triage queue (also for tickets created by the mail collector),
        // use of the rewritten texts (submitted with a followup or a solution)
        $PLUGIN_HOOKS['item_add']['ticketassistant'] = [
            \Ticket::class       => [Triage::class, 'onTicketAdd'],
            \ITILFollowup::class => [TicketHooks::class, 'onAnswerAdd'],
            \ITILSolution::class => [TicketHooks::class, 'onAnswerAdd'],
        ];

        if (!Session::getLoginUserID()) {
            return;
        }

        // Sessions opened before the installation do not have the right yet
        if (!isset($_SESSION['glpiactiveprofile'][Profile::RIGHT])) {
            Profile::initProfile();
        }

        $PLUGIN_HOOKS['timeline_actions']['ticketassistant'] = [TicketHooks::class, 'timelineActions'];
        $PLUGIN_HOOKS['post_item_form']['ticketassistant']   = [TicketHooks::class, 'postItemForm'];

        if (Session::haveRight('config', READ)) {
            $PLUGIN_HOOKS['menu_toadd']['ticketassistant'] = ['config' => Menu::class];
        }
        if (Session::haveRight('config', UPDATE)) {
            $PLUGIN_HOOKS['config_page']['ticketassistant'] = 'front/config.form.php';
        }

        // Script and styles on the ticket form only
        if (Compat::isCurrentScript('front/ticket.form.php') && isset($_GET['id'])) {
            $PLUGIN_HOOKS['add_javascript']['ticketassistant'][] = Compat::assetHookPath('js/assistant.js');
            $PLUGIN_HOOKS['add_css']['ticketassistant'][]        = Compat::assetHookPath('css/assistant.css');
        }
    } catch (\Throwable $e) {
        Compat::log('init: ' . $e->getMessage());
    }
}

/**
 * Get the name and the version of the plugin - REQUIRED.
 *
 * @return array
 */
function plugin_version_ticketassistant()
{
    return [
        'name'         => 'GLPI AI Ticket Assistant',
        'version'      => PLUGIN_TICKETASSISTANT_VERSION,
        'author'       => 'Felipe Santoro Alcantara',
        'license'      => 'GPL-2.0-or-later',
        'homepage'     => 'https://github.com/flpsantoro/glpi-ai-ticket-assistant',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_TICKETASSISTANT_MIN_GLPI,
                'max' => PLUGIN_TICKETASSISTANT_MAX_GLPI,
            ],
            'php'  => [
                'min'  => '7.4',
                'exts' => [
                    'curl'     => ['required' => true],
                    'mbstring' => ['required' => true],
                    'json'     => ['required' => true],
                ],
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install - OPTIONAL.
 *
 * GLPI checks the versions and the PHP extensions declared in the
 * "requirements" of plugin_version_ticketassistant(), with its own
 * translated messages.
 *
 * @return boolean
 */
function plugin_ticketassistant_check_prerequisites()
{
    return true;
}

/**
 * Check configuration process - OPTIONAL.
 *
 * @param boolean $verbose Whether to display message on failure.
 *
 * @return boolean
 */
function plugin_ticketassistant_check_config($verbose = false)
{
    return true;
}
