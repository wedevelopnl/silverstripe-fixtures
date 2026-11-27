<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Dev;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\InvalidFixtureException;
use WeDevelop\Fixtures\LoadedFixtures;
use WeDevelop\Fixtures\ResetRefusedException;

/**
 * Loads, unloads or resets fixtures from sake (`sake tasks:fixtures`) or the browser (`/dev/tasks/fixtures`).
 */
class FixturesTask extends BuildTask
{
    private const array ACTIONS = ['load', 'unload', 'reset'];

    protected static string $commandName = 'fixtures';

    protected string $title = 'Load, unload or reset fixtures';

    protected static string $description = 'Loads fixture files, clears what was loaded (unload), or clears it and loads the files again (reset).';

    public function getOptions(): array
    {
        return [
            new InputOption('action', null, InputOption::VALUE_REQUIRED, 'load, unload, or reset (unload, then load)', null, self::ACTIONS),
            new InputOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'A fixture file, absolute or relative to the project root; repeat it to load several together',
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $action = $input->getOption('action');
        /** @var list<string> $files */
        $files = $input->getOption('file');
        if (!in_array($action, self::ACTIONS, true)) {
            $output->writeln('<error>Name an action: --action=load, --action=unload or --action=reset</error>');

            return Command::INVALID;
        }

        if ($action !== 'unload' && $files === []) {
            $output->writeln('<error>Name at least one fixture file with --file</error>');

            return Command::INVALID;
        }

        $loader = FixtureLoader::singleton();
        try {
            match ($action) {
                'load' => $this->report($loader->load(...$files), $output),
                'reset' => $this->report($loader->reload(...$files), $output),
                'unload' => $loader->reset(),
            };
        } catch (InvalidFixtureException | ResetRefusedException $exception) {
            // One writeln per line: the browser output breaks lines between calls, not at "\n"
            foreach (explode("\n", $exception->getMessage()) as $line) {
                $output->writeln('<error>' . OutputFormatter::escape($line) . '</error>');
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function report(LoadedFixtures $loaded, PolyOutput $output): void
    {
        foreach ($loaded->toArray() as $class => $identifiers) {
            foreach ($identifiers as $identifier => $id) {
                $output->writeln(OutputFormatter::escape(sprintf('%s.%s: %d', $class, $identifier, $id)));
            }
        }
    }
}
