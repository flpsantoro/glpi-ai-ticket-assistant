<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Knowledge;

use GlpiPlugin\Ticketassistant\Config;
use GlpiPlugin\Ticketassistant\Llm\ChatRequest;
use GlpiPlugin\Ticketassistant\Llm\LlmException;
use GlpiPlugin\Ticketassistant\Log;
use GlpiPlugin\Ticketassistant\Prompt;
use GlpiPlugin\Ticketassistant\ResponseParser;
use GlpiPlugin\Ticketassistant\Text;
use GlpiPlugin\Ticketassistant\TicketContext;
use Ticket;

/**
 * Type / category / urgency suggestion for one ticket: k nearest solved
 * tickets first (free and instant), then, optionally, the language model
 * choosing among the candidates with the similar tickets as examples
 * (dynamic few-shot).
 */
final class Suggester
{
    /** Below this number of categories every allowed one is offered to the model */
    private const FULL_LIST_LIMIT = 60;

    /**
     * @param int $users_id 0 for automatic actions
     *
     * @return array{
     *   type: int, category_id: int, category_name: string, confidence: float,
     *   justification: string, urgency: int|null, method: string,
     *   candidates: array<int, array{id: int, name: string, score: float}>,
     *   neighbors: int, similar: array<int, array{tickets_id: int, similarity: float}>,
     *   usage: array<string, mixed>|null
     * }
     *
     * @throws LlmException when the language model fails (the k-NN result is
     *                      returned instead when $use_llm is false)
     */
    public static function suggest(Ticket $ticket, array $config, bool $use_llm, int $users_id): array
    {
        $tickets_id  = (int) $ticket->getID();
        $entities_id = (int) $ticket->fields['entities_id'];
        $text        = TicketContext::brief($ticket, $config, false, 3000);

        // ---- Nearest solved tickets -----------------------------------------
        $vector = self::queryVector($text, $config, $tickets_id, $users_id);
        $knn    = Classifier::knn($text, [
            'exclude_tickets_id' => $tickets_id,
            'entities'           => $users_id > 0 ? CaseLibrary::allowedEntities($config) : null,
            'neighbors'          => (int) $config['classify_neighbors'],
            'vector'             => $vector,
            'scan_limit'         => (int) $config['embeddings_scan_limit'],
        ]);

        $allowed    = Classifier::allowedCategories($entities_id);
        $candidates = [];
        foreach ($knn['categories'] as $category) {
            if (isset($allowed[$category['id']])) {
                $candidates[$category['id']] = [
                    'id'    => $category['id'],
                    'name'  => $allowed[$category['id']]['name'],
                    'score' => $category['score'],
                    'votes' => $category['votes'],
                ];
            }
        }
        $votes = array_sum(array_map(static function ($c) {
            return $c['votes'];
        }, $candidates));

        // ---- k-NN suggestion ------------------------------------------------
        $current_type = (int) $ticket->fields['type'];
        $type         = count($knn['neighbors'])
            ? ($knn['types'][Ticket::DEMAND_TYPE] > $knn['types'][Ticket::INCIDENT_TYPE] ? Ticket::DEMAND_TYPE : Ticket::INCIDENT_TYPE)
            : $current_type;
        $category_id  = 0;
        $confidence   = 0.0;
        foreach ($candidates as $candidate) {
            if (Classifier::categoryFitsType($allowed[$candidate['id']], $type)) {
                $category_id = $candidate['id'];
                $confidence  = Classifier::confidence($candidate['score'], $votes, $knn['best']);
                break;
            }
        }

        $result = [
            'type'          => $type,
            'category_id'   => $category_id,
            'category_name' => $category_id > 0 ? $allowed[$category_id]['name'] : '',
            'confidence'    => round($confidence, 4),
            'justification' => count($knn['neighbors'])
                ? sprintf(__('Based on %d similar solved tickets.', 'ticketassistant'), count($knn['neighbors']))
                : __('No similar solved ticket found.', 'ticketassistant'),
            'urgency'       => null,
            'method'        => 'knn',
            'candidates'    => array_values(array_map(static function ($c) {
                return ['id' => $c['id'], 'name' => $c['name'], 'score' => $c['score']];
            }, array_slice($candidates, 0, 5, true))),
            'neighbors'     => count($knn['neighbors']),
            'similar'       => array_slice(array_map(static function ($n) {
                return ['tickets_id' => $n['tickets_id'], 'similarity' => $n['similarity']];
            }, $knn['neighbors']), 0, 5),
            'usage'         => null,
        ];

        if (!$use_llm || !count($allowed)) {
            return $result;
        }

        // ---- Language model among the candidates ----------------------------
        $offered = [];
        foreach ($candidates as $id => $candidate) {
            if (count($offered) < 8) {
                $offered[$id] = $allowed[$id];
            }
        }
        $current_category = (int) $ticket->fields['itilcategories_id'];
        if ($current_category > 0 && isset($allowed[$current_category])) {
            $offered[$current_category] = $allowed[$current_category];
        }
        if (count($allowed) <= self::FULL_LIST_LIMIT) {
            $offered = $allowed;
        }

        $lines = [];
        foreach ($offered as $category) {
            $kinds = [];
            if ($category['incident']) {
                $kinds[] = 'incident';
            }
            if ($category['request']) {
                $kinds[] = 'request';
            }
            $lines[] = '- [id ' . $category['id'] . '] ' . $category['name']
                . ' (' . (count($kinds) ? implode(', ', $kinds) : 'no type') . ')'
                . ($category['comment'] !== '' ? ' — ' . $category['comment'] : '');
        }

        $examples = self::examples($knn['neighbors']);
        $user     = Prompt::fence('ticket', $text)
            . "\n\n## CURRENT CLASSIFICATION\nType: " . Text::toPlainText((string) Ticket::getTicketTypeName($current_type), 50)
            . "\nCategory: " . ($current_category > 0 ? TicketContext::categoryName($current_category) : '(none)')
            . "\n\n## CANDIDATE CATEGORIES\n" . implode("\n", $lines)
            . ($examples !== '' ? "\n\n## SIMILAR SOLVED TICKETS AND HOW THE TEAM CLASSIFIED THEM\n" . $examples : '');

        $client                   = Config::chatClient($config);
        $request                  = new ChatRequest(Prompt::systemClassify($config));
        $request->maxTokens       = (int) $config['classify_max_tokens'];
        $request->reasoningEffort = (string) $config['classify_effort'];
        $request->temperature     = 0.0;
        $request->json            = true;
        $request->jsonSchema      = Prompt::classifySchema();
        $request->addText($user);

        $settings = $client->getSettings();
        try {
            $response = $client->chat($request);
            $parsed   = ResponseParser::parse($response->content, Prompt::classifyKeys());
        } catch (LlmException $e) {
            Log::record([
                'users_id'      => $users_id,
                'tickets_id'    => $tickets_id,
                'action'        => $users_id > 0 ? 'classify' : 'triage',
                'status'        => $e->getLogStatus(),
                'provider'      => $settings['provider'],
                'model'         => $settings['model'],
                'http_code'     => $e->getHttpCode(),
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $llm_type     = in_array((int) $parsed['type'], [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], true) ? (int) $parsed['type'] : $type;
        $llm_category = (int) ($parsed['category_id'] ?? 0);
        if ($llm_category > 0 && isset($offered[$llm_category])) {
            if (!Classifier::categoryFitsType($offered[$llm_category], $llm_type)) {
                // Keep the category, fix the type when the category allows the other one
                $other = $llm_type === Ticket::INCIDENT_TYPE ? Ticket::DEMAND_TYPE : Ticket::INCIDENT_TYPE;
                if (Classifier::categoryFitsType($offered[$llm_category], $other)) {
                    $llm_type = $other;
                }
            }
            $knn_conf             = isset($candidates[$llm_category])
                ? Classifier::confidence($candidates[$llm_category]['score'], $votes, $knn['best']) : 0.0;
            $llm_conf             = max(0.0, min(1.0, (float) ($parsed['confidence'] ?? 0.5)));
            $result['category_id']   = $llm_category;
            $result['category_name'] = $offered[$llm_category]['name'];
            $result['confidence']    = round(0.6 * $llm_conf + 0.4 * $knn_conf, 4);
        }
        $result['type']          = $llm_type;
        $result['justification'] = (string) $parsed['justification'] !== '' ? (string) $parsed['justification'] : $result['justification'];
        $result['urgency']       = isset($parsed['urgency']) && (int) $parsed['urgency'] >= 1 && (int) $parsed['urgency'] <= 5
            ? (int) $parsed['urgency'] : null;
        $result['method']        = 'llm';
        $result['usage']         = [
            'log_id'            => Log::record([
                'users_id'          => $users_id,
                'tickets_id'        => $tickets_id,
                'action'            => $users_id > 0 ? 'classify' : 'triage',
                'status'            => 'ok',
                'provider'          => $settings['provider'],
                'model'             => $response->model,
                'http_code'         => $response->httpCode,
                'prompt_tokens'     => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'reasoning_tokens'  => $response->reasoningTokens,
                'cost_usd'          => $response->costUsd,
                'duration_ms'       => $response->durationMs,
                'kb_hits'           => count($knn['neighbors']),
                'response_json'     => !empty($config['log_responses']) ? $response->content : null,
            ]),
            'tokens'            => $response->totalTokens(),
            'cost_usd'          => $response->costUsd,
            'duration_ms'       => $response->durationMs,
            'model'             => $response->model,
        ];

        return $result;
    }

    /**
     * Few-shot examples: the closest solved tickets with their labels.
     */
    private static function examples(array $neighbors): string
    {
        $ids = [];
        foreach (array_slice($neighbors, 0, 6) as $neighbor) {
            $ids[] = (int) $neighbor['tickets_id'];
        }
        if (!count($ids)) {
            return '';
        }

        /** @var \DBmysql $DB */
        global $DB;

        $out = '';
        $it  = $DB->request([
            'SELECT' => ['tickets_id', 'problem_text', 'ai_summary', 'category_name', 'type', 'itilcategories_id'],
            'FROM'   => CaseLibrary::TABLE,
            'WHERE'  => ['tickets_id' => $ids],
        ]);
        foreach ($it as $row) {
            $summary = trim((string) $row['ai_summary']) !== ''
                ? (string) $row['ai_summary']
                : Text::truncate(preg_replace('/\s+/', ' ', (string) $row['problem_text']) ?? '', 220);
            $out .= '- "' . str_replace('"', "'", $summary) . '" → [id ' . (int) $row['itilcategories_id'] . '] '
                . (string) $row['category_name'] . ' (' . ((int) $row['type'] === Ticket::DEMAND_TYPE ? 'request' : 'incident') . ")\n";
        }
        return $out;
    }

    /**
     * Embedding of the ticket when semantic search is enabled (best effort).
     *
     * @return float[]|null
     */
    public static function queryVector(string $text, array $config, int $tickets_id, int $users_id): ?array
    {
        $client = Config::embeddingClient($config);
        if ($client === null) {
            return null;
        }
        try {
            $result = $client->embed([mb_substr($text, 0, 6000)], (int) $config['embedding_dimensions']);
            Log::record([
                'users_id'      => $users_id,
                'tickets_id'    => $tickets_id,
                'action'        => 'embed',
                'status'        => 'ok',
                'provider'      => $client->getSettings()['provider'],
                'model'         => (string) $config['embedding_model'],
                'prompt_tokens' => (int) $result['tokens'],
                'cost_usd'      => (float) $result['cost_usd'],
            ]);
            return $result['vectors'][0] ?? null;
        } catch (\Throwable $e) {
            // Semantic search is an improvement, never a requirement
            return null;
        }
    }
}
