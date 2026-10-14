<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use CommonITILObject;
use CommonITILTask;
use CommonITILValidation;
use DbUtils;
use GlpiPlugin\Ticketassistant\Knowledge\CaseLibrary;
use GlpiPlugin\Ticketassistant\Knowledge\Classifier;
use GlpiPlugin\Ticketassistant\Knowledge\Suggester;
use GlpiPlugin\Ticketassistant\Knowledge\Triage;
use GlpiPlugin\Ticketassistant\Llm\ChatRequest;
use GlpiPlugin\Ticketassistant\Llm\ChatResponse;
use GlpiPlugin\Ticketassistant\Llm\Client;
use GlpiPlugin\Ticketassistant\Llm\LlmException;
use ITILFollowup;
use ITILSolution;
use KnowbaseItem;
use KnowbaseItem_Item;
use Session;
use SolutionTemplate;
use Ticket;

/**
 * Orchestrates the actions triggered from the ticket:
 * permission -> guard rails -> context -> AI -> validation -> log.
 *
 * Human in the loop: the only write actions are the explicit "apply
 * classification" and "create knowledge base article" clicks, with the
 * technician's own GLPI rights. Drafts are only inserted in the editor.
 */
final class Assistant
{
    /**
     * Entry point of ajax/assistant.php.
     *
     * @param array $params raw request values
     *
     * @return array JSON payload: success + data, or success=false + code + message
     */
    public static function handle(string $action, array $params): array
    {
        $config   = Config::getConfig();
        $users_id = (int) Session::getLoginUserID();

        // Actions on a previous generation
        if ($action === 'feedback') {
            $ok = Log::setFeedback((int) ($params['log_id'] ?? 0), (int) ($params['value'] ?? 0), $users_id);
            return ['success' => $ok];
        }
        if ($action === 'used') {
            return ['success' => Log::markUsed((int) ($params['log_id'] ?? 0), $users_id)];
        }

        $tickets_id = (int) ($params['tickets_id'] ?? 0);
        $ticket     = new Ticket();
        if ($tickets_id <= 0 || !$ticket->getFromDB($tickets_id)) {
            return self::fail('notfound', __('Ticket not found.', 'ticketassistant'));
        }
        if (!$ticket->canViewItem()) {
            return self::fail('forbidden', __('You cannot see this ticket.', 'ticketassistant'));
        }
        if (!Config::isReady($config)) {
            return self::fail('disabled', __('The AI assistant is not enabled.', 'ticketassistant'));
        }

        $switches = [
            'analyze'        => 'action_analyze',
            'improve'        => 'action_improve',
            'classify'       => 'action_classify',
            'apply'          => 'action_classify',
            'dismiss'        => 'action_classify',
            'article'        => 'action_article',
            'create_article' => 'action_article',
        ];
        if (!isset($switches[$action])) {
            return self::fail('badrequest', __('Unknown action.', 'ticketassistant'));
        }
        if (empty($config[$switches[$action]])) {
            return self::fail('disabled', __('This action of the assistant is disabled.', 'ticketassistant'));
        }

        try {
            switch ($action) {
                case 'analyze':
                    return self::analyze($ticket, $config, $users_id, !empty($params['force']));
                case 'improve':
                    return self::improve($ticket, $config, $users_id, (string) ($params['draft_html'] ?? ''), (string) ($params['mode'] ?? 'improve'));
                case 'classify':
                    return self::classify($ticket, $config, $users_id);
                case 'apply':
                    return self::applyClassification($ticket, $params, $users_id);
                case 'dismiss':
                    Triage::save($tickets_id, ['status' => Triage::STATUS_DISMISSED, 'users_id' => $users_id]);
                    return ['success' => true];
                case 'article':
                    return self::article($ticket, $config, $users_id);
                case 'create_article':
                    return self::createArticle($ticket, $params, $users_id);
            }
        } catch (LlmException $e) {
            return self::fail($e->getKind(), $e->getFriendlyMessage());
        } catch (\Throwable $e) {
            Compat::log('Assistant ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Log::record([
                'users_id'      => $users_id,
                'tickets_id'    => $tickets_id,
                'action'        => $action,
                'status'        => 'error_internal',
                'error_message' => $e->getMessage(),
            ]);
            return self::fail('internal', __('Internal error of the assistant. If it persists, contact the administrator.', 'ticketassistant'));
        }

        return self::fail('badrequest', __('Unknown action.', 'ticketassistant'));
    }

    // ------------------------------------------------------------------
    // Analyze the ticket
    // ------------------------------------------------------------------

    private static function analyze(Ticket $ticket, array $config, int $users_id, bool $force): array
    {
        $tickets_id = (int) $ticket->getID();

        // Recent analysis of an unchanged ticket: shown again, no cost
        if (!$force && ($cached = self::cachedAnalysis($ticket, $config, $users_id)) !== null) {
            return $cached;
        }

        $blocked = self::guardRails('analyze', $tickets_id, $config, $users_id, true);
        if ($blocked !== null) {
            if ($blocked['code'] === 'blocked_cooldown' && ($cached = self::cachedAnalysis($ticket, $config, $users_id, true)) !== null) {
                $cached['meta']['notice'] = $blocked['message'];
                return $cached;
            }
            return $blocked;
        }

        $client  = Config::chatClient($config);
        $context = TicketContext::build($ticket, $config, [
            'vision'     => $client->supportsVision(),
            'native_pdf' => $client->supportsNativePdf(),
        ]);

        $similar   = self::similarCases($ticket, $config, $users_id);
        $templates = !empty($config['solution_templates']) ? self::solutionTemplates($ticket, $config) : '';

        $request                  = new ChatRequest(Prompt::systemAnalyze($config, CategoryGuideline::forCategory($context['category_id'])));
        $request->maxTokens       = (int) $config['analyze_max_tokens'];
        $request->reasoningEffort = (string) $config['analyze_effort'];
        $request->temperature     = (float) $config['temperature'];
        $request->json            = true;
        $request->jsonSchema      = Prompt::analyzeSchema();
        $request->addText(self::analyzeText($context, $similar, $templates));
        foreach ($context['files'] as $file) {
            $request->addFile($file['mime'], $file['data'], $file['name']);
        }
        foreach ($context['images'] as $image) {
            $request->addImage($image['mime'], $image['data'], $image['name']);
        }

        $response = self::call($client, $request, 'analyze', $tickets_id, $users_id);
        $parsed   = self::parse($response, Prompt::analyzeKeys(), 'analyze', $tickets_id, $users_id, $client);

        $log_id = Log::record(self::logFields($client, $response, [
            'users_id'         => $users_id,
            'tickets_id'       => $tickets_id,
            'action'           => 'analyze',
            'images_count'     => count($context['images']),
            'documents_count'  => count($context['documents']),
            'kb_hits'          => count($similar),
            'private_included' => self::privateIncluded($config) ? 1 : 0,
            'response_json'    => !empty($config['log_responses']) ? $response->content : null,
        ]));

        return self::analysisPayload($parsed, $config, $ticket, $log_id, [
            'tokens'       => $response->totalTokens(),
            'cost_usd'     => number_format($response->costUsd, 4),
            'duration_ms'  => $response->durationMs,
            'model'        => $response->model,
            'images_count' => count($context['images']),
            'documents'    => $context['documents'],
            'kb_hits'      => count($similar),
            'omitted'      => $context['omitted_followups'],
            'cached'       => false,
        ], self::similarSummary($similar));
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $meta
     */
    private static function analysisPayload(array $parsed, array $config, Ticket $ticket, int $log_id, array $meta, array $similar): array
    {
        return [
            'success'             => true,
            'action'              => 'analyze',
            'log_id'              => $log_id,
            'summary_html'        => $parsed['summary_html'],
            'strategy_html'       => $parsed['strategy_html'],
            'reply_html'          => self::withSignature($parsed['reply_html'], $config, $ticket),
            'missing_information' => array_values(array_map('strval', (array) $parsed['missing_information'])),
            'similar'             => $similar,
            'meta'                => $meta,
        ];
    }

    /**
     * Last successful analysis of the ticket when it has not changed since
     * then, generated less than 8 hours ago, visible to the current user.
     */
    private static function cachedAnalysis(Ticket $ticket, array $config, int $users_id, bool $ignore_age = false): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (empty($config['log_responses'])) {
            return null;
        }
        $criteria = [
            'tickets_id' => (int) $ticket->getID(),
            'action'     => 'analyze',
            'status'     => 'ok',
            ['NOT' => ['response_json' => null]],
            ['date_creation' => ['>=', (string) $ticket->fields['date_mod']]],
        ];
        if (!$ignore_age) {
            $criteria[] = ['date_creation' => ['>=', date('Y-m-d H:i:s', time() - 8 * 3600)]];
        }
        // An analysis built with private items is reused only by users who
        // may see them as well
        if (!self::privateIncluded($config)) {
            $criteria[] = ['OR' => ['users_id' => $users_id, 'private_included' => 0]];
        }

        $rows = $DB->request([
            'FROM'  => Log::TABLE,
            'WHERE' => $criteria,
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ]);
        foreach ($rows as $row) {
            try {
                $parsed = ResponseParser::parse((string) $row['response_json'], Prompt::analyzeKeys());
            } catch (LlmException $e) {
                return null;
            }
            [, $author] = TicketContext::userName((int) $row['users_id']);
            return self::analysisPayload($parsed, $config, $ticket, (int) $row['id'], [
                'tokens'          => (int) $row['prompt_tokens'] + (int) $row['completion_tokens'],
                'cost_usd'        => '0.0000',
                'duration_ms'     => 0,
                'model'           => (string) $row['model'],
                'images_count'    => (int) $row['images_count'],
                'documents'       => [],
                'documents_count' => (int) $row['documents_count'],
                'kb_hits'         => (int) $row['kb_hits'],
                'omitted'         => 0,
                'cached'          => true,
                'cached_at'       => (string) $row['date_creation'],
                'cached_by'       => $author,
            ], []);
        }
        return null;
    }

    /**
     * The context of this user includes private followups and tasks.
     */
    private static function privateIncluded(array $config): bool
    {
        $policy = (string) ($config['private_items'] ?? TicketContext::PRIVATE_RIGHTS);
        if ($policy === TicketContext::PRIVATE_NONE) {
            return false;
        }
        if ($policy === TicketContext::PRIVATE_ALL) {
            return true;
        }
        return Session::haveRight('followup', ITILFollowup::SEEPRIVATE) || Session::haveRight('task', CommonITILTask::SEEPRIVATE);
    }

    private static function analyzeText(array $context, array $similar, string $templates): string
    {
        $text = Prompt::fence('ticket', $context['text']);

        if ($context['requester_firstname'] !== '') {
            $text .= "\n\n## NAME FOR THE GREETING\nUse \"" . $context['requester_firstname'] . "\" as the requester's first name in the greeting.";
        }

        if (count($similar)) {
            $text .= "\n\n## SIMILAR SOLVED TICKETS (references from the team)\n";
            foreach ($similar as $i => $case) {
                $category = trim((string) ($case['category_name'] ?? ''));
                $distilled = ($case['ai_status'] ?? '') === 'done' && trim((string) $case['ai_summary']) !== '';
                $problem   = $distilled ? (string) $case['ai_summary'] : mb_substr((string) $case['problem_text'], 0, 800);
                $solution  = $distilled && trim((string) $case['ai_resolution']) !== ''
                    ? (string) $case['ai_resolution'] : mb_substr((string) $case['solution_text'], 0, 600);
                $text .= 'Case ' . ($i + 1) . ($category !== '' ? ' [' . $category . ']' : '')
                    . ((float) $case['similarity'] > 0
                        ? ' (similarity ' . number_format((float) $case['similarity'], 2) . ")\n"
                        : " (recent ticket of the same category)\n")
                    . 'Problem: ' . $problem . "\n"
                    . 'Solution applied: ' . $solution . "\n\n";
            }
        } else {
            $text .= "\n\n## SIMILAR SOLVED TICKETS\nNone found: rely on the templates and on good practices.";
        }

        if ($templates !== '') {
            $text .= "\n\n## TEAM SOLUTION TEMPLATES (style references)\n" . $templates;
        }
        return $text;
    }

    /**
     * Similar solved tickets for the analysis (semantic search when enabled).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function similarCases(Ticket $ticket, array $config, int $users_id): array
    {
        $limit = (int) $config['kb_max_results'];
        if ($limit <= 0) {
            return [];
        }
        $query = TicketContext::brief($ticket, $config, false, 1500);
        return CaseLibrary::search($query, [
            'exclude_tickets_id' => (int) $ticket->getID(),
            'entities'           => CaseLibrary::allowedEntities($config),
            'category_id'        => (int) $ticket->fields['itilcategories_id'],
            'limit'              => $limit,
            'reusable_only'      => true,
            'vector'             => Suggester::queryVector($query, $config, (int) $ticket->getID(), $users_id),
            'scan_limit'         => (int) $config['embeddings_scan_limit'],
            'min_similarity'     => (float) ($config['kb_min_similarity'] ?? 0),
        ]);
    }

    /**
     * @return array<int, array{tickets_id: int, similarity: float, category: string}>
     */
    private static function similarSummary(array $similar): array
    {
        $out = [];
        foreach ($similar as $case) {
            $out[] = [
                'tickets_id' => (int) $case['tickets_id'],
                'similarity' => round((float) $case['similarity'], 2),
                'category'   => (string) ($case['category_name'] ?? ''),
                'url'        => Ticket::getFormURLWithID((int) $case['tickets_id']),
            ];
        }
        return $out;
    }

    /**
     * Solution templates visible in the entity of the ticket (style references).
     */
    private static function solutionTemplates(Ticket $ticket, array $config): string
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out      = '';
        $criteria = [
            'SELECT' => ['name', 'content'],
            'FROM'   => SolutionTemplate::getTable(),
            'WHERE'  => (new DbUtils())->getEntitiesRestrictCriteria(SolutionTemplate::getTable(), '', (int) $ticket->fields['entities_id'], true),
            'ORDER'  => 'id ASC',
            'LIMIT'  => 10,
        ];
        foreach ($DB->request($criteria) as $template) {
            $content = Text::toPlainText((string) $template['content'], 700);
            if ($content !== '') {
                $out .= '### ' . Text::toPlainText((string) $template['name'], 120) . "\n" . $content . "\n\n";
            }
        }
        return Privacy::mask($out, Config::maskDetectors($config), (string) ($config['mask_custom'] ?? ''));
    }

    // ------------------------------------------------------------------
    // Improve my text
    // ------------------------------------------------------------------

    private static function improve(Ticket $ticket, array $config, int $users_id, string $draft_html, string $mode): array
    {
        $tickets_id = (int) $ticket->getID();
        $mode       = in_array($mode, Prompt::IMPROVE_MODES, true) ? $mode : 'improve';

        $draft = Text::truncate(Text::scrubUtf8(Text::htmlToText($draft_html)), 12000);
        if (trim($draft) === '') {
            return self::fail('empty_draft', __('Write a draft in the editor first.', 'ticketassistant'));
        }
        $draft = Privacy::mask($draft, Config::maskDetectors($config), (string) ($config['mask_custom'] ?? ''));

        $blocked = self::guardRails('improve', $tickets_id, $config, $users_id, false);
        if ($blocked !== null) {
            return $blocked;
        }

        // Requester: greeting name and, for translations, preferred language
        $first    = '';
        $language = '';
        foreach ($ticket->getUsers(\CommonITILActor::REQUESTER) as $row) {
            [$first] = TicketContext::userName((int) $row['users_id']);
            $user    = new \User();
            if ($user->getFromDB((int) $row['users_id'])) {
                $language = (string) ($user->fields['language'] ?? '');
            }
            break;
        }
        if ($language === '' && (string) $config['response_language'] !== 'auto') {
            $language = (string) $config['response_language'];
        }

        $text = '## TICKET #' . $tickets_id . "\nTitle: " . Text::toPlainText((string) $ticket->fields['name'], 300) . "\n";
        if ($first !== '' && !empty($config['send_requester_name'])) {
            $text .= "Requester's first name (for the greeting): " . $first . "\n";
        }
        $text .= "\n" . Prompt::fence('draft', $draft);

        $client                   = Config::chatClient($config);
        $request                  = new ChatRequest(Prompt::systemImprove($config, $mode, $language));
        $request->maxTokens       = (int) $config['improve_max_tokens'];
        $request->reasoningEffort = (string) $config['improve_effort'];
        $request->temperature     = (float) $config['temperature'];
        $request->json            = true;
        $request->jsonSchema      = Prompt::improveSchema();
        $request->addText($text);

        $response = self::call($client, $request, 'improve', $tickets_id, $users_id, $mode);
        $parsed   = self::parse($response, Prompt::improveKeys(), 'improve', $tickets_id, $users_id, $client, $mode);

        $reply = in_array($mode, ['fix', 'translate'], true)
            ? $parsed['reply_html']
            : self::withSignature($parsed['reply_html'], $config, $ticket);

        $log_id = Log::record(self::logFields($client, $response, [
            'users_id'      => $users_id,
            'tickets_id'    => $tickets_id,
            'action'        => 'improve',
            'mode'          => $mode,
            'response_json' => !empty($config['log_responses']) ? $response->content : null,
        ]));

        return [
            'success'    => true,
            'action'     => 'improve',
            'mode'       => $mode,
            'log_id'     => $log_id,
            'reply_html' => $reply,
            'meta'       => [
                'tokens'      => $response->totalTokens(),
                'cost_usd'    => number_format($response->costUsd, 4),
                'duration_ms' => $response->durationMs,
                'model'       => $response->model,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Classification
    // ------------------------------------------------------------------

    private static function classify(Ticket $ticket, array $config, int $users_id): array
    {
        $tickets_id = (int) $ticket->getID();
        $use_llm    = !empty($config['classify_use_llm']);
        if ($use_llm) {
            $blocked = self::guardRails('classify', $tickets_id, $config, $users_id, false);
            if ($blocked !== null) {
                // The free k-NN suggestion is still available
                $use_llm = false;
            }
        }

        $suggestion = Suggester::suggest($ticket, $config, $use_llm, $users_id);

        Triage::save($tickets_id, ['status' => Triage::STATUS_READY] + Triage::fieldsFromSuggestion($ticket, $suggestion, $users_id));

        return self::classificationPayload($ticket, $suggestion);
    }

    /**
     * Suggestion produced earlier (automatic triage), for the ticket view.
     */
    public static function storedSuggestion(Ticket $ticket): ?array
    {
        $row = Triage::get((int) $ticket->getID());
        if ($row === null || $row['status'] !== Triage::STATUS_READY || (int) $row['category_suggested'] <= 0) {
            return null;
        }
        // Already what the ticket has: nothing to suggest
        if ((int) $row['category_suggested'] === (int) $ticket->fields['itilcategories_id'] && (int) $row['type_suggested'] === (int) $ticket->fields['type']) {
            return null;
        }
        return [
            'category_id'   => (int) $row['category_suggested'],
            'category_name' => TicketContext::categoryName((int) $row['category_suggested']),
            'confidence'    => (float) $row['confidence'],
        ];
    }

    private static function classificationPayload(Ticket $ticket, array $suggestion): array
    {
        $current_category = (int) $ticket->fields['itilcategories_id'];
        return [
            'success'    => true,
            'action'     => 'classify',
            'log_id'     => (int) ($suggestion['usage']['log_id'] ?? 0),
            'suggestion' => [
                'type'          => $suggestion['type'],
                'type_label'    => Text::toPlainText((string) Ticket::getTicketTypeName($suggestion['type']), 60),
                'category_id'   => $suggestion['category_id'],
                'category_name' => $suggestion['category_name'],
                'confidence'    => $suggestion['confidence'],
                'justification' => $suggestion['justification'],
                'urgency'       => $suggestion['urgency'],
                'urgency_label' => $suggestion['urgency'] !== null ? Text::toPlainText((string) Ticket::getUrgencyName($suggestion['urgency']), 60) : '',
                'method'        => $suggestion['method'],
            ],
            'current'    => [
                'type'          => (int) $ticket->fields['type'],
                'type_label'    => Text::toPlainText((string) Ticket::getTicketTypeName((int) $ticket->fields['type']), 60),
                'category_id'   => $current_category,
                'category_name' => TicketContext::categoryName($current_category),
                'urgency'       => (int) $ticket->fields['urgency'],
                'urgency_label' => Text::toPlainText((string) Ticket::getUrgencyName((int) $ticket->fields['urgency']), 60),
            ],
            'candidates' => $suggestion['candidates'],
            'neighbors'  => $suggestion['neighbors'],
            'similar'    => array_map(static function ($row) {
                return $row + ['url' => Ticket::getFormURLWithID($row['tickets_id'])];
            }, $suggestion['similar']),
            'can_apply'  => $ticket->canUpdateItem(),
            'meta'       => $suggestion['usage'] !== null ? [
                'tokens'      => $suggestion['usage']['tokens'],
                'cost_usd'    => number_format((float) $suggestion['usage']['cost_usd'], 4),
                'duration_ms' => $suggestion['usage']['duration_ms'],
                'model'       => $suggestion['usage']['model'],
            ] : ['tokens' => 0, 'cost_usd' => '0.0000', 'duration_ms' => 0, 'model' => ''],
        ];
    }

    private static function applyClassification(Ticket $ticket, array $params, int $users_id): array
    {
        if (!$ticket->canUpdateItem()) {
            return self::fail('forbidden', __('You cannot update this ticket.', 'ticketassistant'));
        }
        $tickets_id = (int) $ticket->getID();
        $input      = ['id' => $tickets_id];

        $type = (int) ($params['type'] ?? 0);
        if (in_array($type, [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], true)) {
            $input['type'] = $type;
        }
        $category_id = (int) ($params['category_id'] ?? 0);
        if ($category_id > 0) {
            $allowed = Classifier::allowedCategories((int) $ticket->fields['entities_id']);
            if (!isset($allowed[$category_id])) {
                return self::fail('badrequest', __('This category is not available for the entity of the ticket.', 'ticketassistant'));
            }
            if (!Classifier::categoryFitsType($allowed[$category_id], (int) ($input['type'] ?? $ticket->fields['type']))) {
                return self::fail('badrequest', __('This category cannot be used with this ticket type.', 'ticketassistant'));
            }
            $input['itilcategories_id'] = $category_id;
        }
        $urgency = (int) ($params['urgency'] ?? 0);
        if ($urgency >= 1 && $urgency <= 5) {
            $input['urgency'] = $urgency;
        }
        if (count($input) === 1) {
            return self::fail('badrequest', __('Nothing to apply.', 'ticketassistant'));
        }

        $ok = (bool) $ticket->update(Compat::itemInput($input));
        Triage::save($tickets_id, ['status' => $ok ? Triage::STATUS_APPLIED : Triage::STATUS_READY, 'users_id' => $users_id]);
        Log::record([
            'users_id'   => $users_id,
            'tickets_id' => $tickets_id,
            'action'     => 'apply',
            'status'     => $ok ? 'ok' : 'error_update',
        ]);

        return $ok
            ? ['success' => true, 'message' => __('Classification applied to the ticket.', 'ticketassistant'), 'reload' => true]
            : self::fail('internal', __('GLPI refused the update of the ticket.', 'ticketassistant'));
    }

    // ------------------------------------------------------------------
    // Knowledge base article
    // ------------------------------------------------------------------

    private static function article(Ticket $ticket, array $config, int $users_id): array
    {
        $tickets_id = (int) $ticket->getID();
        $solved     = in_array((int) $ticket->fields['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true)
            || count((new ITILSolution())->find(['itemtype' => Ticket::class, 'items_id' => $tickets_id, 'NOT' => ['status' => CommonITILValidation::REFUSED]], [], 1)) > 0;
        if (!$solved) {
            return self::fail('not_solved', __('A knowledge base article can be written once the ticket has a solution.', 'ticketassistant'));
        }

        $blocked = self::guardRails('article', $tickets_id, $config, $users_id, true);
        if ($blocked !== null) {
            return $blocked;
        }

        $client  = Config::chatClient($config);
        $context = TicketContext::build($ticket, $config, ['vision' => false], ['attachments' => false]);

        $request                  = new ChatRequest(Prompt::systemArticle($config));
        $request->maxTokens       = (int) $config['article_max_tokens'];
        $request->reasoningEffort = (string) $config['article_effort'];
        $request->temperature     = (float) $config['temperature'];
        $request->json            = true;
        $request->jsonSchema      = Prompt::articleSchema();
        $request->addText(Prompt::fence('ticket', $context['text']));

        $response = self::call($client, $request, 'article', $tickets_id, $users_id);
        $parsed   = self::parse($response, Prompt::articleKeys(), 'article', $tickets_id, $users_id, $client);

        $log_id = Log::record(self::logFields($client, $response, [
            'users_id'      => $users_id,
            'tickets_id'    => $tickets_id,
            'action'        => 'article',
            'response_json' => !empty($config['log_responses']) ? $response->content : null,
        ]));

        return [
            'success'      => true,
            'action'       => 'article',
            'log_id'       => $log_id,
            'title'        => Text::truncate((string) $parsed['title'], 250),
            'content_html' => $parsed['content_html'],
            'can_create'   => KnowbaseItem::canCreate(),
            'meta'         => [
                'tokens'      => $response->totalTokens(),
                'cost_usd'    => number_format($response->costUsd, 4),
                'duration_ms' => $response->durationMs,
                'model'       => $response->model,
            ],
        ];
    }

    /**
     * Creates the article as an unpublished draft (no audience: only the
     * author and the knowledge base managers see it until it is published),
     * linked to the ticket.
     */
    private static function createArticle(Ticket $ticket, array $params, int $users_id): array
    {
        if (!KnowbaseItem::canCreate()) {
            return self::fail('forbidden', __('You cannot create knowledge base articles.', 'ticketassistant'));
        }
        $title   = Text::truncate(trim(strip_tags((string) ($params['title'] ?? ''))), 250);
        $content = ResponseParser::sanitizeHtml((string) ($params['content_html'] ?? ''));
        if ($title === '' || trim(strip_tags($content)) === '') {
            return self::fail('badrequest', __('The article needs a title and a content.', 'ticketassistant'));
        }

        $article = new KnowbaseItem();
        $id      = (int) $article->add(Compat::itemInput([
            'name'     => $title,
            'answer'   => $content,
            'is_faq'   => 0,
            'users_id' => $users_id,
        ]));
        if ($id <= 0) {
            return self::fail('internal', __('GLPI refused the creation of the article.', 'ticketassistant'));
        }
        (new KnowbaseItem_Item())->add(Compat::itemInput([
            'knowbaseitems_id' => $id,
            'itemtype'         => Ticket::class,
            'items_id'         => (int) $ticket->getID(),
        ]));
        Log::record([
            'users_id'   => $users_id,
            'tickets_id' => (int) $ticket->getID(),
            'action'     => 'kb_create',
            'status'     => 'ok',
        ]);

        return [
            'success' => true,
            'message' => __('Draft article created: review it and choose its audience to publish it.', 'ticketassistant'),
            'url'     => KnowbaseItem::getFormURLWithID($id),
        ];
    }

    // ------------------------------------------------------------------
    // Shared steps
    // ------------------------------------------------------------------

    /**
     * Guard rails, checked before any paid call, in this order: cooldown of
     * the ticket, hourly limit of the user, daily budget.
     *
     * @return array|null failure payload, null when allowed
     */
    private static function guardRails(string $action, int $tickets_id, array $config, int $users_id, bool $cooldown): ?array
    {
        $seconds = (int) $config['ticket_cooldown_seconds'];
        if ($cooldown && $seconds > 0) {
            $elapsed = Log::secondsSinceLastOk($tickets_id, $action);
            if ($elapsed !== null && $elapsed < $seconds) {
                $wait = $seconds - $elapsed;
                Log::record(['users_id' => $users_id, 'tickets_id' => $tickets_id, 'action' => $action, 'status' => 'blocked_cooldown']);
                return self::fail(
                    'blocked_cooldown',
                    sprintf(__('This ticket was processed a moment ago. Wait %d s to generate again.', 'ticketassistant'), $wait),
                    $wait,
                );
            }
        }

        $hourly = (int) $config['user_hourly_limit'];
        if ($hourly > 0 && Log::userCallsLastHour($users_id) >= $hourly) {
            Log::record(['users_id' => $users_id, 'tickets_id' => $tickets_id, 'action' => $action, 'status' => 'blocked_rate']);
            return self::fail(
                'blocked_rate',
                sprintf(__('You reached the limit of %d uses of the assistant per hour. Try again later.', 'ticketassistant'), $hourly),
            );
        }

        $budget = (float) $config['daily_budget_usd'];
        if ($budget > 0 && Log::todayCostUSD(Log::INTERACTIVE_ACTIONS) >= $budget) {
            Log::record(['users_id' => $users_id, 'tickets_id' => $tickets_id, 'action' => $action, 'status' => 'blocked_budget']);
            return self::fail(
                'blocked_budget',
                __('The daily budget of the AI assistant is exhausted. Try again tomorrow or contact the administrator.', 'ticketassistant'),
            );
        }
        return null;
    }

    /**
     * @throws LlmException after logging the failure
     */
    private static function call(Client $client, ChatRequest $request, string $action, int $tickets_id, int $users_id, string $mode = ''): ChatResponse
    {
        try {
            return $client->chat($request);
        } catch (LlmException $e) {
            $settings = $client->getSettings();
            Log::record([
                'users_id'      => $users_id,
                'tickets_id'    => $tickets_id,
                'action'        => $action,
                'mode'          => $mode,
                'status'        => $e->getLogStatus(),
                'provider'      => $settings['provider'],
                'model'         => $settings['model'],
                'http_code'     => $e->getHttpCode(),
                'images_count'  => $request->countImages(),
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @throws LlmException after logging the invalid answer (the call was paid)
     */
    private static function parse(ChatResponse $response, array $keys, string $action, int $tickets_id, int $users_id, Client $client, string $mode = ''): array
    {
        try {
            return ResponseParser::parse($response->content, $keys);
        } catch (LlmException $e) {
            Log::record(self::logFields($client, $response, [
                'users_id'      => $users_id,
                'tickets_id'    => $tickets_id,
                'action'        => $action,
                'mode'          => $mode,
                'status'        => $e->getLogStatus(),
                'error_message' => $e->getMessage(),
                'response_json' => mb_substr($response->content, 0, 20000),
            ]));
            throw $e;
        }
    }

    private static function logFields(Client $client, ChatResponse $response, array $fields): array
    {
        $settings = $client->getSettings();
        return $fields + [
            'status'            => 'ok',
            'provider'          => $settings['provider'],
            'model'             => $response->model !== '' ? $response->model : $settings['model'],
            'http_code'         => $response->httpCode,
            'prompt_tokens'     => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'reasoning_tokens'  => $response->reasoningTokens,
            'cost_usd'          => $response->costUsd,
            'duration_ms'       => $response->durationMs,
        ];
    }

    /**
     * Removes the closing formula the model may write and appends the
     * configured signature of the logged-in technician.
     */
    public static function withSignature(string $html, array $config, Ticket $ticket): string
    {
        $template = trim((string) ($config['signature_template'] ?? ''));
        if ($template === '') {
            return $html;
        }
        $html = ResponseParser::stripClosing($html);

        $name = TicketContext::currentUserFullname();
        if ($name === '') {
            $name = __('Service desk team', 'ticketassistant');
        }
        $entity = '';
        if (isset($ticket->fields['entities_id'])) {
            $entity = Text::toPlainText((string) \Dropdown::getDropdownName('glpi_entities', (int) $ticket->fields['entities_id']), 200);
        }
        $replacements = [
            '{name}'         => Text::e($name),
            '{firstname}'    => Text::e(TicketContext::currentUserFirstname()),
            '{entity}'       => Text::e($entity),
            '{organization}' => Text::e((string) ($config['organization_name'] ?? '')),
        ];
        $signature = ResponseParser::sanitizeHtml(strtr($template, $replacements));
        return $html . '<p>' . $signature . '</p>';
    }

    /**
     * @return array{success: false, code: string, message: string, retry_after?: int}
     */
    public static function fail(string $code, string $message, ?int $retry_after = null): array
    {
        $out = ['success' => false, 'code' => $code, 'message' => $message];
        if ($retry_after !== null) {
            $out['retry_after'] = $retry_after;
        }
        return $out;
    }
}
