<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Console;

use CommonITILObject;
use DbUtils;
use Document;
use Entity;
use GlpiPlugin\Ticketassistant\Compat;
use ITILCategory;
use ITILFollowup;
use ITILSolution;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Ticket;
use TicketTask;
use User;

/**
 * php bin/console plugins:ticketassistant:demo [--yes]
 *
 * Creates a self-contained demonstration data set in a dedicated entity
 * ("AI Ticket Assistant demo"): ITIL categories, 40 solved tickets to learn
 * from and 3 open tickets (one with a screenshot, a PDF and a followup with
 * an attachment). Meant for test and demo instances: delete the entity to
 * remove everything.
 */
class DemoCommand extends Command
{
    private const ENTITY = 'AI Ticket Assistant demo';

    /** category => [is_incident, is_request] */
    private const CATEGORIES = [
        'Hardware > Printer'     => [1, 0],
        'Hardware > Laptop'      => [1, 0],
        'Access > Password reset' => [0, 1],
        'Access > New account'   => [0, 1],
        'Network > VPN'          => [1, 0],
        'Network > Wi-Fi'        => [1, 0],
        'Software > E-mail'      => [1, 1],
        'Software > Installation' => [0, 1],
    ];

    /** category => list of [title, description, solution] */
    private const SOLVED = [
        'Hardware > Printer' => [
            ['Printer on the 2nd floor does not print', 'Jobs stay in the queue of the HP printer of the 2nd floor, nothing comes out.', 'Restarted the print spooler on the print server and cleared the stuck jobs. Printing works again.'],
            ['Paper jam in the HR printer', 'The HR printer shows "paper jam in tray 2" but there is no paper inside.', 'Removed a torn piece of paper behind tray 2 and cleaned the rollers.'],
            ['Printer prints blank pages', 'Every page comes out blank from the accounting printer.', 'The toner cartridge was empty although the counter said 20%. Replaced the toner.'],
            ['Printer shows error 49.4C02', 'The reception printer displays error 49.4C02 and reboots.', 'Firmware error caused by a corrupted job: updated the firmware and deleted the job.'],
            ['Cannot add the network printer', 'The new laptop cannot find the printer of the meeting room.', 'Installed the printer from \\\\printserver with the right driver package.'],
        ],
        'Hardware > Laptop' => [
            ['Laptop does not turn on', 'My laptop does not start since yesterday, the power LED stays off.', 'The power adapter was faulty. Replaced the adapter, the battery charges again.'],
            ['Laptop battery drains very fast', 'The battery lasts less than one hour.', 'Battery health at 41%: replaced the battery under warranty.'],
            ['Laptop screen flickering', 'The screen flickers when I move the lid.', 'Loose display cable: sent to the supplier, repaired under warranty.'],
            ['Keyboard keys not working', 'Several keys (E, R, T) of my laptop keyboard do not respond.', 'Liquid damage: replaced the keyboard.'],
            ['Laptop very slow at startup', 'It takes 10 minutes to reach the desktop.', 'Disabled heavy startup programs and replaced the old HDD by an SSD.'],
        ],
        'Access > Password reset' => [
            ['Reset my password', 'I forgot my network password and cannot log in.', 'Password reset in the directory; the user changed it at the next logon.'],
            ['Account locked after failed logins', 'My account is locked, it says too many attempts.', 'Unlocked the account in the directory and reminded the password policy.'],
            ['Forgot the ERP password', 'I cannot access the ERP, I do not remember the password.', 'Reset the ERP password and sent the temporary password by phone.'],
            ['Password expired, cannot change it', 'The system asks for a new password but refuses all of them.', 'Explained the complexity rules (12 characters, no reuse); the user set a valid password.'],
            ['Reset password of the HR mailbox', 'We need to reset the password of the shared HR mailbox.', 'Shared mailboxes have no password: granted the manager full access to the mailbox.'],
        ],
        'Access > New account' => [
            ['Account for a new employee', 'A new analyst starts on Monday in the finance team and needs an account.', 'Created the account, added to the Finance groups and e-mailed the welcome guide.'],
            ['Access for an intern', 'Please create an account for the intern who arrives next week.', 'Created a temporary account valid until the end of the internship.'],
            ['New account for a contractor', 'The contractor of the network project needs access to the VPN.', 'Created a contractor account with VPN access and an expiry date.'],
            ['Create an e-mail account for a new manager', 'The new procurement manager needs an e-mail address.', 'Created the mailbox and added it to the procurement distribution list.'],
            ['Access to the finance shared folder', 'I need access to the finance shared folder for the budget.', 'Added the user to the FINANCE-READ group after approval of the manager.'],
        ],
        'Network > VPN' => [
            ['VPN does not connect from home', 'Since this morning the VPN client says the connection failed.', 'The certificate of the user had expired: renewed the certificate.'],
            ['VPN disconnects every 5 minutes', 'The VPN connection drops every few minutes at home.', 'Home router with an old firmware: switched the client to TCP mode.'],
            ['Error 809 when connecting to the VPN', 'Windows shows error 809, the VPN connection cannot be established.', 'The hotel network blocked IPsec: told the user to use the SSL VPN profile.'],
            ['VPN connected but no access to the intranet', 'The VPN says connected but intranet sites do not open.', 'Split tunnel route missing: pushed the updated VPN profile.'],
            ['Cannot install the VPN client', 'The installation of the VPN client fails with an error.', 'Uninstalled the old client and installed the new version as administrator.'],
        ],
        'Network > Wi-Fi' => [
            ['No Wi-Fi in the meeting room', 'The corporate Wi-Fi does not appear in meeting room B.', 'The access point of room B was down: rebooted it from the controller.'],
            ['Wi-Fi asks for the password every day', 'My laptop asks for the Wi-Fi password every morning.', 'Removed the old saved profile and reconnected with the certificate.'],
            ['Slow Wi-Fi on the 3rd floor', 'The Wi-Fi is very slow on the 3rd floor since Monday.', 'Channel interference: changed the channel plan of the 3rd floor access points.'],
            ['Guest Wi-Fi for visitors', 'Visitors cannot connect to the guest Wi-Fi.', 'The captive portal certificate had expired: renewed it.'],
            ['Phone does not connect to the Wi-Fi', 'My corporate phone does not connect to the Wi-Fi anymore.', 'Reinstalled the Wi-Fi profile with the mobile management tool.'],
        ],
        'Software > E-mail' => [
            ['Cannot send e-mails', 'Outlook keeps the messages in the outbox.', 'The mailbox was full (50 GB): archived old messages, sending works again.'],
            ['E-mails go to spam', 'Messages to our customers go to their spam folder.', 'Added the missing DKIM record to the domain.'],
            ['Shared calendar not visible', 'I cannot see the calendar of my team anymore.', 'Re-added the calendar permissions after the group change.'],
            ['Outlook asks for the password all the time', 'Outlook keeps asking for credentials.', 'Cleared the cached credentials and enabled modern authentication.'],
            ['Mailbox full', 'I receive warnings that my mailbox is full.', 'Enabled the online archive and moved messages older than 2 years.'],
        ],
        'Software > Installation' => [
            ['Install a PDF editor', 'I need a PDF editor to sign documents.', 'Installed the approved PDF editor from the software center.'],
            ['Install Python for data analysis', 'Please install Python on my computer for data analysis.', 'Installed Python 3.12 and the data analysis packages from the internal mirror.'],
            ['Need a license of the drawing tool', 'I need the diagram tool for the architecture project.', 'Assigned a license and installed the tool.'],
            ['Install the statistics software', 'The statistics software is required for the survey project.', 'Installed the software with the campus license.'],
            ['Update the browser', 'The browser is outdated and some sites do not work.', 'Updated the browser through the deployment tool.'],
        ],
    ];

    protected function configure(): void
    {
        $this->setName('plugins:ticketassistant:demo')
            ->setDescription('Creates demonstration data (dedicated entity, categories, solved and open tickets) for test instances')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'GLPI user (super-admin) acting as the author of the data', 'glpi')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('yes')) {
            $question = new ConfirmationQuestion('Create the demonstration data in the entity "' . self::ENTITY . '"? [y/N] ', false);
            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                return 1;
            }
        }

        // GLPI refuses solutions without a user session (same approach as
        // "php bin/console plugin:install --username=...")
        if (!$this->loadUserSession((string) $input->getOption('username'))) {
            $output->writeln('<error>Unknown user: ' . $input->getOption('username') . '</error>');
            return 1;
        }

        $entity_id = $this->entity();
        \Session::changeActiveEntities($entity_id, true);
        if ((new DbUtils())->countElementsInTable(Ticket::getTable(), ['entities_id' => $entity_id]) > 0) {
            $output->writeln('<comment>The entity "' . self::ENTITY . '" already contains tickets: delete it to create the demonstration again.</comment>');
            return 1;
        }
        $output->writeln('Entity: ' . $entity_id);

        $users      = [
            $this->user('demo.maria', 'Maria', 'Silva', 'pt_BR'),
            $this->user('demo.john', 'John', 'Smith', 'en_GB'),
            $this->user('demo.ana', 'Ana', 'Costa', 'pt_BR'),
            $this->user('demo.paul', 'Paul', 'Martin', 'fr_FR'),
        ];
        $categories = $this->categories($entity_id);
        $output->writeln(count($categories) . ' categories');

        // Solved tickets spread over the last 120 days (oldest first), the
        // categories interleaved as in a real history: the temporal evaluation
        // then finds older examples for every ticket but the first of each category
        $created = 0;
        $day     = 120;
        $rounds  = max(array_map('count', self::SOLVED));
        for ($index = 0; $index < $rounds; $index++) {
            foreach (self::SOLVED as $category => $tickets) {
                if (!isset($tickets[$index])) {
                    continue;
                }
                [$title, $description, $solution] = $tickets[$index];
                $type = self::CATEGORIES[$category][0] ? Ticket::INCIDENT_TYPE : Ticket::DEMAND_TYPE;
                $id   = $this->ticket($entity_id, $categories[$category], $type, $title, $description, $users[$created % count($users)]);
                (new ITILSolution())->add(Compat::itemInput([
                    'itemtype' => Ticket::class,
                    'items_id' => $id,
                    'content'  => '<p>' . htmlspecialchars($solution) . '</p>',
                ]));
                $this->backdate($id, $day, CommonITILObject::CLOSED);
                $day -= 2;
                $created++;
            }
        }
        $output->writeln($created . ' solved tickets');

        // Open tickets for the demonstration
        $vpn = $this->ticket(
            $entity_id,
            0,
            Ticket::INCIDENT_TYPE,
            'VPN error 809 since this morning',
            '<p>Hello, since this morning I cannot connect to the VPN from the hotel. Windows shows an error (screenshot attached). I have a presentation at 2 pm and need the files of the shared drive.</p><p>Password: Summer2026! (in case you need it)</p>',
            $users[0],
        );
        $this->attach($vpn, Ticket::class, $entity_id, 'vpn-error.png', $this->screenshot());
        $this->attach($vpn, Ticket::class, $entity_id, 'network-change-notice.pdf', $this->pdf());
        $followup = (int) (new ITILFollowup())->add(Compat::itemInput([
            'itemtype' => Ticket::class,
            'items_id' => $vpn,
            'users_id' => $users[0],
            'content'  => '<p>I also tried from my phone hotspot, same error. The VPN log is attached.</p>',
        ]));
        $this->attach($followup, ITILFollowup::class, $entity_id, 'vpn-client-log.txt', $this->log());
        (new TicketTask())->add(Compat::itemInput([
            'tickets_id' => $vpn,
            'content'    => '<p>Checked the VPN gateway: no IPsec negotiation from this user since 08:10.</p>',
            'is_private' => 1,
            'state'      => \Planning::DONE,
        ]));

        $printer = $this->ticket(
            $entity_id,
            $categories['Hardware > Printer'],
            Ticket::INCIDENT_TYPE,
            'Pages come out blank from the finance printer',
            '<p>All the pages printed on the finance printer are blank since the toner was changed yesterday.</p>',
            $users[1],
        );
        $access = $this->ticket(
            $entity_id,
            0,
            Ticket::DEMAND_TYPE,
            'Access to the budget folder for the new analyst',
            '<p>Our new analyst needs access to the finance shared folder to prepare the budget. The manager of the department already agreed.</p>',
            $users[2],
        );
        $output->writeln(sprintf('<info>Open tickets: #%d (VPN, screenshot + PDF + log), #%d (printer), #%d (access, no category)</info>', $vpn, $printer, $access));
        $output->writeln('Next: php bin/console plugins:ticketassistant:sync');
        return 0;
    }

    private function loadUserSession(string $username): bool
    {
        $user = new User();
        if (!$user->getFromDBbyName($username)) {
            return false;
        }
        $language = $_SESSION['glpilanguage'] ?? 'en_GB';
        $mode     = $_SESSION['glpi_use_mode'] ?? 0;
        $auth                = new \Auth();
        $auth->auth_succeded = true;
        $auth->user          = $user;
        \Session::init($auth);
        $_SESSION['glpilanguage']  = $language;
        $_SESSION['glpi_use_mode'] = $mode;
        return true;
    }

    private function entity(): int
    {
        $entity = new Entity();
        if ($entity->getFromDBByCrit(['name' => self::ENTITY])) {
            return (int) $entity->getID();
        }
        return (int) $entity->add(Compat::itemInput(['name' => self::ENTITY, 'entities_id' => 0]));
    }

    private function user(string $login, string $first, string $last, string $language): int
    {
        $user = new User();
        if ($user->getFromDBByCrit(['name' => $login])) {
            return (int) $user->getID();
        }
        // Demonstration requesters: no password, they cannot log in
        return (int) $user->add(Compat::itemInput([
            'name'      => $login,
            'firstname' => $first,
            'realname'  => $last,
            'language'  => $language,
        ]));
    }

    /**
     * @return array<string, int> complete name => id
     */
    private function categories(int $entity_id): array
    {
        $ids = [];
        foreach (self::CATEGORIES as $complete => [$incident, $request]) {
            [$parent_name, $name] = explode(' > ', $complete);
            $parent = $this->category($parent_name, 0, $entity_id, 1, 1);
            $ids[$complete] = $this->category($name, $parent, $entity_id, $incident, $request);
        }
        return $ids;
    }

    private function category(string $name, int $parent, int $entity_id, int $incident, int $request): int
    {
        $category = new ITILCategory();
        if ($category->getFromDBByCrit(['name' => $name, 'itilcategories_id' => $parent, 'entities_id' => $entity_id])) {
            return (int) $category->getID();
        }
        return (int) $category->add(Compat::itemInput([
            'name'              => $name,
            'itilcategories_id' => $parent,
            'entities_id'       => $entity_id,
            'is_recursive'      => 1,
            'is_incident'       => $incident,
            'is_request'        => $request,
            'is_helpdeskvisible' => 1,
        ]));
    }

    private function ticket(int $entity_id, int $category, int $type, string $title, string $content, int $requester): int
    {
        return (int) (new Ticket())->add(Compat::itemInput([
            'name'                 => $title,
            'content'              => strpos($content, '<p>') === 0 ? $content : '<p>' . htmlspecialchars($content) . '</p>',
            'entities_id'          => $entity_id,
            'itilcategories_id'    => $category,
            'type'                 => $type,
            '_users_id_requester'  => $requester,
            '_disablenotif'        => true,
        ]));
    }

    private function backdate(int $tickets_id, int $days_ago, int $status): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $opened = date('Y-m-d H:i:s', strtotime("-{$days_ago} days 09:00"));
        $solved = date('Y-m-d H:i:s', strtotime("-{$days_ago} days 15:00"));
        // date_mod stays at the creation time: an incremental synchronization of
        // an existing library takes the tickets changed since the last one
        $DB->update('glpi_tickets', [
            'date'          => $opened,
            'date_creation' => $opened,
            'solvedate'     => $solved,
            'closedate'     => $solved,
            'status'        => $status,
        ], ['id' => $tickets_id]);
        $DB->update('glpi_itilsolutions', ['date_creation' => $solved], ['itemtype' => Ticket::class, 'items_id' => $tickets_id]);
    }

    private function attach(int $items_id, string $itemtype, int $entity_id, string $filename, string $content): void
    {
        $tmp_name = uniqid('ticketassistant_demo_', true) . '_' . $filename;
        file_put_contents(GLPI_TMP_DIR . '/' . $tmp_name, $content);
        (new Document())->add(Compat::itemInput([
            'name'              => $filename,
            'entities_id'       => $entity_id,
            'itemtype'          => $itemtype,
            'items_id'          => $items_id,
            '_filename'         => [$tmp_name],
            '_prefix_filename'  => [substr($tmp_name, 0, strlen($tmp_name) - strlen($filename))],
        ]));
    }

    /**
     * Fake Windows error dialog drawn with GD.
     */
    private function screenshot(): string
    {
        $image = imagecreatetruecolor(720, 300);
        $white = imagecolorallocate($image, 255, 255, 255);
        $grey  = imagecolorallocate($image, 230, 230, 230);
        $blue  = imagecolorallocate($image, 0, 90, 158);
        $black = imagecolorallocate($image, 20, 20, 20);
        $red   = imagecolorallocate($image, 200, 30, 30);
        imagefill($image, 0, 0, $grey);
        imagefilledrectangle($image, 20, 20, 700, 280, $white);
        imagefilledrectangle($image, 20, 20, 700, 56, $blue);
        imagestring($image, 5, 34, 30, 'Corporate VPN - Connection failed', $white);
        imagefilledellipse($image, 70, 120, 50, 50, $red);
        imagestring($image, 5, 64, 112, 'X', $white);
        imagestring($image, 5, 110, 95, 'Error 809', $black);
        imagestring($image, 4, 110, 125, 'The network connection between your computer and the VPN', $black);
        imagestring($image, 4, 110, 145, 'server could not be established because the remote server', $black);
        imagestring($image, 4, 110, 165, 'is not responding (IKE/IPsec ports UDP 500 and 4500).', $black);
        imagerectangle($image, 590, 230, 680, 262, $black);
        imagestring($image, 4, 622, 239, 'OK', $black);
        ob_start();
        imagepng($image);
        imagedestroy($image);
        return (string) ob_get_clean();
    }

    /**
     * Minimal one-page PDF with a text layer (no library needed).
     */
    private function pdf(): string
    {
        $lines = [
            'Network change notice - NC-2026-118',
            'From October 12, the hotel and guest networks of our partners block',
            'IPsec traffic (UDP 500/4500). Remote users must use the SSL VPN',
            'profile "Corporate-SSL" available in the VPN client since version 5.2.',
            'Contact the service desk to receive the profile.',
        ];
        $text = "BT /F1 12 Tf 50 780 Td 16 TL\n";
        foreach ($lines as $line) {
            $text .= '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line) . ") Tj T*\n";
        }
        $text .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($text) . " >>\nstream\n" . $text . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf      .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    private function log(): string
    {
        return "2026-10-14 08:10:02 INFO  Connecting to vpn.example.org (IKEv2)\n"
            . "2026-10-14 08:10:07 WARN  No response to IKE_SA_INIT (UDP 500)\n"
            . "2026-10-14 08:10:17 WARN  No response to IKE_SA_INIT (UDP 4500)\n"
            . "2026-10-14 08:10:27 ERROR Connection failed: error 809 (remote server not responding)\n";
    }
}
