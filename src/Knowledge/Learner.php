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
use GlpiPlugin\Ticketassistant\Llm\ChatRequest;
use GlpiPlugin\Ticketassistant\Llm\Client;
use GlpiPlugin\Ticketassistant\Llm\LlmException;
use GlpiPlugin\Ticketassistant\Log;
use GlpiPlugin\Ticketassistant\Privacy;
use GlpiPlugin\Ticketassistant\Prompt;
use GlpiPlugin\Ticketassistant\ResponseParser;
use GlpiPlugin\Ticketassistant\Text;
use GlpiPlugin\Ticketassistant\TicketContext;
use Ticket;

/**
 * Learning from solved tickets, run by the automatic action "ailearn":
 *
 * 1. distillation (optional, uses the language model): each solved ticket
 *    becomes an anonymized record — generic summary, reusable resolution
 *    steps, keywords, the type/category the model would give it and whether
 *    it is a good reference. Disagreements with the current category point
 *    to tickets that were probably misclassified;
 * 2. embeddings (optional): vectors for semantic search and classification.
 *
 * Both steps stop at the daily background budget.
 */
final class Learner extends \CommonGLPI
{
    private const MAX_ATTEMPTS = 3;

    public static function getTypeName($nb = 0)
    {
        return __('AI Ticket Assistant', 'ticketassistant') . ' — ' . __('Learning from solved tickets', 'ticketassistant');
    }

    public static function cronInfo($name)
    {
        if ($name === 'ailearn') {
            return [
                'description' => __('AI Ticket Assistant: learns from solved tickets (distillation and embeddings)', 'ticketassistant'),
                'parameter'   => __('Tickets per run (0 = configuration value)', 'ticketassistant'),
            ];
        }
        return [];
    }

    /**
     * @param CronTask $task
     */
    public static function cronAilearn($task)
    {
        $config = Config::getConfig();
        if (empty($config['enabled'])) {
            return 0;
        }
        $limit  = (int) ($task->fields['param'] ?? 0);
        $result = self::run($config, $limit > 0 ? $limit : (int) $config['learn_batch']);
        $task->addVolume($result['distilled'] + $result['embedded']);
        $task->log(sprintf(
            __('%1$d tickets distilled, %2$d embedded, %3$d errors, US$ %4$s', 'ticketassistant'),
            $result['distilled'],
            $result['embedded'],
            $result['errors'],
            number_format($result['cost_usd'], 4),
        ));
        return ($result['distilled'] + $result['embedded']) > 0 ? 1 : 0;
    }

    /**
     * @return array{distilled: int, embedded: int, errors: int, cost_usd: float, stopped: string}
     */
    public static function run(array $config, int $limit): array
    {
        @set_time_limit(0);
        $result = ['distilled' => 0, 'embedded' => 0, 'errors' => 0, 'cost_usd' => 0.0, 'stopped' => ''];
        $budget = (float) $config['background_budget_usd'];

        if (!empty($config['learn_enabled']) && Config::isReady($config)) {
            $client = Config::chatClient($config);
            foreach (self::pendingCases(max(1, $limit)) as $case) {
                if ($budget > 0 && Log::todayCostUSD(Log::BACKGROUND_ACTIONS) >= $budget) {
                    $result['stopped'] = 'budget';
                    break;
                }
                $outcome = self::distill($case, $client, $config);
                if ($outcome === null) {
                    $result['errors']++;
                } else {
                    $result['distilled']++;
                    $result['cost_usd'] += $outcome;
                }
            }
        }

        $embedder = Config::embeddingClient($config);
        if ($embedder !== null && $result['stopped'] === '') {
            [$embedded, $cost] = self::embedPending($embedder, $config, max(50, $limit * 5));
            $result['embedded'] = $embedded;
            $result['cost_usd'] += $cost;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function pendingCases(int $limit): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => CaseLibrary::TABLE,
                'WHERE' => ['ai_status' => 'pending'],
                'ORDER' => 'date_solved DESC',
                'LIMIT' => $limit,
            ]) as $row
        ) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Distills one solved ticket.
     *
     * @return float|null cost in USD, null on failure
     */
    public static function distill(array $case, Client $client, array $config): ?float
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) $case['tickets_id'])) {
            $DB->update(CaseLibrary::TABLE, ['ai_status' => 'skipped'], ['id' => (int) $case['id']]);
            return null;
        }

        $context = TicketContext::build(
            $ticket,
            array_merge($config, ['max_context_chars' => 12000]),
            ['vision' => false],
            ['private' => TicketContext::PRIVATE_ALL, 'attachments' => false],
        );

        // Candidates: current category + categories of the nearest cases
        $allowed    = Classifier::allowedCategories((int) $ticket->fields['entities_id']);
        $knn        = Classifier::knn((string) $case['problem_text'], ['exclude_tickets_id' => (int) $case['tickets_id'], 'neighbors' => 15]);
        $candidates = [];
        $current    = (int) $case['itilcategories_id'];
        if ($current > 0 && isset($allowed[$current])) {
            $candidates[$current] = $allowed[$current];
        }
        foreach ($knn['categories'] as $category) {
            if (count($candidates) < 8 && isset($allowed[$category['id']])) {
                $candidates[$category['id']] = $allowed[$category['id']];
            }
        }
        $lines = [];
        foreach ($candidates as $category) {
            $lines[] = '- [id ' . $category['id'] . '] ' . $category['name'];
        }

        $request                  = new ChatRequest(Prompt::systemDistill($config));
        $request->maxTokens       = (int) $config['learn_max_tokens'];
        $request->reasoningEffort = (string) $config['learn_effort'];
        $request->temperature     = 0.0;
        $request->json            = true;
        $request->jsonSchema      = Prompt::distillSchema();
        $request->addText(
            Prompt::fence('ticket', $context['text'])
            . "\n\n## CURRENT CLASSIFICATION\nType: " . ((int) $ticket->fields['type'] === Ticket::DEMAND_TYPE ? 'request' : 'incident')
            . "\nCategory: " . ($current > 0 ? '[id ' . $current . '] ' . (string) $case['category_name'] : '(none)')
            . (count($lines) ? "\n\n## CANDIDATE CATEGORIES\n" . implode("\n", $lines) : ''),
        );

        $settings = $client->getSettings();
        try {
            $response = $client->chat($request);
            $parsed   = ResponseParser::parse($response->content, Prompt::distillKeys());
        } catch (LlmException $e) {
            Log::record([
                'users_id'      => 0,
                'tickets_id'    => (int) $case['tickets_id'],
                'action'        => 'distill',
                'status'        => $e->getLogStatus(),
                'provider'      => $settings['provider'],
                'model'         => $settings['model'],
                'http_code'     => $e->getHttpCode(),
                'error_message' => $e->getMessage(),
            ]);
            $attempts = (int) ($case['ai_attempts'] ?? 0) + 1;
            $DB->update(CaseLibrary::TABLE, [
                'ai_attempts' => $attempts,
                'ai_status'   => $e->isRetryable() && $attempts < self::MAX_ATTEMPTS ? 'pending' : 'error',
            ], ['id' => (int) $case['id']]);
            return null;
        }

        // Defense in depth: the record is masked again whatever the model wrote
        $detectors = array_unique(array_merge(Config::maskDetectors($config), ['secrets']));
        $custom    = (string) ($config['mask_custom'] ?? '');
        $mask      = static function (string $text) use ($detectors, $custom): string {
            return Privacy::mask($text, $detectors, $custom);
        };

        $category = (int) ($parsed['category_id'] ?? 0);
        if (!isset($candidates[$category])) {
            $category = 0;
        }
        $keywords = array_slice(array_map(static function ($word) {
            return Text::truncate((string) $word, 40);
        }, (array) $parsed['keywords']), 0, 8);

        $DB->update(CaseLibrary::TABLE, [
            'ai_summary'     => Compat::dbValue($mask(Text::truncate((string) $parsed['summary'], 800))),
            'ai_resolution'  => Compat::dbValue($mask(Text::truncate((string) $parsed['resolution'], 2000))),
            'ai_keywords'    => Compat::dbValue($mask(mb_substr(implode(', ', $keywords), 0, 500))),
            'ai_type'        => in_array((int) $parsed['type'], [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], true) ? (int) $parsed['type'] : 0,
            'ai_category_id' => $category,
            'ai_reusable'    => !empty($parsed['reusable']) ? 1 : 0,
            'ai_status'      => 'done',
            'ai_date'        => date('Y-m-d H:i:s'),
            // the vector must now represent the distilled summary
            'embedding'      => null,
        ], ['id' => (int) $case['id']]);

        Log::record([
            'users_id'          => 0,
            'tickets_id'        => (int) $case['tickets_id'],
            'action'            => 'distill',
            'status'            => 'ok',
            'provider'          => $settings['provider'],
            'model'             => $response->model,
            'http_code'         => $response->httpCode,
            'prompt_tokens'     => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'reasoning_tokens'  => $response->reasoningTokens,
            'cost_usd'          => $response->costUsd,
            'duration_ms'       => $response->durationMs,
        ]);

        return $response->costUsd;
    }

    /**
     * Computes the missing embeddings, most recent cases first.
     *
     * @return array{0: int, 1: float} [embedded, cost]
     */
    public static function embedPending(Client $client, array $config, int $limit): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'tickets_id', 'problem_text', 'ai_summary', 'ai_keywords'],
                'FROM'   => CaseLibrary::TABLE,
                'WHERE'  => ['embedding' => null],
                'ORDER'  => 'date_solved DESC',
                'LIMIT'  => $limit,
            ]) as $row
        ) {
            $rows[] = $row;
        }

        $embedded   = 0;
        $cost       = 0.0;
        $dimensions = (int) $config['embedding_dimensions'];
        $model      = mb_substr((string) $config['embedding_model'], 0, 100);
        $budget     = (float) $config['background_budget_usd'];

        foreach (array_chunk($rows, 32) as $chunk) {
            if ($budget > 0 && Log::todayCostUSD(Log::BACKGROUND_ACTIONS) >= $budget) {
                break;
            }
            $texts = array_map(static function ($row) {
                return mb_substr(CaseLibrary::embeddingText($row), 0, 6000);
            }, $chunk);
            try {
                $result = $client->embed($texts, $dimensions);
            } catch (LlmException $e) {
                Log::record([
                    'users_id'      => 0,
                    'action'        => 'embed',
                    'status'        => $e->getLogStatus(),
                    'provider'      => $client->getSettings()['provider'],
                    'model'         => $model,
                    'http_code'     => $e->getHttpCode(),
                    'error_message' => $e->getMessage(),
                ]);
                break;
            }
            foreach ($chunk as $i => $row) {
                if (!isset($result['vectors'][$i]) || !count($result['vectors'][$i])) {
                    continue;
                }
                $DB->update(CaseLibrary::TABLE, [
                    'embedding'       => Embeddings::pack($result['vectors'][$i]),
                    'embedding_model' => Compat::dbValue($model),
                ], ['id' => (int) $row['id']]);
                $embedded++;
            }
            $cost += (float) $result['cost_usd'];
            Log::record([
                'users_id'      => 0,
                'action'        => 'embed',
                'status'        => 'ok',
                'provider'      => $client->getSettings()['provider'],
                'model'         => $model,
                'prompt_tokens' => (int) $result['tokens'],
                'cost_usd'      => (float) $result['cost_usd'],
                'kb_hits'       => count($chunk),
            ]);
        }

        return [$embedded, $cost];
    }

    /**
     * Cases whose distilled category differs from the one of the ticket:
     * candidates for a review of the classification.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function disagreements(int $limit = 50): array
    {
        return Compat::rawSelect(
            'SELECT c.`tickets_id`, c.`category_name`, c.`ai_category_id`, k.`completename` AS ai_category_name,
                    c.`ai_summary`, c.`date_solved`
             FROM `' . CaseLibrary::TABLE . '` c
             LEFT JOIN `glpi_itilcategories` k ON k.`id` = c.`ai_category_id`
             WHERE c.`ai_status` = ' . Compat::quote('done') . '
               AND c.`ai_category_id` > 0
               AND c.`ai_category_id` <> c.`itilcategories_id`
             ORDER BY c.`date_solved` DESC
             LIMIT ' . max(1, $limit),
        );
    }
}
