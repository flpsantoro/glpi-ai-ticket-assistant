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
use CommonITILObject;
use ITILFollowup;
use ITILSolution;
use Session;
use Ticket;

/**
 * Integration with the ticket form.
 *
 *  - timeline_actions (GLPI 10, 11 and 12): "AI assistant" button in the
 *    footer of the timeline, plus the configuration read by the script;
 *  - post_item_form: "Improve my text" button rendered by the server inside
 *    the followup and solution forms (also when GLPI 11.0.9+ loads these
 *    forms lazily).
 *
 * Fail-open: when anything is missing (right, configuration, ticket state)
 * nothing is printed and GLPI works as usual.
 */
class TicketHooks extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('AI assistant', 'ticketassistant');
    }

    /**
     * @param array $options ['item' => CommonITILObject, 'rand' => int]
     */
    public static function timelineActions($options = []): void
    {
        try {
            $item = $options['item'] ?? null;
            if (!($item instanceof Ticket) || $item->isNewItem() || !Profile::canUse()) {
                return;
            }
            $config = Config::getConfig();
            if (!Config::isReady($config)) {
                return;
            }
            $closed  = (int) $item->fields['status'] === CommonITILObject::CLOSED;
            $actions = [
                'analyze'  => !empty($config['action_analyze']) && !$closed,
                'improve'  => !empty($config['action_improve']) && !$closed,
                'classify' => !empty($config['action_classify']) && !$closed,
                'article'  => !empty($config['action_article']) && in_array((int) $item->fields['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true),
            ];
            if (!in_array(true, $actions, true)) {
                return;
            }

            $suggestion = $actions['classify'] ? Assistant::storedSuggestion($item) : null;
            $settings   = [
                'ticketsId'  => (int) $item->getID(),
                'ajaxUrl'    => Compat::url('ajax/assistant.php'),
                'actions'    => $actions,
                'cooldown'   => (int) $config['ticket_cooldown_seconds'],
                'canUpdate'  => (bool) $item->canUpdateItem(),
                'suggestion' => $suggestion,
                'i18n'       => self::jsStrings(),
            ];

            echo "<li class='ticketassistant-timeline'>";
            // Read by public/js/assistant.js (JSON, not executed: CSP friendly)
            echo "<script type='application/json' class='ticketassistant-settings'>"
                . str_replace('</', '<\/', (string) json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP))
                . '</script>';
            echo "<div class='btn-group'>";
            $main = $actions['analyze'] ? 'analyze' : ($actions['classify'] ? 'classify' : 'article');
            echo "<button type='button' class='btn btn-primary ticketassistant-open' data-ticketassistant-tab='" . $main . "'"
                . " title='" . Text::e(__('Read the ticket (text, images and documents) and suggest a strategy, a reply and a classification', 'ticketassistant')) . "'>"
                . "<i class='ti ti-sparkles me-1'></i>" . Text::e(__('AI assistant', 'ticketassistant')) . '</button>';
            if ($suggestion !== null) {
                echo "<button type='button' class='btn btn-outline-primary ticketassistant-open ticketassistant-suggestion' data-ticketassistant-tab='classify'"
                    . " title='" . Text::e(__('A classification was suggested for this ticket', 'ticketassistant')) . "'>"
                    . "<i class='ti ti-tag me-1'></i>" . Text::e(sprintf(__('Suggested category (%d%%)', 'ticketassistant'), (int) round($suggestion['confidence'] * 100)))
                    . '</button>';
            }
            echo '</div>';
            echo '</li>';
        } catch (\Throwable $e) {
            Compat::log('timeline_actions: ' . $e->getMessage());
        }
    }

    /**
     * post_item_form hook: "Improve my text" inside new followup / solution forms.
     *
     * @param array $params ['item' => CommonDBTM, 'options' => array]
     */
    public static function postItemForm($params = []): void
    {
        try {
            $item = $params['item'] ?? null;
            if (!($item instanceof ITILFollowup) && !($item instanceof ITILSolution)) {
                return;
            }
            // New items only, on tickets
            if ((int) ($item->fields['id'] ?? 0) > 0) {
                return;
            }
            $parent = $params['options']['parent'] ?? null;
            if ($parent !== null && !($parent instanceof Ticket)) {
                return;
            }
            if (!Profile::canUse()) {
                return;
            }
            $config = Config::getConfig();
            if (!Config::isReady($config) || empty($config['action_improve'])) {
                return;
            }

            $modes = [
                'improve'   => [__('Improve my text', 'ticketassistant'), 'ti-wand'],
                'formal'    => [__('More formal', 'ticketassistant'), 'ti-tie'],
                'friendly'  => [__('Friendlier', 'ticketassistant'), 'ti-mood-smile'],
                'shorter'   => [__('Shorter', 'ticketassistant'), 'ti-arrows-minimize'],
                'fix'       => [__('Fix spelling and grammar only', 'ticketassistant'), 'ti-text-spellcheck'],
                'translate' => [__("Translate to the requester's language", 'ticketassistant'), 'ti-language'],
            ];

            // The form can be loaded after the timeline (GLPI 11.0.9+): it carries what the script needs
            echo "<div class='ticketassistant-improve btn-group mt-2 mb-2' role='group'"
                . " data-ticketassistant-url='" . Text::e(Compat::url('ajax/assistant.php')) . "'"
                . " data-ticketassistant-tickets-id='" . (int) ($parent instanceof Ticket ? $parent->getID() : 0) . "'"
                . " data-msg-improving='" . Text::e(__('Improving…', 'ticketassistant')) . "'"
                . " data-msg-improved='" . Text::e(__('Text rewritten: review it before sending (Ctrl+Z undoes it).', 'ticketassistant')) . "'"
                . " data-msg-empty='" . Text::e(__('Write a draft in the editor first.', 'ticketassistant')) . "'"
                . " data-msg-noeditor='" . Text::e(__('The editor could not be found. Use "Copy" and paste the text manually.', 'ticketassistant')) . "'"
                . " data-msg-title='" . Text::e(__('AI assistant', 'ticketassistant')) . "'"
                . '>';
            echo "<button type='button' class='btn btn-outline-secondary ticketassistant-improve-btn' data-ticketassistant-mode='improve'"
                . " title='" . Text::e(__('Rewrite the draft following the communication standard of the team (AI)', 'ticketassistant')) . "'>"
                . "<i class='ti ti-wand me-1'></i>" . Text::e(__('Improve my text (AI)', 'ticketassistant')) . '</button>';
            echo "<button type='button' class='btn btn-outline-secondary dropdown-toggle dropdown-toggle-split' data-bs-toggle='dropdown' aria-expanded='false'>"
                . "<span class='visually-hidden'>" . Text::e(__('More options', 'ticketassistant')) . '</span></button>';
            echo "<ul class='dropdown-menu'>";
            foreach ($modes as $mode => [$label, $icon]) {
                echo "<li><a class='dropdown-item ticketassistant-improve-btn' href='#' data-ticketassistant-mode='" . $mode . "'>"
                    . "<i class='ti " . $icon . " me-2'></i>" . Text::e($label) . '</a></li>';
            }
            echo '</ul>';
            echo '</div>';
        } catch (\Throwable $e) {
            Compat::log('post_item_form: ' . $e->getMessage());
        }
    }

    /**
     * item_add hook on followups and solutions: a text rewritten by the
     * assistant counts as used when the technician submits it (the script
     * adds the hidden field _ticketassistant_log_id to the form).
     *
     * @param \CommonDBTM $item
     */
    public static function onAnswerAdd($item): void
    {
        try {
            $log_id   = (int) ($item->input['_ticketassistant_log_id'] ?? 0);
            $users_id = (int) Session::getLoginUserID();
            if ($log_id > 0 && $users_id > 0) {
                Log::markUsed($log_id, $users_id);
            }
        } catch (\Throwable $e) {
            Compat::log('item_add: ' . $e->getMessage());
        }
    }

    /**
     * Translated strings used by public/js/assistant.js.
     *
     * @return array<string, string>
     */
    public static function jsStrings(): array
    {
        return [
            'title'            => __('AI assistant', 'ticketassistant'),
            'ticket'           => __('Ticket', 'ticketassistant'),
            'tab_analysis'     => __('Analysis', 'ticketassistant'),
            'tab_reply'        => __('Reply to the requester', 'ticketassistant'),
            'tab_classify'     => __('Classification', 'ticketassistant'),
            'tab_article'      => __('Knowledge base', 'ticketassistant'),
            'summary'          => __('Summary', 'ticketassistant'),
            'strategy'         => __('Technical strategy', 'ticketassistant'),
            'missing'          => __('Information still needed', 'ticketassistant'),
            'similar'          => __('Similar solved tickets used as references', 'ticketassistant'),
            'same_category'    => __('same category', 'ticketassistant'),
            'internal_notice'  => __('Internal guidance for the technician: it is never sent to the requester.', 'ticketassistant'),
            'draft_notice'     => __('Draft: review it before sending it to the requester.', 'ticketassistant'),
            'loading_analyze'  => __('Reading the ticket, its history and its attachments…', 'ticketassistant'),
            'loading_hint'     => __('This can take up to a minute.', 'ticketassistant'),
            'loading_classify' => __('Comparing with solved tickets…', 'ticketassistant'),
            'loading_article'  => __('Writing the article…', 'ticketassistant'),
            'start_analyze'    => __('Analyze the ticket', 'ticketassistant'),
            'start_classify'   => __('Suggest a classification', 'ticketassistant'),
            'start_article'    => __('Write a knowledge base article', 'ticketassistant'),
            'regenerate'       => __('Generate again', 'ticketassistant'),
            'retry'            => __('Try again', 'ticketassistant'),
            'copy'             => __('Copy', 'ticketassistant'),
            'copied'           => __('Copied!', 'ticketassistant'),
            'insert_followup'  => __('Insert as followup', 'ticketassistant'),
            'insert_solution'  => __('Insert as solution', 'ticketassistant'),
            'inserted'         => __('Draft inserted in the editor: review it before sending.', 'ticketassistant'),
            'replace_confirm'  => __('The editor already contains text. Replace it with the AI draft? (Ctrl+Z undoes it)', 'ticketassistant'),
            'no_editor'        => __('The editor could not be found. Use "Copy" and paste the text manually.', 'ticketassistant'),
            'useful'           => __('Useful', 'ticketassistant'),
            'not_useful'       => __('Not useful', 'ticketassistant'),
            'thanks'           => __('Thank you for the feedback.', 'ticketassistant'),
            'cached'           => __('Analysis generated on %1$s by %2$s, the ticket has not changed since then.', 'ticketassistant'),
            'current'          => __('Current', 'ticketassistant'),
            'suggested'        => __('Suggested', 'ticketassistant'),
            'type'             => __('Type', 'ticketassistant'),
            'category'         => __('Category', 'ticketassistant'),
            'urgency'          => __('Urgency', 'ticketassistant'),
            'confidence'       => __('Confidence', 'ticketassistant'),
            'other_candidates' => __('Other candidates', 'ticketassistant'),
            'apply'            => __('Apply to the ticket', 'ticketassistant'),
            'apply_type'       => __('Apply the type', 'ticketassistant'),
            'apply_category'   => __('Apply the category', 'ticketassistant'),
            'dismiss'          => __('Dismiss', 'ticketassistant'),
            'method_knn'       => __('Based on similar solved tickets', 'ticketassistant'),
            'method_llm'       => __('Chosen by the AI among the categories of similar solved tickets', 'ticketassistant'),
            'no_suggestion'    => __('No suggestion: the library of solved tickets has no similar case yet.', 'ticketassistant'),
            'create_article'   => __('Create a draft article', 'ticketassistant'),
            'article_notice'   => __('The article is created unpublished: review it and choose its audience in the knowledge base.', 'ticketassistant'),
            'open_article'     => __('Open the article', 'ticketassistant'),
            'improving'        => __('Improving…', 'ticketassistant'),
            'improved'         => __('Text rewritten: review it before sending (Ctrl+Z undoes it).', 'ticketassistant'),
            'empty_draft'      => __('Write a draft in the editor first.', 'ticketassistant'),
            'http_error'       => __('The assistant could not be reached (HTTP %s).', 'ticketassistant'),
            'forbidden'        => __('You are not allowed to use the assistant.', 'ticketassistant'),
            'unknown_error'    => __('Unknown error.', 'ticketassistant'),
            'tokens'           => __('tokens', 'ticketassistant'),
            'image'            => __('image', 'ticketassistant'),
            'images'           => __('images', 'ticketassistant'),
            'document'         => __('document', 'ticketassistant'),
            'documents'        => __('documents', 'ticketassistant'),
        ];
    }
}
