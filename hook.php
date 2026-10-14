<?php

/**
 * GLPI AI Ticket Assistant — install, upgrade and uninstall.
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

use GlpiPlugin\Ticketassistant\Compat;
use GlpiPlugin\Ticketassistant\Config;
use GlpiPlugin\Ticketassistant\Knowledge\CaseLibrary;
use GlpiPlugin\Ticketassistant\Knowledge\Learner;
use GlpiPlugin\Ticketassistant\Knowledge\Triage;
use GlpiPlugin\Ticketassistant\Log;
use GlpiPlugin\Ticketassistant\Profile;

/**
 * Tables of the plugin. Unsigned keys, TIMESTAMP and the charset/collation
 * of the installation, as GLPI 10 and 11 expect them.
 *
 * @return array<string, string> table => CREATE statement
 */
function plugin_ticketassistant_schema(): array
{
    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();
    $options   = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

    return [
        'glpi_plugin_ticketassistant_logs' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_ticketassistant_logs` (
            `id`                INT {$sign} NOT NULL AUTO_INCREMENT,
            `users_id`          INT {$sign} NOT NULL DEFAULT 0,
            `tickets_id`        INT {$sign} NOT NULL DEFAULT 0,
            `action`            VARCHAR(20) NOT NULL DEFAULT '',
            `mode`              VARCHAR(20) NOT NULL DEFAULT '',
            `status`            VARCHAR(30) NOT NULL DEFAULT '',
            `provider`          VARCHAR(30) NOT NULL DEFAULT '',
            `model`             VARCHAR(100) NOT NULL DEFAULT '',
            `http_code`         SMALLINT DEFAULT NULL,
            `prompt_tokens`     INT NOT NULL DEFAULT 0,
            `completion_tokens` INT NOT NULL DEFAULT 0,
            `reasoning_tokens`  INT NOT NULL DEFAULT 0,
            `cost_usd`          DECIMAL(12,6) NOT NULL DEFAULT 0,
            `duration_ms`       INT DEFAULT NULL,
            `images_count`      SMALLINT NOT NULL DEFAULT 0,
            `documents_count`   SMALLINT NOT NULL DEFAULT 0,
            `kb_hits`           SMALLINT NOT NULL DEFAULT 0,
            `private_included`  TINYINT NOT NULL DEFAULT 0,
            `feedback`          TINYINT DEFAULT NULL,
            `used`              TINYINT NOT NULL DEFAULT 0,
            `error_message`     VARCHAR(255) DEFAULT NULL,
            `response_json`     MEDIUMTEXT,
            `date_creation`     TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_date` (`users_id`, `date_creation`),
            KEY `tickets_action` (`tickets_id`, `action`, `date_creation`),
            KEY `action_date` (`action`, `date_creation`),
            KEY `date_creation` (`date_creation`)
        ) {$options}",

        'glpi_plugin_ticketassistant_cases' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_ticketassistant_cases` (
            `id`                INT {$sign} NOT NULL AUTO_INCREMENT,
            `tickets_id`        INT {$sign} NOT NULL,
            `entities_id`       INT {$sign} NOT NULL DEFAULT 0,
            `itilcategories_id` INT {$sign} NOT NULL DEFAULT 0,
            `type`              TINYINT NOT NULL DEFAULT 0,
            `category_name`     VARCHAR(255) DEFAULT NULL,
            `problem_text`      TEXT,
            `solution_text`     TEXT,
            `ai_status`         VARCHAR(20) NOT NULL DEFAULT 'pending',
            `ai_attempts`       TINYINT NOT NULL DEFAULT 0,
            `ai_summary`        TEXT,
            `ai_resolution`     TEXT,
            `ai_keywords`       VARCHAR(500) DEFAULT NULL,
            `ai_type`           TINYINT NOT NULL DEFAULT 0,
            `ai_category_id`    INT {$sign} NOT NULL DEFAULT 0,
            `ai_reusable`       TINYINT NOT NULL DEFAULT 1,
            `ai_date`           TIMESTAMP NULL DEFAULT NULL,
            `embedding`         MEDIUMTEXT,
            `embedding_model`   VARCHAR(100) DEFAULT NULL,
            `content_hash`      CHAR(40) DEFAULT NULL,
            `date_solved`       TIMESTAMP NULL DEFAULT NULL,
            `date_mod`          TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tickets_id` (`tickets_id`),
            KEY `itilcategories_id` (`itilcategories_id`),
            KEY `entities_id` (`entities_id`),
            KEY `ai_status` (`ai_status`),
            KEY `date_solved` (`date_solved`),
            FULLTEXT KEY `search` (`problem_text`, `solution_text`, `ai_summary`, `ai_keywords`)
        ) {$options}",

        'glpi_plugin_ticketassistant_suggestions' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_ticketassistant_suggestions` (
            `id`                 INT {$sign} NOT NULL AUTO_INCREMENT,
            `tickets_id`         INT {$sign} NOT NULL,
            `status`             VARCHAR(20) NOT NULL DEFAULT 'pending',
            `method`             VARCHAR(20) NOT NULL DEFAULT '',
            `type_suggested`     TINYINT NOT NULL DEFAULT 0,
            `category_suggested` INT {$sign} NOT NULL DEFAULT 0,
            `urgency_suggested`  TINYINT NOT NULL DEFAULT 0,
            `confidence`         DECIMAL(5,4) NOT NULL DEFAULT 0,
            `justification`      VARCHAR(500) DEFAULT NULL,
            `candidates`         TEXT,
            `type_before`        TINYINT NOT NULL DEFAULT 0,
            `category_before`    INT {$sign} NOT NULL DEFAULT 0,
            `users_id`           INT {$sign} NOT NULL DEFAULT 0,
            `date_creation`      TIMESTAMP NULL DEFAULT NULL,
            `date_mod`           TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tickets_id` (`tickets_id`),
            KEY `status` (`status`),
            KEY `date_creation` (`date_creation`)
        ) {$options}",

        'glpi_plugin_ticketassistant_categoryguidelines' => "CREATE TABLE IF NOT EXISTS `glpi_plugin_ticketassistant_categoryguidelines` (
            `id`                INT {$sign} NOT NULL AUTO_INCREMENT,
            `itilcategories_id` INT {$sign} NOT NULL,
            `guidelines`        TEXT,
            `is_recursive`      TINYINT NOT NULL DEFAULT 1,
            `date_mod`          TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `itilcategories_id` (`itilcategories_id`)
        ) {$options}",
    ];
}

/**
 * Plugin install and upgrade process.
 *
 * Idempotent: creates what is missing and keeps the configuration, the keys,
 * the logs and the library of solved tickets.
 *
 * @return boolean
 */
function plugin_ticketassistant_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $migration = new Migration(PLUGIN_TICKETASSISTANT_VERSION);

    foreach (plugin_ticketassistant_schema() as $table => $create) {
        if (!$DB->tableExists($table)) {
            if (!Compat::rawExecute($create)) {
                $migration->displayMessage(sprintf('AI Ticket Assistant: could not create %s', $table));
                return false;
            }
        }
    }

    // Future versions: $migration->addField(...) / addKey(...) for the changes
    $migration->executeMigration();

    $first_install = \Config::getConfigurationValues(Config::CONTEXT, ['rights_initialized']) === [];
    Config::installDefaults();

    // Rights: created for every profile; granted to the profiles that answer
    // tickets on the FIRST installation only (upgrades never re-grant)
    Profile::initProfile();
    if ($first_install) {
        Profile::grantToSolverProfiles();
        \Config::setConfigurationValues(Config::CONTEXT, ['rights_initialized' => 1]);
    }

    $tasks = [
        [CaseLibrary::class, 'aisync', DAY_TIMESTAMP, CronTask::STATE_WAITING, __('AI Ticket Assistant: synchronizes the library of solved tickets', 'ticketassistant'), null],
        [Learner::class, 'ailearn', HOUR_TIMESTAMP, CronTask::STATE_WAITING, __('AI Ticket Assistant: learns from solved tickets (distillation and embeddings)', 'ticketassistant'), 0],
        [Triage::class, 'aitriage', 5 * MINUTE_TIMESTAMP, CronTask::STATE_WAITING, __('AI Ticket Assistant: suggests the type and category of new tickets', 'ticketassistant'), 50],
        [Log::class, 'aipurge', DAY_TIMESTAMP, CronTask::STATE_WAITING, __('AI Ticket Assistant: applies the retention policy of the AI assistant log', 'ticketassistant'), null],
    ];
    foreach ($tasks as [$itemtype, $name, $frequency, $state, $comment, $param]) {
        $options = [
            'state'         => $state,
            'mode'          => CronTask::MODE_EXTERNAL,
            'logs_lifetime' => 30,
            'comment'       => $comment,
        ];
        if ($param !== null) {
            $options['param'] = $param;
        }
        // Returns false when the task already exists
        CronTask::register($itemtype, $name, $frequency, $options);
    }

    return true;
}

/**
 * Plugin uninstall process: removes tables, configuration, rights and
 * automatic actions. The API keys stay valid at the provider: revoke them.
 *
 * @return boolean
 */
function plugin_ticketassistant_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    foreach (array_keys(plugin_ticketassistant_schema()) as $table) {
        if ($DB->tableExists($table)) {
            Compat::rawExecute('DROP TABLE `' . $table . '`');
        }
    }

    $values = \Config::getConfigurationValues(Config::CONTEXT);
    if (is_array($values) && count($values)) {
        \Config::deleteConfigurationValues(Config::CONTEXT, array_keys($values));
    }

    Profile::removeRights();
    CronTask::unregister('ticketassistant');

    return true;
}
