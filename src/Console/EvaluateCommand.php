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
use GlpiPlugin\Ticketassistant\Knowledge\Classifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * php bin/console plugins:ticketassistant:evaluate [--sample=200] [--embeddings]
 *
 * Temporal evaluation of the classification learned from solved tickets
 * (no AI call). The result is also shown on the "Learning" page.
 */
class EvaluateCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:ticketassistant:evaluate')
            ->setDescription('Measures how well solved tickets predict the category of new ones (no AI call)')
            ->addOption('sample', 's', InputOption::VALUE_REQUIRED, 'Recent solved tickets to evaluate', '200')
            ->addOption('embeddings', null, InputOption::VALUE_NONE, 'Use the embeddings instead of the full-text search');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Config::getConfig();
        $result = Classifier::evaluate((int) $input->getOption('sample'), (bool) $input->getOption('embeddings'), (int) $config['classify_neighbors']);
        Config::store(['last_evaluation' => (string) json_encode($result, JSON_UNESCAPED_UNICODE)]);

        $pct = static function ($value): string {
            return number_format(100 * (float) $value, 1) . '%';
        };
        $output->writeln(sprintf('<info>%d tickets evaluated (%s) in %.1f s</info>', $result['sample'], $result['method'], $result['duration_ms'] / 1000));
        $output->writeln('Right category, 1st suggestion : ' . $pct($result['accuracy_top1']));
        $output->writeln('Right category, top 3          : ' . $pct($result['accuracy_top3']));
        $output->writeln('Right type                     : ' . $pct($result['accuracy_type']));
        $output->writeln('Tickets with a suggestion      : ' . $pct($result['coverage']));

        $table = new Table($output);
        $table->setHeaders(['Confidence', 'Tickets', 'Accuracy']);
        foreach ($result['calibration'] as $bucket) {
            $table->addRow([$bucket['label'], $bucket['count'], $bucket['count'] ? $pct($bucket['accuracy']) : '-']);
        }
        $table->render();

        if (count($result['confusions'])) {
            $output->writeln('Most frequent confusions:');
            foreach ($result['confusions'] as $row) {
                $output->writeln(sprintf('  %s -> %s (%d)', $row['actual'], $row['predicted'], $row['count']));
            }
        }
        return 0;
    }
}
