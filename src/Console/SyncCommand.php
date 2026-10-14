<?php

/**
 * GLPI AI Ticket Assistant
 *
 * @copyright 2026 Felipe Santoro Alcantara
 * @license   GPL-2.0-or-later
 * @link      https://github.com/flpsantoro/glpi-ai-ticket-assistant
 */

namespace GlpiPlugin\Ticketassistant\Console;

use GlpiPlugin\Ticketassistant\Config;
use GlpiPlugin\Ticketassistant\Knowledge\CaseLibrary;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * php bin/console plugins:ticketassistant:sync [--full]
 */
class SyncCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:ticketassistant:sync')
            ->setDescription('Synchronizes the library of solved tickets used by the AI assistant')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Read every solved ticket again instead of the recent changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $start = microtime(true);
        $stats = CaseLibrary::sync((bool) $input->getOption('full'), Config::getConfig());
        // Same message as the Learning page, in the language of the console (--lang)
        $output->writeln('<info>' . sprintf(
            __('Library synchronized: %1$d added, %2$d updated, %3$d unchanged, %4$d removed.', 'ticketassistant'),
            $stats['added'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['removed']
        ) . '</info>' . sprintf(' (%.1f s)', microtime(true) - $start));
        return 0;
    }
}
