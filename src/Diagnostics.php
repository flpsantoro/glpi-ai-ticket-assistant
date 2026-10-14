<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use CronTask;
use GlpiPlugin\Ticketassistant\Knowledge\CaseLibrary;
use GlpiPlugin\Ticketassistant\Knowledge\Learner;
use GlpiPlugin\Ticketassistant\Knowledge\Triage;
use GlpiPlugin\Ticketassistant\Llm\ChatRequest;
use GlpiPlugin\Ticketassistant\Llm\LlmException;
use GlpiPlugin\Ticketassistant\Llm\Providers;

/**
 * Connection test, model listing and state of the automatic actions, shared
 * by the configuration page and the console commands (installers).
 */
final class Diagnostics
{
    /**
     * Sends a minimal request with the SAVED configuration, plus an embedding
     * when semantic search is enabled. Every attempt is logged.
     *
     * @return array{success: bool, message: string}
     */
    public static function testConnection(array $config): array
    {
        $settings = Config::connectionSettings($config);
        if (count(Providers::missingSettings($settings))) {
            return ['success' => false, 'message' => __('Fill in and SAVE the address, the model and the key before testing.', 'ticketassistant')];
        }

        $client                   = Config::chatClient($config);
        $request                  = new ChatRequest();
        $request->maxTokens       = 1024;
        $request->reasoningEffort = (string) $config['improve_effort'];
        $request->temperature     = 0.0;
        $request->addText('Reply with the single word: pong');

        try {
            $response = $client->chat($request);
        } catch (LlmException $e) {
            Log::record([
                'action'        => 'test',
                'status'        => $e->getLogStatus(),
                'provider'      => $settings['provider'],
                'model'         => $settings['model'],
                'http_code'     => $e->getHttpCode(),
                'error_message' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getFriendlyMessage() . ' [' . $e->getMessage() . ']'];
        }

        Log::record([
            'action'            => 'test',
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
        $message = sprintf(
            __('Connection OK — %1$s answered in %2$d ms (%3$d tokens, US$ %4$s).', 'ticketassistant'),
            $response->model !== '' ? $response->model : $settings['model'],
            $response->durationMs,
            $response->totalTokens(),
            number_format($response->costUsd, 4),
        );

        $embedder = Config::embeddingClient($config);
        if ($embedder !== null) {
            try {
                $result   = $embedder->embed(['connection test'], (int) $config['embedding_dimensions']);
                $message .= ' ' . sprintf(__('Embeddings OK (%d dimensions).', 'ticketassistant'), count($result['vectors'][0] ?? []));
            } catch (LlmException $e) {
                $message .= ' ' . __('Embeddings failed:', 'ticketassistant') . ' ' . $e->getMessage();
            }
        }
        return ['success' => true, 'message' => $message];
    }

    /**
     * Models offered by the provider, with the SAVED address and key.
     *
     * @return array{success: bool, models: string[], message: string}
     */
    public static function listModels(array $config): array
    {
        $missing = Providers::missingSettings(Config::connectionSettings($config));
        if (in_array('base_url', $missing, true) || in_array('api_key', $missing, true)) {
            return ['success' => false, 'models' => [], 'message' => __('Save the address and the key before listing the models.', 'ticketassistant')];
        }
        try {
            return ['success' => true, 'models' => Config::chatClient($config)->listModels(), 'message' => ''];
        } catch (LlmException $e) {
            return ['success' => false, 'models' => [], 'message' => $e->getFriendlyMessage() . ' [' . $e->getMessage() . ']'];
        }
    }

    /**
     * Automatic actions of the plugin.
     *
     * @return array<string, array{active: bool, cli: bool, lastrun: string|null}>
     */
    public static function cronTasks(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $it  = $DB->request([
            'SELECT' => ['name', 'state', 'mode', 'lastrun'],
            'FROM'   => 'glpi_crontasks',
            // Exact class names: GLPI escapes namespaced identifiers on every version
            'WHERE'  => ['itemtype' => [CaseLibrary::class, Learner::class, Triage::class, Log::class]],
        ]);
        foreach ($it as $row) {
            $out[(string) $row['name']] = [
                'active'  => (int) $row['state'] !== CronTask::STATE_DISABLE,
                'cli'     => (int) $row['mode'] === CronTask::MODE_EXTERNAL,
                'lastrun' => $row['lastrun'] !== null ? (string) $row['lastrun'] : null,
            ];
        }
        return $out;
    }
}
