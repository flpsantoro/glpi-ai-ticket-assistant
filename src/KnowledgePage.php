<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use GlpiPlugin\Ticketassistant\Knowledge\CaseLibrary;
use GlpiPlugin\Ticketassistant\Knowledge\Learner;
use Html;
use Ticket;

/**
 * "Learning from solved tickets" page: state of the library, manual runs,
 * evaluation of the classification and tickets probably misclassified.
 */
final class KnowledgePage
{
    public static function show(array $config): void
    {
        $stats  = CaseLibrary::stats();
        $action = Compat::url('front/knowledge.php');

        echo "<div class='container-xl my-3'>";
        echo "<div class='d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3'>";
        echo "<h2 class='mb-0'><i class='ti ti-school me-2'></i>" . Text::e(__('Learning from solved tickets', 'ticketassistant')) . '</h2>';
        echo "<div class='d-flex gap-2'>";
        echo "<a class='btn btn-outline-secondary' href='" . Text::e(Compat::url('front/stats.php')) . "'><i class='ti ti-chart-bar me-1'></i>" . Text::e(__('Statistics', 'ticketassistant')) . '</a>';
        echo "<a class='btn btn-outline-secondary' href='" . Text::e(Compat::url('front/config.form.php')) . "'><i class='ti ti-settings me-1'></i>" . Text::e(__('Configuration', 'ticketassistant')) . '</a>';
        echo '</div></div>';

        echo "<p class='text-muted'>" . Text::e(__('Every solved or closed ticket with an accepted solution becomes a case: a reference for the analysis of new tickets and a labelled example (type and category) for their classification. No model is trained or fine-tuned: the assistant retrieves the closest cases and, optionally, lets the AI distill them into reusable records.', 'ticketassistant')) . '</p>';

        // ---- State ----------------------------------------------------------
        $cards = [
            [__('Cases in the library', 'ticketassistant'), $stats['cases'], 'ti-books'],
            [__('Distilled by the AI', 'ticketassistant'), $stats['distilled'], 'ti-filter'],
            [__('Good references', 'ticketassistant'), $stats['reusable'], 'ti-star'],
            [__('With embeddings', 'ticketassistant'), $stats['embedded'], 'ti-vector'],
        ];
        echo "<div class='row g-3 mb-3'>";
        foreach ($cards as [$label, $value, $icon]) {
            echo "<div class='col-6 col-lg-3'><div class='card'><div class='card-body'>"
                . "<div class='text-muted'><i class='ti " . $icon . " me-1'></i>" . Text::e($label) . '</div>'
                . "<div class='h1 mb-0'>" . number_format((int) $value) . '</div></div></div></div>';
        }
        echo '</div>';
        echo "<p class='mb-3'>" . Text::e(sprintf(
            __('Last synchronization: %1$s · waiting for distillation: %2$d · errors: %3$d', 'ticketassistant'),
            $stats['last_sync'] ?? '-',
            $stats['pending'],
            $stats['errors'],
        )) . '</p>';

        // ---- Manual runs ----------------------------------------------------
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . Text::e(__('Run now', 'ticketassistant')) . '</h3></div><div class="card-body">';
        echo "<form method='post' action='" . Text::e($action) . "' class='d-flex flex-wrap gap-2 align-items-center'>";
        echo "<button type='submit' name='sync' value='1' class='btn btn-outline-primary'><i class='ti ti-refresh me-1'></i>" . Text::e(__('Synchronize (new and changed tickets)', 'ticketassistant')) . '</button>';
        echo "<button type='submit' name='rebuild' value='1' class='btn btn-outline-secondary' onclick=\"return confirm('" . Text::e(__('Read again every solved ticket? It can take a few minutes on large databases.', 'ticketassistant')) . "');\"><i class='ti ti-database-import me-1'></i>" . Text::e(__('Rebuild everything', 'ticketassistant')) . '</button>';
        $learn_disabled = empty($config['learn_enabled']) && empty($config['embeddings_enabled']);
        echo "<button type='submit' name='learn' value='1' class='btn btn-outline-primary'" . ($learn_disabled ? ' disabled' : '') . "><i class='ti ti-bulb me-1'></i>"
            . Text::e(sprintf(__('Learn now (%d tickets)', 'ticketassistant'), (int) $config['learn_batch'])) . '</button>';
        if ($learn_disabled) {
            echo "<span class='text-muted'>" . Text::e(__('Enable the AI distillation or the embeddings in the configuration to use it.', 'ticketassistant')) . '</span>';
        }
        Html::closeForm();
        echo '</div></div>';

        // ---- Evaluation -----------------------------------------------------
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . Text::e(__('How well do solved tickets predict the category?', 'ticketassistant')) . '</h3></div><div class="card-body">';
        echo "<p class='text-muted'>" . Text::e(__('Temporal evaluation: for each recent solved ticket, only older tickets are used to suggest its category, as it would have happened when it was opened. No AI call, no cost.', 'ticketassistant')) . '</p>';
        echo "<form method='post' action='" . Text::e($action) . "' class='d-flex flex-wrap gap-2 align-items-end mb-3'>";
        echo "<div><label class='form-label'>" . Text::e(__('Tickets evaluated', 'ticketassistant')) . "</label><input type='number' class='form-control' name='sample' value='200' min='20' max='1000'></div>";
        if (!empty($config['embeddings_enabled']) && $stats['embedded'] > 0) {
            echo "<label class='form-check mb-2'><input class='form-check-input' type='checkbox' name='use_embeddings' value='1' checked><span class='form-check-label'>" . Text::e(__('Use the embeddings', 'ticketassistant')) . '</span></label>';
        }
        echo "<button type='submit' name='evaluate' value='1' class='btn btn-primary'><i class='ti ti-target-arrow me-1'></i>" . Text::e(__('Evaluate', 'ticketassistant')) . '</button>';
        Html::closeForm();

        $evaluation = json_decode((string) ($config['last_evaluation'] ?? ''), true);
        if (is_array($evaluation) && !empty($evaluation['sample'])) {
            self::evaluationReport($evaluation);
        } else {
            echo "<p class='mb-0'>" . Text::e(__('No evaluation yet.', 'ticketassistant')) . '</p>';
        }
        echo '</div></div>';

        // ---- Disagreements --------------------------------------------------
        $rows = Learner::disagreements(30);
        if (count($rows)) {
            echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . Text::e(__('Solved tickets the AI would classify differently', 'ticketassistant')) . '</h3></div>';
            echo "<div class='card-body p-0'><table class='table table-sm mb-0'><thead><tr><th>" . Text::e(__('Ticket', 'ticketassistant')) . '</th><th>'
                . Text::e(__('Current category', 'ticketassistant')) . '</th><th>' . Text::e(__('Category suggested by the AI', 'ticketassistant')) . '</th><th>'
                . Text::e(__('Summary', 'ticketassistant')) . '</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo "<tr><td><a href='" . Text::e(Ticket::getFormURLWithID((int) $row['tickets_id'])) . "'>#" . (int) $row['tickets_id'] . '</a></td>'
                    . '<td>' . Text::e((string) $row['category_name']) . '</td>'
                    . '<td>' . Text::e(Text::toPlainText((string) $row['ai_category_name'], 200)) . '</td>'
                    . "<td class='text-muted'>" . Text::e(Text::truncate((string) $row['ai_summary'], 160)) . '</td></tr>';
            }
            echo '</tbody></table></div>';
            echo "<div class='card-footer text-muted'>" . Text::e(__('Fixing the category of these tickets improves the suggestions for the next ones.', 'ticketassistant')) . '</div></div>';
        }

        echo '</div>';
    }

    private static function evaluationReport(array $e): void
    {
        $pct = static function ($value): string {
            return number_format(100 * (float) $value, 1) . '%';
        };
        echo "<div class='row g-3 mb-3'>";
        foreach (
            [
                [__('Right category (1st suggestion)', 'ticketassistant'), $pct($e['accuracy_top1'])],
                [__('Right category among the 3 first', 'ticketassistant'), $pct($e['accuracy_top3'])],
                [__('Right type (incident / request)', 'ticketassistant'), $pct($e['accuracy_type'])],
                [__('Tickets with a suggestion', 'ticketassistant'), $pct($e['coverage'])],
            ] as [$label, $value]
        ) {
            echo "<div class='col-6 col-lg-3'><div class='border rounded p-2'><div class='text-muted' style='font-size: .875em;'>" . Text::e($label) . "</div><div class='h2 mb-0'>" . Text::e($value) . '</div></div></div>';
        }
        echo '</div>';
        echo "<p class='text-muted'>" . Text::e(sprintf(
            __('%1$d tickets, method: %2$s, %3$s s, on %4$s.', 'ticketassistant'),
            (int) $e['sample'],
            (string) $e['method'],
            number_format(((int) $e['duration_ms']) / 1000, 1),
            (string) $e['date'],
        )) . '</p>';

        if (!empty($e['calibration'])) {
            echo '<h4>' . Text::e(__('Accuracy by confidence', 'ticketassistant')) . '</h4>';
            echo "<p class='text-muted'>" . Text::e(__('Use it to choose the threshold of the automatic application: pick a confidence level whose accuracy you accept.', 'ticketassistant')) . '</p>';
            echo "<table class='table table-sm w-auto'><thead><tr><th>" . Text::e(__('Confidence', 'ticketassistant')) . "</th><th class='text-end'>" . Text::e(__('Tickets', 'ticketassistant')) . "</th><th class='text-end'>" . Text::e(__('Accuracy', 'ticketassistant')) . '</th></tr></thead><tbody>';
            foreach ($e['calibration'] as $bucket) {
                echo '<tr><td>' . Text::e($bucket['label']) . "</td><td class='text-end'>" . (int) $bucket['count'] . "</td><td class='text-end'>" . ($bucket['count'] ? $pct($bucket['accuracy']) : '-') . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        if (!empty($e['per_category'])) {
            echo '<h4>' . Text::e(__('Per category', 'ticketassistant')) . '</h4>';
            echo "<div class='table-responsive'><table class='table table-sm'><thead><tr><th>" . Text::e(__('Category', 'ticketassistant')) . "</th><th class='text-end'>"
                . Text::e(__('Tickets', 'ticketassistant')) . "</th><th class='text-end'>" . Text::e(__('Found', 'ticketassistant')) . '</th></tr></thead><tbody>';
            foreach ($e['per_category'] as $row) {
                $class = (float) $row['recall'] < 0.5 ? " class='text-danger'" : '';
                echo '<tr><td>' . Text::e((string) $row['name']) . "</td><td class='text-end'>" . (int) $row['support'] . "</td><td class='text-end'" . $class . '>' . $pct($row['recall']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        if (!empty($e['confusions'])) {
            echo '<h4>' . Text::e(__('Most frequent confusions', 'ticketassistant')) . '</h4>';
            echo "<p class='text-muted'>" . Text::e(__('Categories often mistaken for one another may overlap in the category tree, or be used inconsistently.', 'ticketassistant')) . '</p><ul>';
            foreach ($e['confusions'] as $row) {
                echo '<li>' . Text::e((string) $row['actual']) . ' → ' . Text::e((string) $row['predicted']) . ' (' . (int) $row['count'] . ')</li>';
            }
            echo '</ul>';
        }

        if (!empty($e['suspicious'])) {
            echo '<h4>' . Text::e(__('Solved tickets probably misclassified', 'ticketassistant')) . '</h4><ul>';
            foreach ($e['suspicious'] as $row) {
                echo "<li><a href='" . Text::e(Ticket::getFormURLWithID((int) $row['tickets_id'])) . "'>#" . (int) $row['tickets_id'] . '</a> '
                    . Text::e((string) $row['actual']) . ' → <strong>' . Text::e((string) $row['predicted']) . '</strong> (' . $pct($row['score']) . ')</li>';
            }
            echo '</ul>';
        }
    }
}
