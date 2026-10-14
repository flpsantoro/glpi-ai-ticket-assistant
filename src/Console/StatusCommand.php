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
use GlpiPlugin\Ticketassistant\Diagnostics;
use GlpiPlugin\Ticketassistant\Knowledge\CaseLibrary;
use GlpiPlugin\Ticketassistant\Llm\Providers;
use GlpiPlugin\Ticketassistant\Log;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * php bin/console plugins:ticketassistant:status [--porcelain]
 *
 * State of the assistant: readiness, connection, library of solved tickets,
 * automatic actions and today's cost. --porcelain prints key=value lines for
 * scripts (installers, monitoring).
 */
class StatusCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:ticketassistant:status')
            ->setDescription('Shows the state of the AI assistant (connection, library, automatic actions, cost)')
            ->addOption('porcelain', null, InputOption::VALUE_NONE, 'Machine-readable output: one key=value per line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config   = Config::getConfig(true);
        $settings = Providers::resolve(Config::connectionSettings($config));
        $missing  = Providers::missingSettings(Config::connectionSettings($config));
        $library  = CaseLibrary::stats();
        $crons    = Diagnostics::cronTasks();
        $values   = [
            'version'        => defined('PLUGIN_TICKETASSISTANT_VERSION') ? PLUGIN_TICKETASSISTANT_VERSION : '?',
            'glpi_version'   => defined('GLPI_VERSION') ? GLPI_VERSION : '?',
            'php_version'    => PHP_VERSION,
            'enabled'        => empty($config['enabled']) ? '0' : '1',
            'ready'          => Config::isReady($config) ? '1' : '0',
            'provider'       => $settings['provider'],
            'base_url'       => $settings['base_url'],
            'model'          => $settings['model'],
            'api_key'        => (string) $config['api_key'] !== '' ? '1' : '0',
            'missing'        => implode(',', $missing),
            'cases'          => (string) $library['cases'],
            'distilled'      => (string) $library['distilled'],
            'embedded'       => (string) $library['embedded'],
            'last_sync'      => (string) ($library['last_sync'] ?? ''),
            'cost_today_usd' => number_format(Log::todayCostUSD(Log::INTERACTIVE_ACTIONS), 4, '.', ''),
            'budget_usd'     => (string) $config['daily_budget_usd'],
        ];
        foreach ($crons as $name => $task) {
            $values['cron_' . $name] = ($task['active'] ? 'enabled' : 'disabled') . ',' . ($task['cli'] ? 'cli' : 'glpi') . ',' . ($task['lastrun'] ?? '');
        }

        if ($input->getOption('porcelain')) {
            foreach ($values as $key => $value) {
                $output->writeln($key . '=' . $value, OutputInterface::OUTPUT_RAW);
            }
            return 0;
        }

        $preset = Providers::get($settings['provider']);
        $state  = $values['ready'] === '1'
            ? '<info>enabled and ready</info>'
            : ($values['enabled'] === '1' ? '<error>enabled, NOT READY (missing: ' . $values['missing'] . ')</error>' : '<comment>disabled</comment>');

        $output->writeln(sprintf('<info>GLPI AI Ticket Assistant %s</info> — GLPI %s, PHP %s', $values['version'], $values['glpi_version'], $values['php_version']));
        $output->writeln('State       : ' . $state);
        $output->writeln(sprintf(
            'Connection  : %s, model "%s", key %s',
            (string) $preset['label'],
            $values['model'] !== '' ? $values['model'] : '-',
            $values['api_key'] === '1' ? 'saved' : 'not set'
        ));
        $output->writeln('Address     : ' . ($values['base_url'] !== '' ? $values['base_url'] : '-'));
        $output->writeln(sprintf(
            'Library     : %s solved tickets, %s distilled, %s with embeddings — last synchronization: %s',
            $values['cases'],
            $values['distilled'],
            $values['embedded'],
            $values['last_sync'] !== '' ? $values['last_sync'] : '-'
        ));
        $output->writeln(sprintf(
            'Cost today  : US$ %s (daily budget of the technicians: %s)',
            $values['cost_today_usd'],
            (float) $values['budget_usd'] > 0 ? 'US$ ' . $values['budget_usd'] : 'none'
        ));
        $output->writeln('Automatic actions:');
        foreach ($crons as $name => $task) {
            $output->writeln(sprintf(
                '  %-9s %-8s %-4s last run: %s',
                $name,
                $task['active'] ? 'enabled' : 'disabled',
                $task['cli'] ? 'CLI' : 'GLPI',
                $task['lastrun'] ?? '-'
            ));
        }
        return 0;
    }
}
