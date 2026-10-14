<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use CommonDBTM;
use CommonGLPI;
use DbUtils;
use Html;
use ITILCategory;

/**
 * Instructions for the AI attached to an ITIL category ("AI assistant" tab
 * of Setup > Dropdowns > ITIL categories): how the team handles that kind
 * of ticket, what to ask, when to escalate. Optionally inherited by the
 * sub-categories.
 */
class CategoryGuideline extends CommonDBTM
{
    public const TABLE = 'glpi_plugin_ticketassistant_categoryguidelines';

    // No $rightname redeclaration: the property is typed (string) in GLPI 12 and
    // untyped in GLPI 10/11, so a redeclaration breaks one of them.

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('AI guideline', 'AI guidelines', $nb, 'ticketassistant');
    }

    public static function canView(): bool
    {
        return (bool) ITILCategory::canView();
    }

    public static function canUpdate(): bool
    {
        return (bool) ITILCategory::canUpdate();
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof ITILCategory && !$item->isNewItem() && ITILCategory::canView()) {
            return __('AI assistant', 'ticketassistant');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof ITILCategory) {
            self::showForCategory($item);
        }
        return true;
    }

    /**
     * @return array{guidelines: string, is_recursive: int}
     */
    public static function getForCategory(int $category_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['itilcategories_id' => $category_id], 'LIMIT' => 1]) as $row) {
            return ['guidelines' => (string) $row['guidelines'], 'is_recursive' => (int) $row['is_recursive']];
        }
        return ['guidelines' => '', 'is_recursive' => 1];
    }

    public static function saveForCategory(int $category_id, string $guidelines, bool $recursive): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $guidelines = trim(mb_substr($guidelines, 0, 8000));
        if ($guidelines === '') {
            $DB->delete(self::TABLE, ['itilcategories_id' => $category_id]);
            return;
        }
        $DB->updateOrInsert(
            self::TABLE,
            [
                'guidelines'   => Compat::dbValue($guidelines),
                'is_recursive' => $recursive ? 1 : 0,
                'date_mod'     => date('Y-m-d H:i:s'),
            ],
            ['itilcategories_id' => $category_id],
        );
    }

    /**
     * Guidelines applying to a ticket of this category: its own, then those
     * of its ancestors marked as inherited (most general first).
     */
    public static function forCategory(int $category_id): string
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($category_id <= 0) {
            return '';
        }
        $ancestors = array_map('intval', array_keys((new DbUtils())->getAncestorsOf(ITILCategory::getTable(), $category_id)));
        $ids       = array_merge($ancestors, [$category_id]);

        $rows = [];
        foreach (
            $DB->request([
                'SELECT'    => [self::TABLE . '.itilcategories_id', self::TABLE . '.guidelines', self::TABLE . '.is_recursive', 'glpi_itilcategories.level', 'glpi_itilcategories.completename'],
                'FROM'      => self::TABLE,
                'INNER JOIN' => [
                    'glpi_itilcategories' => ['ON' => [self::TABLE => 'itilcategories_id', 'glpi_itilcategories' => 'id']],
                ],
                'WHERE'     => [self::TABLE . '.itilcategories_id' => $ids],
                'ORDER'     => 'glpi_itilcategories.level ASC',
            ]) as $row
        ) {
            $own = (int) $row['itilcategories_id'] === $category_id;
            if ($own || !empty($row['is_recursive'])) {
                $rows[] = '[' . Text::toPlainText((string) $row['completename'], 200) . "]\n" . trim((string) $row['guidelines']);
            }
        }
        return implode("\n\n", $rows);
    }

    private static function showForCategory(ITILCategory $category): void
    {
        $current  = self::getForCategory((int) $category->getID());
        $can_edit = ITILCategory::canUpdate();

        echo "<div class='card m-3'><div class='card-body'>";
        echo "<p class='text-muted mb-3'>" . Text::e(__('Instructions added to the AI prompt for tickets of this category: how the team handles them, which information to ask for, which procedure or team applies, when to escalate. Keep them short and concrete.', 'ticketassistant')) . '</p>';

        if ($can_edit) {
            echo "<form method='post' action='" . Text::e(Compat::url('front/categoryguideline.form.php')) . "'>";
            echo Html::hidden('itilcategories_id', ['value' => (int) $category->getID()]);
        }
        echo "<textarea class='form-control mb-2' name='guidelines' rows='8' " . ($can_edit ? '' : 'readonly') . ' placeholder="'
            . Text::e(__('Example: always ask for the asset tag and a screenshot of the error; access requests require the approval of the manager.', 'ticketassistant'))
            . '">' . Text::e($current['guidelines']) . '</textarea>';
        echo "<label class='form-check form-switch mb-3'>";
        echo "<input type='hidden' name='is_recursive' value='0'>";
        echo "<input class='form-check-input' type='checkbox' name='is_recursive' value='1' " . ($current['is_recursive'] ? 'checked' : '') . ' ' . ($can_edit ? '' : 'disabled') . '>';
        echo "<span class='form-check-label'>" . Text::e(__('Also apply to the sub-categories', 'ticketassistant')) . '</span></label>';
        if ($can_edit) {
            echo "<div><button type='submit' name='save' value='1' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>" . Text::e(_x('button', 'Save')) . '</button></div>';
            Html::closeForm();
        }
        echo '</div></div>';
    }
}
