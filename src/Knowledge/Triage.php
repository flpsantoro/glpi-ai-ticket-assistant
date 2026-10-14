<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Knowledge;

use CronTask;
use GlpiPlugin\Ticketassistant\Compat;
use GlpiPlugin\Ticketassistant\Config;
use GlpiPlugin\Ticketassistant\Log;
use Ticket;

/**
 * Classification suggestions (table glpi_plugin_ticketassistant_suggestions):
 * one row per ticket, created when a technician asks for a classification
 * or, with automatic triage enabled, when the ticket is created (queue
 * processed by the automatic action "aitriage").
 *
 * The row keeps what was suggested and what happened to it (applied,
 * dismissed, automatically applied), which gives the acceptance rate and,
 * once the ticket is solved, the real accuracy of the suggestions.
 */
final class Triage extends \CommonGLPI
{
    public const TABLE = 'glpi_plugin_ticketassistant_suggestions';

    public const STATUS_PENDING      = 'pending';
    public const STATUS_READY        = 'ready';
    public const STATUS_APPLIED      = 'applied';
    public const STATUS_AUTO_APPLIED = 'auto_applied';
    public const STATUS_DISMISSED    = 'dismissed';
    public const STATUS_ERROR        = 'error';

    public static function getTypeName($nb = 0)
    {
        return __('AI Ticket Assistant', 'ticketassistant') . ' — ' . __('Automatic triage', 'ticketassistant');
    }

    /**
     * item_add hook on tickets: queues the new ticket for triage. Never
     * throws: ticket creation must not depend on the assistant.
     */
    public static function onTicketAdd($ticket): void
    {
        try {
            if (!($ticket instanceof Ticket)) {
                return;
            }
            $config = Config::getConfig();
            if (empty($config['enabled']) || empty($config['triage_enabled'])) {
                return;
            }
            if (!empty($config['triage_only_uncategorized']) && (int) ($ticket->fields['itilcategories_id'] ?? 0) > 0) {
                return;
            }
            self::save((int) $ticket->getID(), ['status' => self::STATUS_PENDING]);
        } catch (\Throwable $e) {
            Compat::log('Triage queue: ' . $e->getMessage());
        }
    }

    /**
     * Creates or updates the suggestion of a ticket.
     */
    public static function save(int $tickets_id, array $fields): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $values = ['date_mod' => date('Y-m-d H:i:s')];
        foreach (['status', 'method', 'justification', 'candidates'] as $key) {
            if (array_key_exists($key, $fields)) {
                $values[$key] = Compat::dbValue(mb_substr((string) $fields[$key], 0, $key === 'candidates' ? 60000 : 500));
            }
        }
        foreach (['type_suggested', 'category_suggested', 'urgency_suggested', 'type_before', 'category_before', 'users_id'] as $key) {
            if (array_key_exists($key, $fields)) {
                $values[$key] = (int) $fields[$key];
            }
        }
        if (array_key_exists('confidence', $fields)) {
            $values['confidence'] = round(max(0.0, min(1.0, (float) $fields['confidence'])), 4);
        }

        $exists = $DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => ['tickets_id' => $tickets_id]])->count() > 0;
        if ($exists) {
            $DB->update(self::TABLE, $values, ['tickets_id' => $tickets_id]);
        } else {
            $values['tickets_id']    = $tickets_id;
            $values['date_creation'] = $values['date_mod'];
            $DB->insert(self::TABLE, $values);
        }
    }

    /**
     * Row values describing a suggestion and the classification the ticket
     * had when it was made (read them before applying the suggestion).
     *
     * @param array $suggestion result of Suggester::suggest()
     *
     * @return array<string, mixed>
     */
    public static function fieldsFromSuggestion(Ticket $ticket, array $suggestion, int $users_id): array
    {
        return [
            'method'             => (string) $suggestion['method'],
            'type_suggested'     => (int) $suggestion['type'],
            'category_suggested' => (int) $suggestion['category_id'],
            'urgency_suggested'  => (int) ($suggestion['urgency'] ?? 0),
            'confidence'         => (float) $suggestion['confidence'],
            'justification'      => (string) $suggestion['justification'],
            'candidates'         => (string) json_encode($suggestion['candidates']),
            'type_before'        => (int) $ticket->fields['type'],
            'category_before'    => (int) $ticket->fields['itilcategories_id'],
            'users_id'           => $users_id,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(int $tickets_id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['tickets_id' => $tickets_id], 'LIMIT' => 1]) as $row) {
            return $row;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Automatic action "aitriage"
    // ------------------------------------------------------------------

    public static function cronInfo($name)
    {
        if ($name === 'aitriage') {
            return [
                'description' => __('AI Ticket Assistant: suggests the type and category of new tickets', 'ticketassistant'),
                'parameter'   => __('Tickets per run', 'ticketassistant'),
            ];
        }
        return [];
    }

    /**
     * @param CronTask $task
     */
    public static function cronAitriage($task)
    {
        $config = Config::getConfig();
        if (empty($config['enabled']) || empty($config['triage_enabled'])) {
            return 0;
        }
        $limit  = max(1, (int) ($task->fields['param'] ?? 50));
        $result = self::processQueue($config, $limit);
        $task->addVolume($result['processed']);
        $task->log(sprintf(
            __('%1$d tickets classified, %2$d applied automatically', 'ticketassistant'),
            $result['processed'],
            $result['auto_applied']
        ));
        return $result['processed'] > 0 ? 1 : 0;
    }

    /**
     * @return array{processed: int, auto_applied: int}
     */
    public static function processQueue(array $config, int $limit): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $processed = 0;
        $applied   = 0;
        $rows      = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['status' => self::STATUS_PENDING],
            'ORDER'  => 'id ASC',
            'LIMIT'  => $limit,
        ]);

        $tickets = [];
        foreach ($rows as $row) {
            $tickets[] = (int) $row['tickets_id'];
        }

        foreach ($tickets as $tickets_id) {
            $ticket = new Ticket();
            if (!$ticket->getFromDB($tickets_id)) {
                self::save($tickets_id, ['status' => self::STATUS_ERROR, 'justification' => 'ticket not found']);
                continue;
            }
            $use_llm = !empty($config['triage_use_llm'])
                && Log::todayCostUSD(Log::BACKGROUND_ACTIONS) < (float) $config['background_budget_usd'];

            try {
                $suggestion = Suggester::suggest($ticket, $config, $use_llm, 0);
            } catch (\Throwable $e) {
                self::save($tickets_id, ['status' => self::STATUS_ERROR, 'justification' => mb_substr($e->getMessage(), 0, 200)]);
                continue;
            }
            $processed++;

            // Before the update below: it changes $ticket->fields
            $fields = self::fieldsFromSuggestion($ticket, $suggestion, 0);
            $status = self::STATUS_READY;
            if (
                !empty($config['triage_auto_apply'])
                && $suggestion['category_id'] > 0
                && $suggestion['confidence'] >= (float) $config['triage_threshold']
                && (empty($config['triage_only_uncategorized']) || (int) $ticket->fields['itilcategories_id'] === 0)
            ) {
                $input = ['id' => $tickets_id, 'itilcategories_id' => $suggestion['category_id']];
                if (in_array($suggestion['type'], [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], true)) {
                    $input['type'] = $suggestion['type'];
                }
                if ($ticket->update(Compat::itemInput($input))) {
                    $status = self::STATUS_AUTO_APPLIED;
                    $applied++;
                }
            }
            self::save($tickets_id, ['status' => $status] + $fields);
        }

        return ['processed' => $processed, 'auto_applied' => $applied];
    }

    // ------------------------------------------------------------------
    // Quality indicators
    // ------------------------------------------------------------------

    /**
     * Acceptance and accuracy of the suggestions over a period.
     *
     * @return array{total: int, applied: int, auto_applied: int, dismissed: int, evaluated: int, correct: int}
     */
    public static function quality(int $days = 90): array
    {
        $since = Compat::quote(date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days')));
        $rows  = Compat::rawSelect(
            "SELECT
                COUNT(*) AS total,
                SUM(s.`status` = 'applied') AS applied,
                SUM(s.`status` = 'auto_applied') AS auto_applied,
                SUM(s.`status` = 'dismissed') AS dismissed,
                SUM(t.`status` IN (5, 6) AND s.`category_suggested` > 0) AS evaluated,
                SUM(t.`status` IN (5, 6) AND s.`category_suggested` > 0 AND t.`itilcategories_id` = s.`category_suggested`) AS correct
             FROM `" . self::TABLE . "` s
             LEFT JOIN `glpi_tickets` t ON t.`id` = s.`tickets_id`
             WHERE s.`date_creation` >= {$since} AND s.`status` <> 'pending'"
        );
        $row = $rows[0] ?? [];
        $out = [];
        foreach (['total', 'applied', 'auto_applied', 'dismissed', 'evaluated', 'correct'] as $key) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }
        return $out;
    }
}
