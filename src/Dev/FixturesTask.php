<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Dev;

use SilverStripe\Control\Director;
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
    protected static string $commandName = 'fixtures';

    protected string $title = 'Load, unload or reset fixtures';

    protected static string $description = 'Loads fixture files, clears what was loaded (unload), or clears it and loads the files again (reset).';

    public function getOptions(): array
    {
        return [
            new InputOption('action', null, InputOption::VALUE_REQUIRED, 'load, unload, or reset (unload, then load)', null, FixturesTaskRules::ACTIONS),
            new InputOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'A fixture file, absolute or relative to the project root; repeat it to load several together',
            ),
            new InputOption(
                'dir',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'A directory whose .yml and .yaml files load together with the --file files, not those of its subdirectories; absolute or relative to the project root, repeatable',
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $action = $input->getOption('action');
        /** @var list<string> $files */
        $files = $input->getOption('file');
        /** @var list<string> $dirs */
        $dirs = $input->getOption('dir');
        $problem = FixturesTaskRules::optionProblem($action, $files, $dirs);
        if ($problem !== null) {
            $output->writeln('<error>' . $problem . '</error>');

            return Command::INVALID;
        }

        /** @var value-of<FixturesTaskRules::ACTIONS> $action checked above */
        $loader = FixtureLoader::singleton();
        try {
            match ($action) {
                'load' => $this->report($loader->load(...$this->paths($files, $dirs)), $output),
                'reset' => $this->report($loader->reload(...$this->paths($files, $dirs)), $output),
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

    /**
     * @param list<string> $files
     * @param list<string> $dirs
     *
     * @return list<string>
     *
     * @throws InvalidFixtureException naming each directory that is missing or holds no fixture file
     */
    private function paths(array $files, array $dirs): array
    {
        $listings = [];
        foreach ($dirs as $dir) {
            $absolute = Director::is_absolute($dir) ? $dir : Director::baseFolder() . '/' . $dir;
            $listings[] = [$dir, is_dir($absolute) ? array_values(array_filter(
                scandir($absolute) ?: [],
                static fn (string $name): bool => is_file($absolute . '/' . $name),
            )) : null];
        }

        return FixturesTaskRules::paths($files, $listings);
    }

    private function report(LoadedFixtures $loaded, PolyOutput $output): void
    {
        foreach (FixturesTaskRules::reportLines($loaded) as $line) {
            $output->writeln(OutputFormatter::escape($line));
        }
    }
}
