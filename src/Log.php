<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use CommonDBTM;
use CronTask;
use Session;

/**
 * Audit and cost log (table glpi_plugin_ticketassistant_logs): one row per attempt
 * to use the AI, successful or not. It also drives the guard rails (daily
 * budgets, per-user hourly limit, per-ticket cooldown) and the statistics.
 */
class Log extends CommonDBTM
{
    public const TABLE = 'glpi_plugin_ticketassistant_logs';

    /** Actions triggered by a technician (count in the interactive budget and hourly limit) */
    public const INTERACTIVE_ACTIONS = ['analyze', 'improve', 'classify', 'article'];

    /** Actions run by automatic tasks (count in the background budget) */
    public const BACKGROUND_ACTIONS = ['distill', 'embed', 'triage'];

    // No $rightname redeclaration: the property is typed (string) in GLPI 12 and
    // untyped in GLPI 10/11, so a redeclaration breaks one of them.

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    public static function getTypeName($nb = 0)
    {
        return __('AI assistant log', 'ticketassistant');
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight('config', READ);
    }

    /**
     * Inserts a log row (direct insert, no GLPI history).
     *
     * @param array $fields users_id, tickets_id, action, mode, status, provider,
     *                      model, http_code, prompt_tokens, completion_tokens,
     *                      reasoning_tokens, cost_usd, duration_ms, images_count,
     *                      documents_count, kb_hits, private_included,
     *                      error_message, response_json
     *
     * @return int id of the new row (0 on failure)
     */
    public static function record(array $fields): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $users_id = $fields['users_id'] ?? Session::getLoginUserID();
        $row      = [
            'users_id'          => (int) ($users_id ?: 0),
            'tickets_id'        => (int) ($fields['tickets_id'] ?? 0),
            'action'            => Compat::dbValue(mb_substr((string) ($fields['action'] ?? ''), 0, 20)),
            'mode'              => Compat::dbValue(mb_substr((string) ($fields['mode'] ?? ''), 0, 20)),
            'status'            => Compat::dbValue(mb_substr((string) ($fields['status'] ?? ''), 0, 30)),
            'provider'          => Compat::dbValue(mb_substr((string) ($fields['provider'] ?? ''), 0, 30)),
            'model'             => Compat::dbValue(mb_substr((string) ($fields['model'] ?? ''), 0, 100)),
            'http_code'         => isset($fields['http_code']) ? (int) $fields['http_code'] : null,
            'prompt_tokens'     => (int) ($fields['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($fields['completion_tokens'] ?? 0),
            'reasoning_tokens'  => (int) ($fields['reasoning_tokens'] ?? 0),
            'cost_usd'          => round((float) ($fields['cost_usd'] ?? 0), 6),
            'duration_ms'       => isset($fields['duration_ms']) ? (int) $fields['duration_ms'] : null,
            'images_count'      => min(255, (int) ($fields['images_count'] ?? 0)),
            'documents_count'   => min(255, (int) ($fields['documents_count'] ?? 0)),
            'kb_hits'           => min(255, (int) ($fields['kb_hits'] ?? 0)),
            'private_included'  => !empty($fields['private_included']) ? 1 : 0,
            'error_message'     => isset($fields['error_message'])
                ? Compat::dbValue(mb_substr((string) $fields['error_message'], 0, 255))
                : null,
            'response_json'     => isset($fields['response_json'])
                ? Compat::dbValue((string) $fields['response_json'])
                : null,
            'date_creation'     => date('Y-m-d H:i:s'),
        ];

        try {
            $DB->insert(self::TABLE, $row);
            return (int) $DB->insertId();
        } catch (\Throwable $e) {
            Compat::log('AI Ticket Assistant: could not write the log: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Records the technician's feedback on a generation (own rows only).
     *
     * @param int  $log_id
     * @param int  $value  1 = useful, -1 = not useful, 0 = cleared
     */
    public static function setFeedback(int $log_id, int $value, int $users_id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $value = max(-1, min(1, $value));
        return (bool) $DB->update(
            self::TABLE,
            ['feedback' => $value === 0 ? null : $value],
            ['id' => $log_id, 'users_id' => $users_id, 'status' => 'ok']
        );
    }

    /**
     * Marks a generation as used: a draft inserted in the editor or copied,
     * a rewritten text submitted with its followup or solution.
     */
    public static function markUsed(int $log_id, int $users_id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        return (bool) $DB->update(
            self::TABLE,
            ['used' => 1],
            ['id' => $log_id, 'users_id' => $users_id, 'status' => 'ok']
        );
    }

    /**
     * Estimated cost (USD) since midnight for a group of actions.
     *
     * @param string[] $actions
     */
    public static function todayCostUSD(array $actions): float
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'SELECT' => [Compat::expr('COALESCE(SUM(' . $DB->quoteName('cost_usd') . '), 0) AS total')],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'action' => $actions,
                ['date_creation' => ['>=', date('Y-m-d 00:00:00')]],
            ],
        ]);
        foreach ($it as $row) {
            return (float) $row['total'];
        }
        return 0.0;
    }

    /**
     * Calls of a user during the last hour (guard-rail blocks excluded: they
     * cost nothing).
     */
    public static function userCallsLastHour(int $users_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::TABLE,
            'WHERE' => [
                'users_id' => $users_id,
                'action'   => self::INTERACTIVE_ACTIONS,
                ['date_creation' => ['>', date('Y-m-d H:i:s', time() - 3600)]],
                ['NOT' => ['status' => ['LIKE', 'blocked%']]],
            ],
        ]);
        foreach ($it as $row) {
            return (int) $row['cpt'];
        }
        return 0;
    }

    /**
     * Seconds since the last successful generation of an action on a ticket.
     *
     * @return int|null null when there was none
     */
    public static function secondsSinceLastOk(int $tickets_id, string $action): ?int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'SELECT' => [Compat::expr('MAX(' . $DB->quoteName('date_creation') . ') AS last_ok')],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'tickets_id' => $tickets_id,
                'action'     => $action,
                'status'     => 'ok',
            ],
        ]);
        foreach ($it as $row) {
            if (!empty($row['last_ok'])) {
                return max(0, time() - (int) strtotime((string) $row['last_ok']));
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Retention (automatic action "aipurge")
    // ------------------------------------------------------------------

    public static function cronInfo($name)
    {
        if ($name === 'aipurge') {
            return ['description' => __('AI Ticket Assistant: applies the retention policy of the AI assistant log', 'ticketassistant')];
        }
        return [];
    }

    /**
     * @param CronTask $task
     *
     * @return int 1 = done, 0 = nothing to do
     */
    public static function cronAipurge($task)
    {
        $result = self::purge(Config::getConfig());
        $task->addVolume($result['texts'] + $result['rows']);
        $task->log(sprintf(
            __('%1$d generated texts cleared, %2$d log rows deleted', 'ticketassistant'),
            $result['texts'],
            $result['rows']
        ));
        return ($result['texts'] + $result['rows']) > 0 ? 1 : 0;
    }

    /**
     * @return array{texts: int, rows: int}
     */
    public static function purge(array $config): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $texts = 0;
        $rows  = 0;

        $text_days = (int) ($config['response_retention_days'] ?? 0);
        if ($text_days > 0) {
            $DB->update(
                self::TABLE,
                ['response_json' => null],
                [
                    ['date_creation' => ['<', date('Y-m-d H:i:s', strtotime("-{$text_days} days"))]],
                    ['NOT' => ['response_json' => null]],
                ]
            );
            $texts = (int) $DB->affectedRows();
        }

        // Never delete rows of the current day: guard rails are computed on them
        $row_days = max(0, (int) ($config['log_retention_days'] ?? 0));
        if ($row_days > 0) {
            $DB->delete(
                self::TABLE,
                [['date_creation' => ['<', date('Y-m-d 00:00:00', strtotime('-' . max(1, $row_days) . ' days'))]]]
            );
            $rows = (int) $DB->affectedRows();
        }

        return ['texts' => $texts, 'rows' => $rows];
    }
}
