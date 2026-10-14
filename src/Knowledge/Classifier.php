<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Knowledge;

use DbUtils;
use GlpiPlugin\Ticketassistant\Compat;
use GlpiPlugin\Ticketassistant\Text;
use ITILCategory;
use Ticket;

/**
 * Ticket classification learned from solved tickets.
 *
 * 1. k nearest neighbours: the categories and types of the most similar
 *    solved tickets are weighted by similarity (no AI call, instant);
 * 2. optionally, the language model chooses among the candidates, reading
 *    the ticket itself (see Assistant::classify()).
 *
 * evaluate() measures how well the library predicts the category of solved
 * tickets (temporal hold-out: each ticket is classified with older tickets
 * only) and the accuracy per confidence level: it tells whether the
 * "training data" and the category tree are good enough to rely on the
 * suggestions, and which threshold to use for automatic application.
 */
final class Classifier
{
    /** Similarity from which the closest solved ticket fully supports a suggestion */
    private const STRONG_MATCH = 0.3;

    /**
     * Category/type vote over similar solved tickets.
     *
     * @param array $options exclude_tickets_id, entities, neighbors, vector, scan_limit,
     *                       before (only cases solved before this date)
     *
     * @return array{
     *   categories: array<int, array{id: int, name: string, score: float, votes: int}>,
     *   types: array<int, float>,
     *   neighbors: array<int, array{tickets_id: int, category_id: int, similarity: float}>,
     *   best: float,
     *   method: string
     * }
     */
    public static function knn(string $text, array $options = []): array
    {
        $method    = !empty($options['vector']) ? 'embeddings' : 'fulltext';
        $neighbors = CaseLibrary::search($text, [
            'exclude_tickets_id' => (int) ($options['exclude_tickets_id'] ?? 0),
            'entities'           => $options['entities'] ?? null,
            'limit'              => max(5, (int) ($options['neighbors'] ?? 25)),
            'vector'             => $options['vector'] ?? null,
            'scan_limit'         => (int) ($options['scan_limit'] ?? 2000),
            'fields'             => 'labels',
            'no_fallback'        => true,
            'before'             => $options['before'] ?? null,
        ]);

        $weights_cat  = [];
        $votes        = [];
        $names        = [];
        $weights_type = [1 => 0.0, 2 => 0.0];
        $total        = 0.0;
        $list         = [];
        $best         = count($neighbors) ? (float) $neighbors[0]['similarity'] : 0.0;

        foreach ($neighbors as $row) {
            $similarity = (float) $row['similarity'];
            // Embeddings give close similarities to related and unrelated tickets:
            // sharpened, the closest neighbours dominate the vote. The similarity
            // of the words is spread enough to weigh the vote itself.
            $weight = $method === 'embeddings'
                ? ($best > 0 ? exp(6 * ($similarity - $best)) * max($similarity, 0.01) : 0.01)
                : max($similarity, 0.0001);
            $cat    = (int) $row['itilcategories_id'];
            $type   = (int) $row['type'];
            if ($cat > 0) {
                $weights_cat[$cat] = ($weights_cat[$cat] ?? 0.0) + $weight;
                $votes[$cat]       = ($votes[$cat] ?? 0) + 1;
                $names[$cat]       = (string) ($row['category_name'] ?? '');
            }
            if (isset($weights_type[$type])) {
                $weights_type[$type] += $weight;
            }
            $total += $weight;
            $list[] = ['tickets_id' => (int) $row['tickets_id'], 'category_id' => $cat, 'similarity' => $similarity];
        }

        arsort($weights_cat);
        $categories = [];
        $sum_cat    = array_sum($weights_cat);
        foreach (array_slice($weights_cat, 0, 8, true) as $cat => $weight) {
            $categories[] = [
                'id'    => (int) $cat,
                'name'  => $names[$cat] !== '' ? $names[$cat] : ('#' . $cat),
                'score' => $sum_cat > 0 ? round($weight / $sum_cat, 4) : 0.0,
                'votes' => $votes[$cat],
            ];
        }

        $sum_type = $weights_type[1] + $weights_type[2];
        $types    = [
            1 => $sum_type > 0 ? round($weights_type[1] / $sum_type, 4) : 0.0,
            2 => $sum_type > 0 ? round($weights_type[2] / $sum_type, 4) : 0.0,
        ];

        return [
            'categories' => $categories,
            'types'      => $types,
            'neighbors'  => $list,
            'best'       => $best,
            'method'     => $method,
        ];
    }

    /**
     * Confidence of a category suggested by the vote: its share of the vote,
     * reduced when fewer than 5 solved tickets voted or when even the closest
     * one is a weak match.
     *
     * @param float $score share of the vote of the category (0–1)
     * @param int   $votes solved tickets that voted
     * @param float $best  similarity of the closest solved ticket
     */
    public static function confidence(float $score, int $votes, float $best): float
    {
        return $score * min(1.0, $votes / 5) * min(1.0, $best / self::STRONG_MATCH);
    }

    /**
     * Categories a ticket of this entity can receive, with the type flags.
     *
     * @return array<int, array{id: int, name: string, incident: bool, request: bool, comment: string}>
     */
    public static function allowedCategories(int $entities_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria = [
            'SELECT' => ['id', 'completename', 'is_incident', 'is_request', 'comment'],
            'FROM'   => ITILCategory::getTable(),
            'WHERE'  => (new DbUtils())->getEntitiesRestrictCriteria(ITILCategory::getTable(), '', $entities_id, true),
            'ORDER'  => 'completename',
        ];
        $out = [];
        foreach ($DB->request($criteria) as $row) {
            $out[(int) $row['id']] = [
                'id'       => (int) $row['id'],
                'name'     => Text::toPlainText((string) $row['completename'], 255),
                'incident' => !empty($row['is_incident']),
                'request'  => !empty($row['is_request']),
                'comment'  => Text::toPlainText((string) ($row['comment'] ?? ''), 200),
            ];
        }
        return $out;
    }

    /**
     * Whether a category may be used for a ticket type.
     */
    public static function categoryFitsType(array $category, int $type): bool
    {
        return $type === Ticket::DEMAND_TYPE ? $category['request'] : $category['incident'];
    }

    // ------------------------------------------------------------------
    // Evaluation
    // ------------------------------------------------------------------

    /**
     * Temporal evaluation over the most recent categorized cases: each case
     * is classified with the cases solved before it only, from its raw
     * problem text (the distilled summary was written knowing the solution).
     *
     * @return array<string, mixed>
     */
    public static function evaluate(int $sample = 200, bool $use_embeddings = false, int $neighbors = 25): array
    {
        @set_time_limit(0);
        $start  = microtime(true);
        $sample = max(20, min(1000, $sample));

        $columns = '`id`, `tickets_id`, `itilcategories_id`, `category_name`, `type`, `problem_text`, `date_solved`';
        if ($use_embeddings) {
            $columns .= ', `embedding`';
        }
        $cases = Compat::rawSelect(
            "SELECT {$columns} FROM `" . CaseLibrary::TABLE . '`
             WHERE `itilcategories_id` > 0' . ($use_embeddings ? ' AND `embedding` IS NOT NULL' : '') . '
             ORDER BY `date_solved` DESC, `id` DESC
             LIMIT ' . $sample,
        );

        $total      = 0;
        $top1       = 0;
        $top3       = 0;
        $type_ok    = 0;
        $covered    = 0;
        $per_cat    = [];
        $confusions = [];
        $suspicious = [];
        $buckets    = [
            ['label' => '≥ 80%', 'count' => 0, 'hits' => 0],
            ['label' => '60–80%', 'count' => 0, 'hits' => 0],
            ['label' => '40–60%', 'count' => 0, 'hits' => 0],
            ['label' => '< 40%', 'count' => 0, 'hits' => 0],
        ];

        foreach ($cases as $case) {
            $actual = (int) $case['itilcategories_id'];
            $vector = $use_embeddings ? Embeddings::unpack((string) $case['embedding']) : null;
            $result = self::knn((string) $case['problem_text'], [
                'exclude_tickets_id' => (int) $case['tickets_id'],
                'neighbors'          => $neighbors,
                'vector'             => $vector,
                'scan_limit'         => 3000,
                'before'             => !empty($case['date_solved']) ? (string) $case['date_solved'] : null,
            ]);
            $total++;
            $name                      = (string) $case['category_name'];
            $per_cat[$actual]          = $per_cat[$actual] ?? ['id' => $actual, 'name' => $name, 'support' => 0, 'hits' => 0];
            $per_cat[$actual]['support']++;

            if (!count($result['categories'])) {
                continue;
            }
            $covered++;
            $predicted = (int) $result['categories'][0]['id'];
            $ids       = array_map(static function ($c) {
                return (int) $c['id'];
            }, array_slice($result['categories'], 0, 3));

            // Same confidence as the suggestions (Suggester)
            $votes      = array_sum(array_map(static function ($c) {
                return $c['votes'];
            }, $result['categories']));
            $confidence = self::confidence((float) $result['categories'][0]['score'], (int) $votes, (float) $result['best']);
            $bucket     = $confidence >= 0.8 ? 0 : ($confidence >= 0.6 ? 1 : ($confidence >= 0.4 ? 2 : 3));
            $buckets[$bucket]['count']++;
            if ($predicted === $actual) {
                $buckets[$bucket]['hits']++;
            }

            if ($predicted === $actual) {
                $top1++;
                $per_cat[$actual]['hits']++;
            } else {
                $pair              = $actual . '>' . $predicted;
                $confusions[$pair] = $confusions[$pair] ?? [
                    'actual'    => $name,
                    'predicted' => (string) $result['categories'][0]['name'],
                    'count'     => 0,
                ];
                $confusions[$pair]['count']++;

                if ($result['categories'][0]['score'] >= 0.6 && $result['categories'][0]['votes'] >= 4) {
                    $suspicious[] = [
                        'tickets_id' => (int) $case['tickets_id'],
                        'actual'     => $name,
                        'predicted'  => (string) $result['categories'][0]['name'],
                        'score'      => (float) $result['categories'][0]['score'],
                    ];
                }
            }
            if (in_array($actual, $ids, true)) {
                $top3++;
            }
            $predicted_type = $result['types'][2] > $result['types'][1] ? 2 : 1;
            if ($predicted_type === (int) $case['type']) {
                $type_ok++;
            }
        }

        usort($per_cat, static function ($a, $b) {
            return $b['support'] <=> $a['support'];
        });
        usort($confusions, static function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        usort($suspicious, static function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return [
            'date'          => date('Y-m-d H:i:s'),
            'method'        => $use_embeddings ? 'embeddings' : 'fulltext',
            'sample'        => $total,
            'accuracy_top1' => $total ? round($top1 / $total, 4) : 0.0,
            'accuracy_top3' => $total ? round($top3 / $total, 4) : 0.0,
            'accuracy_type' => $total ? round($type_ok / $total, 4) : 0.0,
            'coverage'      => $total ? round($covered / $total, 4) : 0.0,
            'per_category'  => array_slice(array_map(static function ($row) {
                $row['recall'] = $row['support'] ? round($row['hits'] / $row['support'], 4) : 0.0;
                return $row;
            }, $per_cat), 0, 25),
            'confusions'    => array_slice(array_values($confusions), 0, 10),
            'suspicious'    => array_slice($suspicious, 0, 25),
            'calibration'   => array_map(static function ($bucket) {
                return [
                    'label'    => $bucket['label'],
                    'count'    => $bucket['count'],
                    'accuracy' => $bucket['count'] ? round($bucket['hits'] / $bucket['count'], 4) : 0.0,
                ];
            }, $buckets),
            'duration_ms'   => (int) round((microtime(true) - $start) * 1000),
        ];
    }
}
