<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use GlpiPlugin\Ticketassistant\Knowledge\Triage;

/**
 * Usage dashboard (source: glpi_plugin_ticketassistant_logs). People are
 * always named from first name + last name, never from the login.
 */
final class Stats
{
    private const TABLE = Log::TABLE;

    public static function show(): void
    {
        echo "<div class='container-xl my-3'>";
        echo "<div class='d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3'>";
        echo "<h2 class='mb-0'><i class='ti ti-chart-bar me-2'></i>" . Text::e(__('AI assistant — statistics', 'ticketassistant')) . '</h2>';
        echo "<div class='d-flex gap-2'>";
        echo "<a class='btn btn-outline-secondary' href='" . Text::e(Compat::url('front/knowledge.php')) . "'><i class='ti ti-school me-1'></i>" . Text::e(__('Learning from solved tickets', 'ticketassistant')) . '</a>';
        echo "<a class='btn btn-outline-secondary' href='" . Text::e(Compat::url('front/config.form.php')) . "'><i class='ti ti-settings me-1'></i>" . Text::e(__('Configuration', 'ticketassistant')) . '</a>';
        echo '</div></div>';

        self::cards();

        echo "<div class='row g-3'>";
        echo "<div class='col-lg-7'>";
        self::dailySeries();
        self::quality();
        self::topCategories();
        echo '</div>';
        echo "<div class='col-lg-5'>";
        self::byAction();
        self::byStatus();
        self::byModel();
        self::topUsers();
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    private static function since(int $days): string
    {
        return $days === 0 ? date('Y-m-d 00:00:00') : date('Y-m-d 00:00:00', strtotime('-' . $days . ' days'));
    }

    private static function q(string $name): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        return $DB->quoteName($name);
    }

    /**
     * @return array{ok: int, cost: float, avg_s: float|null, blocked: int, errors: int}
     */
    private static function period(int $days): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $generation = '(' . self::q('status') . " = 'ok' AND " . self::q('action') . " IN ('analyze','improve','classify','article'))";
        $it         = $DB->request([
            'SELECT' => [
                Compat::expr('SUM(CASE WHEN ' . $generation . ' THEN 1 ELSE 0 END) AS ok_count'),
                Compat::expr('COALESCE(SUM(' . self::q('cost_usd') . '), 0) AS cost'),
                Compat::expr('AVG(CASE WHEN ' . $generation . ' THEN ' . self::q('duration_ms') . ' ELSE NULL END) AS avg_ms'),
                Compat::expr('SUM(CASE WHEN ' . self::q('status') . " LIKE 'blocked%' THEN 1 ELSE 0 END) AS blocked"),
                Compat::expr('SUM(CASE WHEN ' . self::q('status') . " LIKE 'error%' THEN 1 ELSE 0 END) AS errors"),
            ],
            'FROM'   => self::TABLE,
            'WHERE'  => [['date_creation' => ['>=', self::since($days)]]],
        ]);
        foreach ($it as $row) {
            return [
                'ok'      => (int) $row['ok_count'],
                'cost'    => (float) $row['cost'],
                'avg_s'   => $row['avg_ms'] !== null ? round(((float) $row['avg_ms']) / 1000, 1) : null,
                'blocked' => (int) $row['blocked'],
                'errors'  => (int) $row['errors'],
            ];
        }
        return ['ok' => 0, 'cost' => 0.0, 'avg_s' => null, 'blocked' => 0, 'errors' => 0];
    }

    private static function cards(): void
    {
        $periods = [
            0  => __('Today', 'ticketassistant'),
            7  => __('Last 7 days', 'ticketassistant'),
            30 => __('Last 30 days', 'ticketassistant'),
        ];
        echo "<div class='row g-3 mb-3'>";
        foreach ($periods as $days => $label) {
            $agg = self::period($days);
            echo "<div class='col-md-4'><div class='card'><div class='card-body'>";
            echo "<div class='text-muted'>" . Text::e($label) . '</div>';
            echo "<div class='d-flex align-items-baseline gap-2'><span class='h1 mb-0'>" . $agg['ok'] . "</span><span class='text-muted'>" . Text::e(__('generations', 'ticketassistant')) . '</span></div>';
            echo "<div class='mt-1'>US$ " . number_format($agg['cost'], 2) . ($agg['avg_s'] !== null ? ' · ' . Text::e(sprintf(__('average %s s', 'ticketassistant'), $agg['avg_s'])) : '') . '</div>';
            if ($agg['blocked'] || $agg['errors']) {
                echo "<div class='text-muted mt-1'>" . Text::e(sprintf(__('%1$d blocked · %2$d errors', 'ticketassistant'), $agg['blocked'], $agg['errors'])) . '</div>';
            }
            echo '</div></div></div>';
        }
        echo '</div>';
    }

    private static function dailySeries(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'SELECT'  => [
                Compat::expr('DATE(' . self::q('date_creation') . ') AS day'),
                Compat::expr('SUM(CASE WHEN ' . self::q('status') . " = 'ok' AND " . self::q('action') . " IN ('analyze','improve','classify','article') THEN 1 ELSE 0 END) AS ok_count"),
                Compat::expr('COALESCE(SUM(' . self::q('cost_usd') . '), 0) AS cost'),
            ],
            'FROM'    => self::TABLE,
            'WHERE'   => [['date_creation' => ['>=', self::since(13)]]],
            'GROUPBY' => Compat::expr('DATE(' . self::q('date_creation') . ')'),
        ]);
        $by_day = [];
        foreach ($it as $row) {
            $by_day[(string) $row['day']] = ['ok' => (int) $row['ok_count'], 'cost' => (float) $row['cost']];
        }

        $max  = 1;
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $day    = date('Y-m-d', strtotime('-' . $i . ' days'));
            $data   = $by_day[$day] ?? ['ok' => 0, 'cost' => 0.0];
            $days[] = ['day' => $day] + $data;
            $max    = max($max, $data['ok']);
        }

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . Text::e(__('Generations per day (14 days)', 'ticketassistant')) . '</h3></div>';
        echo "<div class='card-body'><div class='d-flex align-items-end gap-1' style='height: 140px;'>";
        foreach ($days as $d) {
            $height = $d['ok'] > 0 ? max(6, (int) round(($d['ok'] / $max) * 120)) : 2;
            $title  = date('d/m', strtotime($d['day'])) . ': ' . $d['ok'] . ' · US$ ' . number_format($d['cost'], 2);
            echo "<div class='flex-fill text-center' title='" . Text::e($title) . "'>";
            echo "<div class='mx-auto rounded-top' style='width: 70%; height: " . $height . 'px; background: var(--tblr-primary, #206bc4); opacity: ' . ($d['ok'] > 0 ? '1' : '.25') . ";'></div>";
            echo "<div class='text-muted' style='font-size: .65rem;'>" . date('d', strtotime($d['day'])) . '</div>';
            echo '</div>';
        }
        echo '</div></div></div>';
    }

    /**
     * Feedback, use of the drafts and quality of the classification.
     */
    private static function quality(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'SELECT' => [
                'action',
                Compat::expr('COUNT(*) AS total'),
                Compat::expr('SUM(CASE WHEN ' . self::q('feedback') . ' = 1 THEN 1 ELSE 0 END) AS positive'),
                Compat::expr('SUM(CASE WHEN ' . self::q('feedback') . ' = -1 THEN 1 ELSE 0 END) AS negative'),
                Compat::expr('SUM(CASE WHEN ' . self::q('used') . ' = 1 THEN 1 ELSE 0 END) AS used'),
            ],
            'FROM'    => self::TABLE,
            'WHERE'   => [
                'status' => 'ok',
                'action' => ['analyze', 'improve', 'article'],
                ['date_creation' => ['>=', self::since(30)]],
            ],
            'GROUPBY' => 'action',
        ]);

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . Text::e(__('Quality (30 days)', 'ticketassistant')) . '</h3></div>';
        echo "<div class='card-body p-0'><table class='table table-sm mb-0'><thead><tr><th>" . Text::e(__('Action', 'ticketassistant')) . "</th><th class='text-end'>"
            . Text::e(__('Generations', 'ticketassistant')) . "</th><th class='text-end'>" . Text::e(__('Draft used', 'ticketassistant')) . "</th><th class='text-end'>👍</th><th class='text-end'>👎</th></tr></thead><tbody>";
        $labels = self::actionLabels();
        foreach ($it as $row) {
            $total = max(1, (int) $row['total']);
            echo '<tr><td>' . Text::e($labels[$row['action']] ?? $row['action']) . "</td><td class='text-end'>" . (int) $row['total'] . '</td>'
                . "<td class='text-end'>" . round(100 * (int) $row['used'] / $total) . '%</td>'
                . "<td class='text-end'>" . (int) $row['positive'] . "</td><td class='text-end'>" . (int) $row['negative'] . '</td></tr>';
        }
        echo '</tbody></table></div>';

        $triage = Triage::quality(90);
        echo "<div class='card-body border-top'>";
        echo '<strong>' . Text::e(__('Classification suggestions (90 days)', 'ticketassistant')) . '</strong><br>';
        if ($triage['total'] === 0) {
            echo "<span class='text-muted'>" . Text::e(__('No suggestion yet.', 'ticketassistant')) . '</span>';
        } else {
            echo Text::e(sprintf(
                __('%1$d suggestions: %2$d applied by technicians, %3$d applied automatically, %4$d dismissed.', 'ticketassistant'),
                $triage['total'],
                $triage['applied'],
                $triage['auto_applied'],
                $triage['dismissed'],
            ));
            if ($triage['evaluated'] > 0) {
                echo '<br>' . Text::e(sprintf(
                    __('Accuracy on solved tickets: %1$d%% (%2$d of %3$d kept the suggested category).', 'ticketassistant'),
                    round(100 * $triage['correct'] / $triage['evaluated']),
                    $triage['correct'],
                    $triage['evaluated'],
                ));
            }
        }
        echo '</div></div>';
    }

    /**
     * @return array<string, string>
     */
    private static function actionLabels(): array
    {
        return [
            'analyze'   => __('Analyze the ticket', 'ticketassistant'),
            'improve'   => __('Improve my text', 'ticketassistant'),
            'classify'  => __('Classification', 'ticketassistant'),
            'article'   => __('Knowledge base article', 'ticketassistant'),
            'apply'     => __('Classification applied', 'ticketassistant'),
            'kb_create' => __('Article created', 'ticketassistant'),
            'distill'   => __('Learning (distillation)', 'ticketassistant'),
            'embed'     => __('Embeddings', 'ticketassistant'),
            'triage'    => __('Automatic triage', 'ticketassistant'),
            'test'      => __('Connection test', 'ticketassistant'),
        ];
    }

    private static function byAction(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'SELECT'  => [
                'action',
                Compat::expr('SUM(CASE WHEN ' . self::q('status') . " = 'ok' THEN 1 ELSE 0 END) AS ok_count"),
                Compat::expr('COALESCE(SUM(' . self::q('cost_usd') . '), 0) AS cost'),
            ],
            'FROM'    => self::TABLE,
            'WHERE'   => [['date_creation' => ['>=', self::since(30)]]],
            'GROUPBY' => 'action',
            'ORDER'   => 'cost DESC',
        ]);
        $labels = self::actionLabels();
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . Text::e(__('Per action (30 days)', 'ticketassistant')) . '</h3></div>';
        echo "<div class='card-body p-0'><table class='table table-sm mb-0'><thead><tr><th>" . Text::e(__('Action', 'ticketassistant')) . "</th><th class='text-end'>OK</th><th class='text-end'>"
            . Text::e(__('Cost', 'ticketassistant')) . '</th></tr></thead><tbody>';
        foreach ($it as $row) {
            echo '<tr><td>' . Text::e($labels[$row['action']] ?? $row['action']) . "</td><td class='text-end'>" . (int) $row['ok_count']
                . "</td><td class='text-end'>US$ " . number_format((float) $row['cost'], 2) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function byStatus(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'SELECT'  => ['status', Compat::expr('COUNT(*) AS cnt')],
            'FROM'    => self::TABLE,
            'WHERE'   => [['date_creation' => ['>=', self::since(30)]]],
            'GROUPBY' => 'status',
            'ORDER'   => 'cnt DESC',
        ]);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . Text::e(__('Per result (30 days)', 'ticketassistant')) . '</h3></div>';
        echo "<div class='card-body p-0'><table class='table table-sm mb-0'><tbody>";
        foreach ($it as $row) {
            $status = (string) $row['status'];
            $badge  = $status === 'ok' ? 'bg-green-lt' : (strpos($status, 'blocked') === 0 ? 'bg-yellow-lt' : (strpos($status, 'error') === 0 ? 'bg-red-lt' : 'bg-secondary-lt'));
            echo "<tr><td><span class='badge " . $badge . "'>" . Text::e($status) . "</span></td><td class='text-end'>" . (int) $row['cnt'] . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function byModel(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'SELECT'  => [
                'provider',
                'model',
                Compat::expr('COUNT(*) AS cnt'),
                Compat::expr('COALESCE(SUM(' . self::q('prompt_tokens') . ' + ' . self::q('completion_tokens') . '), 0) AS tokens'),
                Compat::expr('COALESCE(SUM(' . self::q('cost_usd') . '), 0) AS cost'),
            ],
            'FROM'    => self::TABLE,
            'WHERE'   => ['status' => 'ok', ['date_creation' => ['>=', self::since(30)]], ['NOT' => ['model' => '']]],
            'GROUPBY' => ['provider', 'model'],
            'ORDER'   => 'cnt DESC',
            'LIMIT'   => 10,
        ]);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . Text::e(__('Per model (30 days)', 'ticketassistant')) . '</h3></div>';
        echo "<div class='card-body p-0'><table class='table table-sm mb-0'><thead><tr><th>" . Text::e(__('Model', 'ticketassistant')) . "</th><th class='text-end'>"
            . Text::e(__('Calls', 'ticketassistant')) . "</th><th class='text-end'>" . Text::e(__('tokens', 'ticketassistant')) . "</th><th class='text-end'>" . Text::e(__('Cost', 'ticketassistant')) . '</th></tr></thead><tbody>';
        foreach ($it as $row) {
            echo '<tr><td>' . Text::e($row['model']) . " <span class='text-muted'>(" . Text::e($row['provider']) . ")</span></td><td class='text-end'>" . (int) $row['cnt']
                . "</td><td class='text-end'>" . number_format((int) $row['tokens']) . "</td><td class='text-end'>US$ " . number_format((float) $row['cost'], 2) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function topUsers(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'SELECT'    => [
                self::TABLE . '.users_id',
                'glpi_users.firstname',
                'glpi_users.realname',
                Compat::expr('COUNT(*) AS cnt'),
                Compat::expr('COALESCE(SUM(' . $DB->quoteName(self::TABLE . '.cost_usd') . '), 0) AS cost'),
            ],
            'FROM'      => self::TABLE,
            'LEFT JOIN' => ['glpi_users' => ['ON' => [self::TABLE => 'users_id', 'glpi_users' => 'id']]],
            'WHERE'     => [
                self::TABLE . '.status' => 'ok',
                self::TABLE . '.action' => Log::INTERACTIVE_ACTIONS,
                [self::TABLE . '.date_creation' => ['>=', self::since(30)]],
            ],
            'GROUPBY'   => [self::TABLE . '.users_id', 'glpi_users.firstname', 'glpi_users.realname'],
            'ORDER'     => 'cnt DESC',
            'LIMIT'     => 10,
        ]);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . Text::e(__('Most active technicians (30 days)', 'ticketassistant')) . '</h3></div>';
        echo "<div class='card-body p-0'><table class='table table-sm mb-0'><thead><tr><th>" . Text::e(__('Technician', 'ticketassistant')) . "</th><th class='text-end'>"
            . Text::e(__('Generations', 'ticketassistant')) . "</th><th class='text-end'>" . Text::e(__('Cost', 'ticketassistant')) . '</th></tr></thead><tbody>';
        foreach ($it as $row) {
            $name = trim(Text::toPlainText((string) ($row['firstname'] ?? ''), 80) . ' ' . Text::toPlainText((string) ($row['realname'] ?? ''), 80));
            if ($name === '') {
                $name = sprintf(__('User #%d', 'ticketassistant'), (int) $row['users_id']);
            }
            echo '<tr><td>' . Text::e($name) . "</td><td class='text-end'>" . (int) $row['cnt'] . "</td><td class='text-end'>US$ " . number_format((float) $row['cost'], 2) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function topCategories(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'SELECT'    => ['glpi_itilcategories.completename', Compat::expr('COUNT(*) AS cnt')],
            'FROM'      => self::TABLE,
            'LEFT JOIN' => [
                'glpi_tickets'        => ['ON' => [self::TABLE => 'tickets_id', 'glpi_tickets' => 'id']],
                'glpi_itilcategories' => ['ON' => ['glpi_tickets' => 'itilcategories_id', 'glpi_itilcategories' => 'id']],
            ],
            'WHERE'     => [
                self::TABLE . '.status' => 'ok',
                self::TABLE . '.action' => 'analyze',
                [self::TABLE . '.date_creation' => ['>=', self::since(30)]],
            ],
            'GROUPBY'   => 'glpi_itilcategories.completename',
            'ORDER'     => 'cnt DESC',
            'LIMIT'     => 10,
        ]);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title mb-0'>" . Text::e(__('Most analyzed categories (30 days)', 'ticketassistant')) . '</h3></div>';
        echo "<div class='card-body p-0'><table class='table table-sm mb-0'><thead><tr><th>" . Text::e(__('Category', 'ticketassistant')) . "</th><th class='text-end'>"
            . Text::e(__('Analyses', 'ticketassistant')) . '</th></tr></thead><tbody>';
        $any = false;
        foreach ($it as $row) {
            $any  = true;
            $name = $row['completename'] !== null ? Text::toPlainText((string) $row['completename'], 200) : __('(no category)', 'ticketassistant');
            echo "<tr><td class='text-truncate' style='max-width: 380px;'>" . Text::e($name) . "</td><td class='text-end'>" . (int) $row['cnt'] . '</td></tr>';
        }
        if (!$any) {
            echo "<tr><td colspan='2' class='text-muted'>" . Text::e(__('No analysis in the period.', 'ticketassistant')) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
}
