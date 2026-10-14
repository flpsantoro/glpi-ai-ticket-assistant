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
use DbUtils;
use Html;
use Profile as GlpiProfile;
use ProfileRight;
use Session;

/**
 * Right "plugin_ticketassistant" (Read = may use the assistant on tickets),
 * shown in Administration > Profiles > "AI assistant" tab.
 */
class Profile extends GlpiProfile
{
    public const RIGHT = 'plugin_ticketassistant';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && $item->getField('interface') !== 'helpdesk') {
            return __('AI assistant', 'ticketassistant');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile) {
            self::ensureRightRow((int) $item->getID());
            (new self())->showForm((int) $item->getID());
        }
        return true;
    }

    /**
     * @return array<int, array{itemtype: string, label: string, field: string, rights: array}>
     */
    public static function getAllRights($all = false)
    {
        return [
            [
                'itemtype' => TicketHooks::class,
                'label'    => __('Use the AI assistant on tickets (analyze, improve text, classify, write articles)', 'ticketassistant'),
                'field'    => self::RIGHT,
                'rights'   => [READ => __('Read')],
            ],
        ];
    }

    /**
     * Creates the right (value 0) for every profile when it does not exist
     * yet, and loads the value of the active profile in the session. Also
     * bound to the "change_profile" hook.
     */
    public static function initProfile(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ((new DbUtils())->countElementsInTable('glpi_profilerights', ['name' => self::RIGHT]) === 0) {
            // GLPI 11 throws on duplicates: only when the right is missing
            ProfileRight::addProfileRights([self::RIGHT]);
        }

        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            $it = $DB->request([
                'SELECT' => ['rights'],
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => ['profiles_id' => (int) $_SESSION['glpiactiveprofile']['id'], 'name' => self::RIGHT],
            ]);
            foreach ($it as $row) {
                $_SESSION['glpiactiveprofile'][self::RIGHT] = (int) $row['rights'];
            }
        }
    }

    /**
     * Row of the right for a profile created after the installation.
     */
    private static function ensureRightRow(int $profiles_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $exists = (new DbUtils())->countElementsInTable('glpi_profilerights', ['profiles_id' => $profiles_id, 'name' => self::RIGHT]);
        if (!$exists) {
            $DB->insert('glpi_profilerights', ['profiles_id' => $profiles_id, 'name' => self::RIGHT, 'rights' => 0]);
        }
    }

    /**
     * First installation only: grants Read to the standard-interface
     * profiles that already answer tickets (followups on group/all tickets,
     * or ticket update). Upgrades never touch the rights again.
     *
     * @return int number of profiles that received the right
     */
    public static function grantToSolverProfiles(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $followup_mask = Compat::followupAddOthersMask();
        $granted       = 0;

        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles', 'WHERE' => ['interface' => 'central']]) as $profile) {
            $profiles_id = (int) $profile['id'];
            $solver      = false;
            foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $profiles_id, 'name' => ['followup', 'ticket']]]) as $right) {
                if ($right['name'] === 'followup' && ((int) $right['rights'] & $followup_mask)) {
                    $solver = true;
                }
                if ($right['name'] === 'ticket' && ((int) $right['rights'] & UPDATE)) {
                    $solver = true;
                }
            }
            if (!$solver) {
                continue;
            }
            self::ensureRightRow($profiles_id);
            $DB->update('glpi_profilerights', ['rights' => READ], ['profiles_id' => $profiles_id, 'name' => self::RIGHT, 'rights' => 0]);
            if ($DB->affectedRows() > 0) {
                $granted++;
            }
        }
        self::initProfile();
        return $granted;
    }

    public static function removeRights(): void
    {
        ProfileRight::deleteProfileRights([self::RIGHT]);
        unset($_SESSION['glpiactiveprofile'][self::RIGHT]);
    }

    public static function canUse(): bool
    {
        return (bool) Session::haveRight(self::RIGHT, READ);
    }

    /**
     * Rights matrix of the tab.
     */
    public function showForm($ID, array $options = [])
    {
        $profile = new GlpiProfile();
        if (!$profile->getFromDB((int) $ID)) {
            return false;
        }
        $can_edit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);

        echo "<div class='firstbloc'>";
        if ($can_edit) {
            echo "<form method='post' action='" . Text::e($profile->getFormURL()) . "'>";
        }
        if ($profile->getField('interface') === 'central') {
            $profile->displayRightsChoiceMatrix(self::getAllRights(), [
                'canedit'       => $can_edit,
                'default_class' => 'tab_bg_2',
                'title'         => __('GLPI AI Ticket Assistant', 'ticketassistant'),
            ]);
        }
        if ($can_edit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => (int) $ID]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';
        return true;
    }
}
