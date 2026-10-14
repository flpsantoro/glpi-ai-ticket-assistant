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
use GlpiPlugin\Ticketassistant\Knowledge\Learner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * php bin/console plugins:ticketassistant:learn [--limit=50]
 *
 * Distills pending solved tickets and computes missing embeddings, within
 * the daily budget of automatic actions (same as the automatic action
 * "ailearn", useful for a first bulk run).
 */
class LearnCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:ticketassistant:learn')
            ->setDescription('Learns from solved tickets: AI distillation and embeddings (respects the background budget)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Tickets to distill', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Config::getConfig();
        if (empty($config['learn_enabled']) && empty($config['embeddings_enabled'])) {
            $output->writeln('<comment>Enable the AI distillation and/or the embeddings in the configuration first.</comment>');
            return 1;
        }
        $result = Learner::run($config, max(1, (int) $input->getOption('limit')));
        $output->writeln(sprintf(
            '<info>%d distilled, %d embedded, %d errors, US$ %s</info>%s',
            $result['distilled'],
            $result['embedded'],
            $result['errors'],
            number_format($result['cost_usd'], 4),
            $result['stopped'] === 'budget' ? ' — stopped: daily background budget reached' : ''
        ));
        return $result['errors'] > 0 && ($result['distilled'] + $result['embedded']) === 0 ? 1 : 0;
    }
}
