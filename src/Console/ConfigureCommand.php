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
use GlpiPlugin\Ticketassistant\Llm\Providers;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * php bin/console plugins:ticketassistant:configure [options]
 *
 * Configures the assistant from the command line (used by install.sh and
 * install.ps1). Values go through the validation of the configuration page.
 * The API key is read from the standard input, so that it never appears in
 * the process list or in the shell history:
 *
 *   printf '%s' "$KEY" | php bin/console plugins:ticketassistant:configure \
 *       --provider=openai --model=gpt-5-mini --api-key-stdin --enable --test
 */
class ConfigureCommand extends Command
{
    /** Settings that --set does not change (secrets have their own options) */
    private const RESERVED = ['api_key', 'emb_api_key', 'last_evaluation'];

    protected function configure(): void
    {
        $this->setName('plugins:ticketassistant:configure')
            ->setDescription('Configures the AI assistant (provider, model, key, any setting) and tests the connection')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'AI provider: ' . implode(', ', array_keys(Providers::all())))
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'API address (empty: default address of the provider)')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model name (Azure: deployment name)')
            ->addOption('api-version', null, InputOption::VALUE_REQUIRED, 'Azure API version ("v1" for the v1 API)')
            ->addOption('api-key-stdin', null, InputOption::VALUE_NONE, 'Read the API key from the standard input (asked, hidden, in a terminal)')
            ->addOption('clear-api-key', null, InputOption::VALUE_NONE, 'Remove the saved API key')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable the assistant')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable the assistant')
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Any other setting as key=value, repeatable (see --show), e.g. --set=daily_budget_usd=10')
            ->addOption('show', null, InputOption::VALUE_NONE, 'Print every setting (keys masked)')
            ->addOption('list-models', null, InputOption::VALUE_NONE, 'Print the models offered by the provider, one per line')
            ->addOption('test', null, InputOption::VALUE_NONE, 'Test the connection with the saved settings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $post = [];

        $provider = $input->getOption('provider');
        if ($provider !== null) {
            if (!Providers::exists((string) $provider)) {
                $output->writeln('<error>Unknown provider "' . $provider . '". Valid: ' . implode(', ', array_keys(Providers::all())) . '</error>');
                return 2;
            }
            $post['provider'] = (string) $provider;
        }
        foreach (['base-url' => 'base_url', 'model' => 'model', 'api-version' => 'api_version'] as $option => $key) {
            if ($input->getOption($option) !== null) {
                $post[$key] = (string) $input->getOption($option);
            }
        }

        if ($input->getOption('api-key-stdin')) {
            $key = $this->readKey($input, $output);
            if ($key === null) {
                return 2;
            }
            $post['api_key'] = $key;
        }
        if ($input->getOption('clear-api-key')) {
            $post['_clear_api_key'] = 1;
        }

        if ($input->getOption('enable') && $input->getOption('disable')) {
            $output->writeln('<error>--enable and --disable cannot be used together.</error>');
            return 2;
        }
        if ($input->getOption('enable') || $input->getOption('disable')) {
            $post['_present_enabled'] = 1;
            $post['enabled']          = $input->getOption('enable') ? 1 : 0;
        }

        $known = array_values(array_diff(array_keys(Config::getDefaults()), self::RESERVED));
        foreach ((array) $input->getOption('set') as $pair) {
            $parts = explode('=', (string) $pair, 2);
            $key   = trim($parts[0]);
            if (count($parts) !== 2 || !in_array($key, $known, true)) {
                $output->writeln('<error>Invalid --set "' . $pair . '": expected key=value, key among: ' . implode(', ', $known) . '</error>');
                return 2;
            }
            // The marker tells the validation that a checkbox was submitted
            $post['_present_' . $key] = 1;
            $post[$key]               = $key === 'mask_detectors'
                ? array_values(array_filter(array_map('trim', explode(',', $parts[1]))))
                : $parts[1];
        }

        if (count($post)) {
            foreach (Config::saveFromForm($post) as $warning) {
                $output->writeln('<comment>' . $warning . '</comment>');
            }
            $output->writeln('<info>Configuration saved.</info>', OutputInterface::VERBOSITY_NORMAL);
        }

        $config  = Config::getConfig(true);
        $status  = 0;
        $actions = $input->getOption('show') || $input->getOption('list-models') || $input->getOption('test');

        if ($input->getOption('show') || (!count($post) && !$actions)) {
            $this->show($output, $config);
        }
        if ($input->getOption('list-models')) {
            $result = Diagnostics::listModels($config);
            if ($result['success']) {
                foreach ($result['models'] as $model) {
                    $output->writeln($model, OutputInterface::OUTPUT_RAW);
                }
            } else {
                $output->writeln('<error>' . $result['message'] . '</error>');
                $status = 1;
            }
        }
        if ($input->getOption('test')) {
            @set_time_limit((int) $config['http_timeout'] + 30);
            $result = Diagnostics::testConnection($config);
            $output->writeln($result['success'] ? '<info>' . $result['message'] . '</info>' : '<error>' . $result['message'] . '</error>');
            if (!$result['success']) {
                $status = 1;
            }
        }
        return $status;
    }

    /**
     * API key from the standard input: hidden question in a terminal, first
     * line of the piped data otherwise.
     */
    private function readKey(InputInterface $input, OutputInterface $output): ?string
    {
        $key = '';
        if ($input->isInteractive() && function_exists('stream_isatty') && @stream_isatty(STDIN)) {
            $question = new Question('API key (hidden): ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            try {
                /** @var QuestionHelper $helper */
                $helper = $this->getHelper('question');
                $key    = trim((string) $helper->ask($input, $output, $question));
            } catch (\Throwable $e) {
                $output->writeln('<error>This terminal cannot hide the input: pipe the key instead (printf \'%s\' "$KEY" | php bin/console …).</error>');
                return null;
            }
        } else {
            $line = fgets(STDIN);
            // Some Windows tools prepend a byte order mark to what they pipe
            $key = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', is_string($line) ? $line : ''));
        }
        if ($key === '') {
            $output->writeln('<error>No API key received on the standard input.</error>');
            return null;
        }
        return $key;
    }

    private function show(OutputInterface $output, array $config): void
    {
        $missing = Providers::missingSettings(Config::connectionSettings($config));
        $state   = empty($config['enabled']) ? 'disabled' : (count($missing) ? 'enabled, NOT READY (missing: ' . implode(', ', $missing) . ')' : 'enabled, ready');
        $output->writeln('<info>Assistant: ' . $state . '</info>');

        foreach (Config::getDefaults() as $key => $default) {
            if ($key === 'last_evaluation') {
                continue;
            }
            $value = $config[$key] ?? $default;
            if (in_array($key, Config::SECRETS, true)) {
                $value = (string) $value !== '' ? '******** (saved)' : '(empty)';
            } else {
                $value = str_replace(["\r\n", "\n", "\r"], ' ⏎ ', (string) $value);
                if (mb_strlen($value) > 90) {
                    $value = mb_substr($value, 0, 89) . '…';
                }
            }
            $output->writeln(sprintf('  %-26s %s', $key, $value), OutputInterface::OUTPUT_RAW);
        }
    }
}
