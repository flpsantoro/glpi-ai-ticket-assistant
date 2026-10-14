<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant;

use CommonITILActor;
use CommonITILTask;
use CommonITILValidation;
use Document;
use Document_Item;
use Dropdown;
use ITILCategory;
use ITILFollowup;
use ITILSolution;
use Session;
use Ticket;
use TicketTask;
use User;

/**
 * Serializes a ticket for the AI: text (header, description, history, tasks,
 * solutions, extracted documents) and images.
 *
 * Privacy:
 *  - people are named from first name + last name, never from the login
 *    (which is a personal identifier in many organizations);
 *  - private followups and tasks follow the configured policy (by default,
 *    only what the current user is allowed to see) and are flagged;
 *  - the whole text, file names included, goes through Privacy::mask().
 */
final class TicketContext
{
    /** Private items: what the user can see / everything / nothing */
    public const PRIVATE_RIGHTS = 'rights';
    public const PRIVATE_ALL    = 'all';
    public const PRIVATE_NONE   = 'none';

    /** @var array<int, array{0: string, 1: string}> user id => [first name, full name] */
    private static $user_names = [];

    /**
     * @param Ticket $ticket       loaded ticket
     * @param array  $config       effective plugin configuration
     * @param array  $capabilities ['vision' => bool, 'native_pdf' => bool]
     * @param array  $options      ['private' => policy override, 'attachments' => bool, 'history' => bool]
     *
     * @return array{
     *   text: string,
     *   images: array<int, array{mime: string, data: string, name: string}>,
     *   files: array<int, array{mime: string, data: string, name: string}>,
     *   documents: string[],
     *   other_attachments: string[],
     *   requester_firstname: string,
     *   requester_fullname: string,
     *   requester_language: string,
     *   omitted_followups: int,
     *   category_id: int,
     *   entities_id: int
     * }
     */
    public static function build(Ticket $ticket, array $config, array $capabilities = [], array $options = []): array
    {
        $tickets_id = (int) $ticket->getID();
        $policy     = (string) ($options['private'] ?? ($config['private_items'] ?? self::PRIVATE_RIGHTS));
        $max_chars  = max(4000, (int) ($config['max_context_chars'] ?? 24000));

        // ---- People ---------------------------------------------------------
        $requester_ids = [];
        $requester     = ['', '', ''];
        foreach ($ticket->getUsers(CommonITILActor::REQUESTER) as $row) {
            $users_id = (int) $row['users_id'];
            if ($users_id <= 0) {
                continue;
            }
            $requester_ids[$users_id] = true;
            if ($requester[1] === '') {
                [$first, $full] = self::userName($users_id);
                $requester      = [$first, $full, self::userLanguage($users_id)];
            }
        }
        if (empty($config['send_requester_name'] ?? 1)) {
            $requester = ['', '', $requester[2]];
        }

        // ---- Header + description -------------------------------------------
        $category_id = (int) ($ticket->fields['itilcategories_id'] ?? 0);
        $category    = self::categoryName($category_id);

        $lines   = [];
        $lines[] = '## TICKET #' . $tickets_id;
        $lines[] = 'Title: ' . Text::toPlainText((string) $ticket->fields['name'], 300);
        $lines[] = 'Type: ' . self::plain(Ticket::getTicketTypeName((int) $ticket->fields['type']));
        if ($category !== '') {
            $lines[] = 'Category: ' . $category;
        }
        $lines[] = 'Status: ' . self::plain(Ticket::getStatus((int) $ticket->fields['status']));
        $lines[] = 'Priority: ' . self::plain(Ticket::getPriorityName((int) $ticket->fields['priority']))
            . ' | Urgency: ' . self::plain(Ticket::getUrgencyName((int) $ticket->fields['urgency']))
            . ' | Impact: ' . self::plain(Ticket::getImpactName((int) $ticket->fields['impact']));
        if (!empty($ticket->fields['requesttypes_id'])) {
            $lines[] = 'Source: ' . self::plain(Dropdown::getDropdownName('glpi_requesttypes', (int) $ticket->fields['requesttypes_id']));
        }
        $lines[] = 'Opened: ' . (string) ($ticket->fields['date'] ?? '');
        if ($requester[1] !== '') {
            $lines[] = 'Requester: ' . $requester[1];
        }
        $groups = self::assignedGroups($ticket);
        if (count($groups)) {
            $lines[] = 'Assigned group: ' . implode('; ', $groups);
        }
        $approval = (int) ($ticket->fields['global_validation'] ?? CommonITILValidation::NONE);
        if ($approval !== CommonITILValidation::NONE) {
            $lines[] = 'Approval: ' . self::plain(CommonITILValidation::getStatus($approval));
        }
        if (!empty($config['context_linked_items'] ?? 1)) {
            $items = self::linkedItems($tickets_id);
            if (count($items)) {
                $lines[] = 'Linked items: ' . implode('; ', $items);
            }
        }
        $lines[] = '';
        $lines[] = '### Description';
        $lines[] = Text::toPlainText((string) $ticket->fields['content'], 8000);
        $header  = implode("\n", $lines) . "\n";

        // ---- Visible items --------------------------------------------------
        $see_private_followups = self::canSeePrivate($policy, 'followup');
        $see_private_tasks     = self::canSeePrivate($policy, 'task');
        $current_user          = (int) Session::getLoginUserID();

        // ---- Solutions ------------------------------------------------------
        $solutions_text = '';
        $status_labels  = [
            CommonITILValidation::WAITING  => 'waiting for approval',
            CommonITILValidation::ACCEPTED => 'accepted',
            CommonITILValidation::REFUSED  => 'REFUSED by the requester',
        ];
        $solutions = (new ITILSolution())->find(['itemtype' => Ticket::class, 'items_id' => $tickets_id], ['id ASC']);
        foreach ($solutions as $solution) {
            $content = Text::toPlainText((string) $solution['content'], 1500);
            if ($content === '') {
                continue;
            }
            $status          = $status_labels[(int) $solution['status']] ?? '';
            $solutions_text .= '[' . $solution['date_creation'] . ']' . ($status !== '' ? " ({$status})" : '') . ': ' . $content . "\n";
        }
        if ($solutions_text !== '') {
            $solutions_text = "\n## SOLUTIONS ALREADY PROPOSED\n" . $solutions_text;
        }

        // ---- Tasks ----------------------------------------------------------
        $tasks_text = '';
        $tasks      = (new TicketTask())->find(['tickets_id' => $tickets_id], ['date_creation DESC', 'id DESC'], 10);
        foreach (array_reverse($tasks, true) as $task) {
            $private = !empty($task['is_private']);
            if ($private && !$see_private_tasks && !self::isOwnTask($task, $current_user)) {
                continue;
            }
            $content = Text::toPlainText((string) $task['content'], 800);
            if ($content === '') {
                continue;
            }
            $flags = [];
            if ((int) ($task['state'] ?? 0) === \Planning::DONE) {
                $flags[] = 'done';
            } elseif ((int) ($task['state'] ?? 0) === \Planning::TODO) {
                $flags[] = 'to do';
            }
            if ($private) {
                $flags[] = 'internal note';
            }
            $tasks_text .= '[' . $task['date_creation'] . ']' . (count($flags) ? ' (' . implode(', ', $flags) . ')' : '') . ': ' . $content . "\n";
        }
        if ($tasks_text !== '') {
            $tasks_text = "\n## TASKS\n" . $tasks_text;
        }

        // ---- Followups (newest first within the budget, shown chronologically)
        $history_text = '';
        $omitted      = 0;
        if (($options['history'] ?? true) !== false) {
            $followups = (new ITILFollowup())->find(
                ['itemtype' => Ticket::class, 'items_id' => $tickets_id],
                ['date_creation DESC', 'id DESC'],
            );
            $remaining = max(2000, $max_chars - mb_strlen($header) - mb_strlen($solutions_text) - mb_strlen($tasks_text) - 4000);
            $selected  = [];
            foreach ($followups as $followup) {
                $private = !empty($followup['is_private']);
                if ($private && !$see_private_followups && (int) $followup['users_id'] !== $current_user) {
                    continue;
                }
                $content = Text::toPlainText((string) $followup['content'], 1500);
                if ($content === '') {
                    continue;
                }
                [, $author] = self::userName((int) $followup['users_id']);
                $role       = isset($requester_ids[(int) $followup['users_id']]) ? 'requester' : 'team';
                $line       = '[' . $followup['date_creation'] . '] ' . ($author !== '' ? $author . ' ' : '')
                    . '(' . $role . ($private ? ', internal note' : '') . '): ' . $content . "\n";

                if (mb_strlen($line) <= $remaining) {
                    $selected[] = $line;
                    $remaining -= mb_strlen($line);
                } else {
                    $omitted++;
                }
            }
            if (count($selected) || $omitted) {
                $history_text = "\n## HISTORY (chronological order)\n";
                if ($omitted > 0) {
                    $history_text .= "(+{$omitted} older interactions omitted)\n";
                }
                $history_text .= implode('', array_reverse($selected));
            }
        }

        // ---- Attachments ----------------------------------------------------
        $attachments = ['images' => [], 'files' => [], 'documents' => [], 'texts' => [], 'others' => []];
        if (($options['attachments'] ?? true) !== false) {
            $attachments = Attachments::collect(
                self::attachmentLinks($tickets_id, $see_private_followups, $see_private_tasks, $current_user),
                $config,
                $capabilities,
            );
        }

        $documents_text = '';
        foreach ($attachments['texts'] as $document) {
            $documents_text .= '### ' . $document['name'] . "\n" . $document['text'] . "\n\n";
        }
        if ($documents_text !== '') {
            $documents_text = "\n## ATTACHED DOCUMENTS (extracted text)\n" . $documents_text;
        }

        $files_text = '';
        foreach ($attachments['files'] as $i => $file) {
            $files_text .= ($i + 1) . '. ' . $file['name'] . "\n";
        }
        if ($files_text !== '') {
            $files_text = "\n## ATTACHED FILES (sent as files after this text, in this order)\n" . $files_text;
        }

        $images_text = '';
        foreach ($attachments['images'] as $i => $image) {
            $images_text .= ($i + 1) . '. ' . $image['name'] . "\n";
        }
        if ($images_text !== '') {
            $images_text = "\n## ATTACHED IMAGES (sent after this text, in this order)\n" . $images_text;
        }

        $others_text = '';
        if (count($attachments['others'])) {
            $others_text = "\n## OTHER ATTACHMENTS (not analysed, names only)\n- " . implode("\n- ", $attachments['others']) . "\n";
        }

        $text = $header . $history_text . $tasks_text . $solutions_text . $documents_text . $files_text . $images_text . $others_text;
        $text = Privacy::mask($text, Config::maskDetectors($config), (string) ($config['mask_custom'] ?? ''));

        return [
            'text'                => $text,
            'images'              => $attachments['images'],
            'files'               => $attachments['files'],
            'documents'           => $attachments['documents'],
            'other_attachments'   => $attachments['others'],
            'requester_firstname' => $requester[0],
            'requester_fullname'  => $requester[1],
            'requester_language'  => $requester[2],
            'omitted_followups'   => $omitted,
            'category_id'         => $category_id,
            'entities_id'         => (int) ($ticket->fields['entities_id'] ?? 0),
        ];
    }

    /**
     * Compact text of a ticket for classification and learning: title and
     * description, plus (optionally) solutions; no attachments.
     */
    public static function brief(Ticket $ticket, array $config, bool $with_solution = false, int $max_chars = 3000): string
    {
        $text = Text::toPlainText((string) $ticket->fields['name'], 300) . "\n"
            . Text::toPlainText((string) $ticket->fields['content'], $max_chars);
        if ($with_solution) {
            $solutions = (new ITILSolution())->find(
                ['itemtype' => Ticket::class, 'items_id' => (int) $ticket->getID(), 'NOT' => ['status' => CommonITILValidation::REFUSED]],
                ['id DESC'],
                1,
            );
            foreach ($solutions as $solution) {
                $text .= "\nSolution: " . Text::toPlainText((string) $solution['content'], 2000);
            }
        }
        return Privacy::mask($text, Config::maskDetectors($config), (string) ($config['mask_custom'] ?? ''));
    }

    /**
     * Documents of the ticket and of its followups, tasks and solutions,
     * newest link first, without duplicates.
     *
     * In GLPI a file attached INSIDE a followup, task or solution (including
     * images pasted in the text) is linked to that item, not to the ticket.
     *
     * @return array<int, bool> documents_id => true when it only appears in private items
     */
    public static function attachmentLinks(int $tickets_id, bool $private_followups, bool $private_tasks, int $current_user): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // itemtype => [items_id => is_private]
        $parents = [Ticket::class => [$tickets_id => false]];

        foreach (
            [
                ITILFollowup::class => [['itemtype' => Ticket::class, 'items_id' => $tickets_id], $private_followups, 'users_id'],
                TicketTask::class   => [['tickets_id' => $tickets_id], $private_tasks, 'users_id_tech'],
                ITILSolution::class => [['itemtype' => Ticket::class, 'items_id' => $tickets_id], true, null],
            ] as $itemtype => [$where, $see_private, $owner_field]
        ) {
            $fields = ['id'];
            if ($itemtype !== ITILSolution::class) {
                $fields[] = 'is_private';
                $fields[] = 'users_id';
                if ($owner_field !== 'users_id') {
                    $fields[] = $owner_field;
                }
            }
            $it = $DB->request(['SELECT' => $fields, 'FROM' => $itemtype::getTable(), 'WHERE' => $where]);
            foreach ($it as $row) {
                $private = !empty($row['is_private']);
                if (
                    $private
                    && !$see_private
                    && (int) ($row['users_id'] ?? 0) !== $current_user
                    && ($owner_field === null || (int) ($row[$owner_field] ?? 0) !== $current_user)
                ) {
                    continue;
                }
                $parents[$itemtype][(int) $row['id']] = $private;
            }
        }

        $or = [];
        foreach ($parents as $itemtype => $items) {
            if (count($items)) {
                $or[] = ['itemtype' => $itemtype, 'items_id' => array_keys($items)];
            }
        }
        $it = $DB->request([
            'SELECT' => ['documents_id', 'itemtype', 'items_id'],
            'FROM'   => Document_Item::getTable(),
            'WHERE'  => ['OR' => $or],
            'ORDER'  => 'id DESC',
        ]);

        // A document linked to several items keeps the position of its newest
        // link and is "internal" only when every link is private
        $documents = [];
        foreach ($it as $link) {
            $documents_id             = (int) $link['documents_id'];
            $private                  = $parents[$link['itemtype']][(int) $link['items_id']] ?? false;
            $documents[$documents_id] = ($documents[$documents_id] ?? true) && $private;
        }
        return $documents;
    }

    private static function canSeePrivate(string $policy, string $kind): bool
    {
        if ($policy === self::PRIVATE_ALL) {
            return true;
        }
        if ($policy === self::PRIVATE_NONE) {
            return false;
        }
        if (!Session::getLoginUserID()) {
            return false;
        }
        return $kind === 'followup'
            ? (bool) Session::haveRight('followup', ITILFollowup::SEEPRIVATE)
            : (bool) Session::haveRight('task', CommonITILTask::SEEPRIVATE);
    }

    private static function isOwnTask(array $task, int $users_id): bool
    {
        return $users_id > 0
            && ((int) ($task['users_id'] ?? 0) === $users_id || (int) ($task['users_id_tech'] ?? 0) === $users_id);
    }

    /**
     * @return string[]
     */
    private static function assignedGroups(Ticket $ticket): array
    {
        $names = [];
        foreach ($ticket->getGroups(CommonITILActor::ASSIGN) as $row) {
            $name = self::plain(Dropdown::getDropdownName('glpi_groups', (int) $row['groups_id']));
            if ($name !== '' && $name !== '&nbsp;') {
                $names[] = $name;
            }
        }
        return array_slice($names, 0, 3);
    }

    /**
     * Assets linked to the ticket ("Computer: PC-001").
     *
     * @return string[]
     */
    private static function linkedItems(int $tickets_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $items = [];
        $it    = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => 'glpi_items_tickets',
            'WHERE'  => ['tickets_id' => $tickets_id],
            'LIMIT'  => 5,
        ]);
        foreach ($it as $row) {
            $item = getItemForItemtype((string) $row['itemtype']);
            if ($item && $item->getFromDB((int) $row['items_id'])) {
                $items[] = self::plain($item::getTypeName(1)) . ': ' . self::plain((string) $item->getName());
            }
        }
        return $items;
    }

    public static function categoryName(int $category_id): string
    {
        if ($category_id <= 0) {
            return '';
        }
        $category = new ITILCategory();
        if ($category->getFromDB($category_id)) {
            return Text::toPlainText((string) $category->fields['completename'], 255);
        }
        return '';
    }

    /**
     * Display name: [first name, full name] from firstname + realname,
     * NEVER from the login.
     *
     * @return array{0: string, 1: string}
     */
    public static function userName(int $users_id): array
    {
        if ($users_id <= 0) {
            return ['', ''];
        }
        if (isset(self::$user_names[$users_id])) {
            return self::$user_names[$users_id];
        }
        $first = '';
        $full  = '';
        $user  = new User();
        if ($user->getFromDB($users_id)) {
            $firstname = Text::toPlainText((string) ($user->fields['firstname'] ?? ''), 80);
            $realname  = Text::toPlainText((string) ($user->fields['realname'] ?? ''), 80);
            $full      = trim($firstname . ' ' . $realname);
            $first     = $firstname !== '' ? Text::firstWord($firstname) : '';
            if ($full === '') {
                $full = sprintf(__('User #%d', 'ticketassistant'), $users_id);
            }
        }
        return self::$user_names[$users_id] = [$first, $full];
    }

    private static function userLanguage(int $users_id): string
    {
        $user = new User();
        if ($user->getFromDB($users_id) && !empty($user->fields['language'])) {
            return (string) $user->fields['language'];
        }
        return '';
    }

    /**
     * Full name of the logged-in technician (for the signature): '' when the
     * account has neither first name nor last name.
     */
    public static function currentUserFullname(): string
    {
        $users_id = (int) Session::getLoginUserID();
        [, $full] = self::userName($users_id);
        // userName() falls back to "User #N", which identifies authors in the timeline but is no signature
        return $full === sprintf(__('User #%d', 'ticketassistant'), $users_id) ? '' : $full;
    }

    public static function currentUserFirstname(): string
    {
        [$first] = self::userName((int) Session::getLoginUserID());
        return $first;
    }

    private static function plain($value): string
    {
        return Text::toPlainText((string) $value, 300);
    }
}
