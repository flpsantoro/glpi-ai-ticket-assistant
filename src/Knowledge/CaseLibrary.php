<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Knowledge;

use CommonITILObject;
use CommonITILValidation;
use CronTask;
use GlpiPlugin\Ticketassistant\Compat;
use GlpiPlugin\Ticketassistant\Config;
use GlpiPlugin\Ticketassistant\Privacy;
use GlpiPlugin\Ticketassistant\Text;
use Session;

/**
 * Case library: what the assistant learns from solved and closed tickets.
 *
 * One row per solved/closed ticket having a non refused solution: problem
 * (title + description), solution, category and type — the labels used to
 * classify new tickets — plus, when learning is enabled, an AI distilled
 * record (generic summary, reusable resolution steps, keywords, suggested
 * type/category) and an embedding for semantic search.
 *
 * Search: MySQL FULLTEXT finds the candidates (no extra service), ranked by
 * the similarity of their meaningful words (Similarity). With embeddings
 * enabled, the candidates are re-ranked by the cosine of the vectors, and the
 * most recent cases are scanned as well, so that tickets written with
 * different words still match.
 */
final class CaseLibrary extends \CommonGLPI
{
    public const TABLE = 'glpi_plugin_ticketassistant_cases';

    private const BATCH = 500;

    public static function getTypeName($nb = 0)
    {
        return __('AI Ticket Assistant', 'ticketassistant') . ' — ' . __('Library of solved tickets', 'ticketassistant');
    }

    // ------------------------------------------------------------------
    // Synchronization
    // ------------------------------------------------------------------

    /**
     * (Re)builds the library from solved/closed tickets.
     *
     * @param bool $full true = every ticket; false = tickets changed since
     *                   the last synchronization (minus 48 h)
     *
     * @return array{added: int, updated: int, unchanged: int, removed: int}
     */
    public static function sync(bool $full, array $config): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        @set_time_limit(0);

        $since = null;
        if (!$full) {
            $last = self::lastSync();
            if ($last !== null) {
                $since = date('Y-m-d H:i:s', strtotime($last) - 48 * 3600);
            }
        }

        $detectors = array_unique(array_merge(Config::maskDetectors($config), ['secrets']));
        $custom    = (string) ($config['mask_custom'] ?? '');
        $stats     = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0];
        $last_id   = 0;

        while (true) {
            $where_since = $since !== null
                ? 'AND (t.`date_mod` >= ' . Compat::quote($since) . ' OR t.`solvedate` >= ' . Compat::quote($since) . ')'
                : '';

            // Last non refused solution of each solved/closed ticket
            $rows = Compat::rawSelect(
                'SELECT t.`id`, t.`entities_id`, t.`itilcategories_id`, t.`type`, t.`name`, t.`content`,
                        t.`solvedate`, t.`closedate`, c.`completename` AS category_name, s.`content` AS solution_content
                 FROM `glpi_tickets` t
                 INNER JOIN `glpi_itilsolutions` s
                         ON s.`itemtype` = ' . Compat::quote('Ticket') . '
                        AND s.`items_id` = t.`id`
                        AND s.`id` = (
                             SELECT MAX(s2.`id`) FROM `glpi_itilsolutions` s2
                             WHERE s2.`itemtype` = ' . Compat::quote('Ticket') . '
                               AND s2.`items_id` = t.`id`
                               AND s2.`status` <> ' . (int) CommonITILValidation::REFUSED . '
                        )
                 LEFT JOIN `glpi_itilcategories` c ON c.`id` = t.`itilcategories_id`
                 WHERE t.`is_deleted` = 0
                   AND t.`status` IN (' . (int) CommonITILObject::SOLVED . ', ' . (int) CommonITILObject::CLOSED . ')
                   AND t.`id` > ' . (int) $last_id . '
                   ' . $where_since . '
                 ORDER BY t.`id` ASC
                 LIMIT ' . self::BATCH
            );
            if (!count($rows)) {
                break;
            }

            $ids      = array_map(static function ($row) {
                return (int) $row['id'];
            }, $rows);
            $existing = [];
            foreach ($DB->request(['SELECT' => ['tickets_id', 'content_hash'], 'FROM' => self::TABLE, 'WHERE' => ['tickets_id' => $ids]]) as $row) {
                $existing[(int) $row['tickets_id']] = (string) $row['content_hash'];
            }

            foreach ($rows as $row) {
                $last_id = (int) $row['id'];

                $solution = Text::toPlainText((string) $row['solution_content'], 2000);
                if (trim($solution) === '') {
                    continue;
                }
                $problem = Text::toPlainText((string) $row['name'], 300);
                $content = Text::toPlainText((string) $row['content'], 3500);
                if ($content !== '') {
                    $problem .= "\n" . $content;
                }
                $problem  = Privacy::mask(mb_substr($problem, 0, 4000), $detectors, $custom);
                $solution = Privacy::mask($solution, $detectors, $custom);
                $category = mb_substr(Text::toPlainText((string) ($row['category_name'] ?? ''), 255), 0, 255);
                $hash     = sha1($problem . "\0" . $solution . "\0" . $row['itilcategories_id'] . "\0" . $row['type'] . "\0" . $row['entities_id']);

                $values = [
                    'entities_id'       => (int) $row['entities_id'],
                    'itilcategories_id' => (int) $row['itilcategories_id'],
                    'type'              => (int) $row['type'],
                    'category_name'     => Compat::dbValue($category),
                    'problem_text'      => Compat::dbValue($problem),
                    'solution_text'     => Compat::dbValue($solution),
                    'content_hash'      => $hash,
                    'date_solved'       => $row['solvedate'] ?: ($row['closedate'] ?: null),
                    'date_mod'          => date('Y-m-d H:i:s'),
                ];

                if (!isset($existing[$last_id])) {
                    $values['tickets_id'] = $last_id;
                    $values['ai_status']  = 'pending';
                    $DB->insert(self::TABLE, $values);
                    $stats['added']++;
                } elseif ($existing[$last_id] !== $hash) {
                    // Content changed: the AI record and the embedding are stale
                    $values['ai_status'] = 'pending';
                    $values['embedding'] = null;
                    $DB->update(self::TABLE, $values, ['tickets_id' => $last_id]);
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            }

            if (count($rows) < self::BATCH) {
                break;
            }
        }

        $stats['removed'] = self::removeStale();
        return $stats;
    }

    /**
     * Removes cases of deleted, purged or reopened tickets.
     */
    public static function removeStale(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = Compat::rawSelect(
            'SELECT c.`id` FROM `' . self::TABLE . '` c
             LEFT JOIN `glpi_tickets` t ON t.`id` = c.`tickets_id`
             WHERE t.`id` IS NULL OR t.`is_deleted` = 1
                OR t.`status` NOT IN (' . (int) CommonITILObject::SOLVED . ', ' . (int) CommonITILObject::CLOSED . ')'
        );
        if (!count($rows)) {
            return 0;
        }
        $ids = array_map(static function ($row) {
            return (int) $row['id'];
        }, $rows);
        foreach (array_chunk($ids, 500) as $chunk) {
            $DB->delete(self::TABLE, ['id' => $chunk]);
        }
        return count($ids);
    }

    public static function lastSync(): ?string
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'SELECT' => [Compat::expr('MAX(' . $DB->quoteName('date_mod') . ') AS last_sync')],
            'FROM'   => self::TABLE,
        ]);
        foreach ($it as $row) {
            return !empty($row['last_sync']) ? (string) $row['last_sync'] : null;
        }
        return null;
    }

    /**
     * @return array{cases: int, distilled: int, embedded: int, pending: int, errors: int, reusable: int, last_sync: string|null}
     */
    public static function stats(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $q   = static function (string $name) use ($DB): string {
            return $DB->quoteName($name);
        };
        $out = ['cases' => 0, 'distilled' => 0, 'embedded' => 0, 'pending' => 0, 'errors' => 0, 'reusable' => 0, 'last_sync' => null];
        $it  = $DB->request([
            'SELECT' => [
                Compat::expr('COUNT(*) AS cases'),
                Compat::expr('SUM(CASE WHEN ' . $q('ai_status') . " = 'done' THEN 1 ELSE 0 END) AS distilled"),
                Compat::expr('SUM(CASE WHEN ' . $q('embedding') . ' IS NOT NULL THEN 1 ELSE 0 END) AS embedded'),
                Compat::expr('SUM(CASE WHEN ' . $q('ai_status') . " = 'pending' THEN 1 ELSE 0 END) AS pending"),
                Compat::expr('SUM(CASE WHEN ' . $q('ai_status') . " = 'error' THEN 1 ELSE 0 END) AS errors"),
                Compat::expr('SUM(CASE WHEN ' . $q('ai_status') . " = 'done' AND " . $q('ai_reusable') . ' = 1 THEN 1 ELSE 0 END) AS reusable'),
                Compat::expr('MAX(' . $q('date_mod') . ') AS last_sync'),
            ],
            'FROM'   => self::TABLE,
        ]);
        foreach ($it as $row) {
            foreach (['cases', 'distilled', 'embedded', 'pending', 'errors', 'reusable'] as $key) {
                $out[$key] = (int) ($row[$key] ?? 0);
            }
            $out['last_sync'] = $row['last_sync'] ?? null;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Search
    // ------------------------------------------------------------------

    /**
     * Similar solved tickets.
     *
     * @param string $query   free text (title + description of the ticket)
     * @param array  $options exclude_tickets_id (int), entities (int[]|null = no restriction),
     *                        category_id (int, boosted), limit (int), reusable_only (bool),
     *                        vector (float[]|null, query embedding), scan_limit (int),
     *                        fields ('full'|'labels'), before (datetime: cases solved before it),
     *                        no_fallback (bool), min_similarity (float: weaker matches are dropped;
     *                        cosine of the words in full-text search, of the vectors with embeddings)
     *
     * @return array<int, array<string, mixed>> rows with a "similarity" key in [0, 1], best first
     */
    public static function search(string $query, array $options = []): array
    {
        $limit        = max(1, (int) ($options['limit'] ?? 3));
        $exclude      = (int) ($options['exclude_tickets_id'] ?? 0);
        $entities     = $options['entities'] ?? null;
        $category_id  = (int) ($options['category_id'] ?? 0);
        $reusable     = !empty($options['reusable_only']);
        $vector       = isset($options['vector']) && is_array($options['vector']) && count($options['vector']) ? $options['vector'] : null;
        $scan_limit   = max(0, (int) ($options['scan_limit'] ?? 2000));
        $labels_only  = ($options['fields'] ?? 'full') === 'labels';

        $where = ['1 = 1'];
        if ($exclude > 0) {
            $where[] = '`tickets_id` <> ' . $exclude;
        }
        if (is_array($entities)) {
            $entities = array_values(array_filter(array_map('intval', $entities), static function ($id) {
                return $id >= 0;
            }));
            $where[]  = count($entities) ? '`entities_id` IN (' . implode(',', $entities) . ')' : '1 = 0';
        }
        if ($reusable) {
            $where[] = "(`ai_status` <> 'done' OR `ai_reusable` = 1)";
        }
        if (!empty($options['before'])) {
            // Temporal evaluation: only what was known at that date
            $where[] = '`date_solved` < ' . Compat::quote((string) $options['before']);
        }
        $where_sql = implode(' AND ', $where);

        $columns = $labels_only
            ? '`id`, `tickets_id`, `itilcategories_id`, `type`, `category_name`'
            : '`id`, `tickets_id`, `entities_id`, `itilcategories_id`, `type`, `category_name`, `problem_text`, `solution_text`, `ai_status`, `ai_summary`, `ai_resolution`, `date_solved`';

        // ---- FULLTEXT candidates, ranked by the similarity of their words ----
        $candidates = [];
        $clean      = Text::cleanFulltextQuery(Similarity::withoutStopwords($query), 600);
        if ($clean !== '') {
            $match = 'MATCH(`problem_text`, `solution_text`, `ai_summary`, `ai_keywords`) AGAINST (' . Compat::quote($clean) . ' IN NATURAL LANGUAGE MODE)';
            $rows  = Compat::rawSelect(
                "SELECT {$columns}, {$match} AS score,
                        `problem_text` AS words_problem, `ai_summary` AS words_summary, `ai_keywords` AS words_keywords
                 FROM `" . self::TABLE . "`
                 WHERE {$where_sql} AND {$match} > 0
                 ORDER BY score DESC
                 LIMIT " . ($vector !== null ? 100 : max(50, $limit * 4))
            );
            $query_vector = Similarity::vector($query);
            foreach ($rows as $row) {
                $similarity = Similarity::cosine($query_vector, Similarity::caseVector(
                    (int) $row['id'],
                    $row['words_problem'] . "\n" . $row['words_summary'] . "\n" . $row['words_keywords']
                ));
                unset($row['words_problem'], $row['words_summary'], $row['words_keywords']);
                if ($similarity <= 0.0) {
                    continue; // matched only on words without meaning
                }
                $row['similarity']            = round($similarity, 4);
                $candidates[(int) $row['id']] = $row;
            }
        }

        // ---- Semantic re-ranking -------------------------------------------
        if ($vector !== null) {
            $vector = Embeddings::normalize($vector);
            $scored = [];

            // Embeddings of the FULLTEXT candidates
            if (count($candidates)) {
                foreach (Compat::rawSelect('SELECT `id`, `embedding` FROM `' . self::TABLE . '` WHERE `id` IN (' . implode(',', array_keys($candidates)) . ') AND `embedding` IS NOT NULL') as $row) {
                    $scored[(int) $row['id']] = Embeddings::dot($vector, Embeddings::unpack((string) $row['embedding']));
                }
            }
            // Most recent cases, whatever their words
            if ($scan_limit > 0) {
                $offset = 0;
                while ($offset < $scan_limit) {
                    $chunk = min(500, $scan_limit - $offset);
                    $rows  = Compat::rawSelect(
                        'SELECT `id`, `embedding` FROM `' . self::TABLE . "`
                         WHERE {$where_sql} AND `embedding` IS NOT NULL
                         ORDER BY `date_solved` DESC, `id` DESC
                         LIMIT {$chunk} OFFSET {$offset}"
                    );
                    foreach ($rows as $row) {
                        $id = (int) $row['id'];
                        if (!isset($scored[$id])) {
                            $scored[$id] = Embeddings::dot($vector, Embeddings::unpack((string) $row['embedding']));
                        }
                    }
                    if (count($rows) < $chunk) {
                        break;
                    }
                    $offset += $chunk;
                }
            }

            if (count($scored)) {
                arsort($scored);
                $top     = array_slice($scored, 0, max($limit * 4, 30), true);
                $missing = array_diff(array_keys($top), array_keys($candidates));
                if (count($missing)) {
                    foreach (Compat::rawSelect("SELECT {$columns} FROM `" . self::TABLE . '` WHERE `id` IN (' . implode(',', $missing) . ')') as $row) {
                        $candidates[(int) $row['id']] = $row;
                    }
                }
                $ranked = [];
                foreach ($top as $id => $cosine) {
                    if (isset($candidates[$id])) {
                        $row               = $candidates[$id];
                        $row['similarity'] = round(max(0.0, $cosine), 4);
                        $ranked[$id]       = $row;
                    }
                }
                // FULLTEXT-only candidates (not embedded yet) come after
                foreach ($candidates as $id => $row) {
                    if (!isset($ranked[$id]) && !isset($scored[$id])) {
                        $row['similarity'] = round($row['similarity'] * 0.5, 4);
                        $ranked[$id]       = $row;
                    }
                }
                $candidates = $ranked;
            }
        }

        // ---- Category boost and final order --------------------------------
        $results = array_values($candidates);
        if ($category_id > 0) {
            foreach ($results as &$row) {
                if ((int) $row['itilcategories_id'] === $category_id) {
                    $row['similarity'] = min(1.0, $row['similarity'] * 1.1 + 0.02);
                }
            }
            unset($row);
        }
        usort($results, static function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        $min_similarity = (float) ($options['min_similarity'] ?? 0);
        if ($min_similarity > 0) {
            $results = array_values(array_filter($results, static function ($row) use ($min_similarity) {
                return (float) $row['similarity'] >= $min_similarity;
            }));
        }

        // Fallback: most recent cases of the same category
        if (count($results) < $limit && $category_id > 0 && empty($options['no_fallback'])) {
            $known = array_map(static function ($row) {
                return (int) $row['id'];
            }, $results);
            $rows  = Compat::rawSelect(
                "SELECT {$columns} FROM `" . self::TABLE . "`
                 WHERE {$where_sql} AND `itilcategories_id` = {$category_id}"
                . (count($known) ? ' AND `id` NOT IN (' . implode(',', $known) . ')' : '') . '
                 ORDER BY `date_solved` DESC
                 LIMIT ' . ($limit - count($results))
            );
            foreach ($rows as $row) {
                $row['similarity'] = 0.0;
                $results[]         = $row;
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Entities whose cases the current user may receive as references.
     *
     * @return int[]|null null = no restriction
     */
    public static function allowedEntities(array $config): ?array
    {
        if (empty($config['kb_same_entity'] ?? 1)) {
            return null;
        }
        if (!Session::getLoginUserID()) {
            return null;
        }
        $active = $_SESSION['glpiactiveentities'] ?? [];
        return is_array($active) ? array_map('intval', array_values($active)) : null;
    }

    /**
     * Text used to represent a case in semantic search.
     */
    public static function embeddingText(array $case): string
    {
        $parts = [];
        if (!empty($case['ai_summary'])) {
            $parts[] = (string) $case['ai_summary'];
        }
        if (!empty($case['ai_keywords'])) {
            $parts[] = (string) $case['ai_keywords'];
        }
        $parts[] = mb_substr((string) ($case['problem_text'] ?? ''), 0, 1500);
        return implode("\n", $parts);
    }

    // ------------------------------------------------------------------
    // Automatic action "ticketaisync"
    // ------------------------------------------------------------------

    public static function cronInfo($name)
    {
        if ($name === 'aisync') {
            return ['description' => __('AI Ticket Assistant: synchronizes the library of solved tickets', 'ticketassistant')];
        }
        return [];
    }

    /**
     * @param CronTask $task
     */
    public static function cronAisync($task)
    {
        $stats = self::sync(false, Config::getConfig());
        $task->addVolume($stats['added'] + $stats['updated']);
        $task->log(sprintf(
            __('%1$d added, %2$d updated, %3$d removed', 'ticketassistant'),
            $stats['added'],
            $stats['updated'],
            $stats['removed']
        ));
        return 1;
    }
}
