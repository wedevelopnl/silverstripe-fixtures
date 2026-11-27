<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Dev;

use WeDevelop\Fixtures\InvalidFixtureException;
use WeDevelop\Fixtures\LoadedFixtures;

/**
 * What the fixtures task accepts, which files a directory adds and what it reports. The task itself reads the
 * filesystem and writes the output.
 */
final class FixturesTaskRules
{
    public const array ACTIONS = ['load', 'unload', 'reset'];

    /**
     * @param list<string> $files
     * @param list<string> $dirs
     *
     * @return string|null why the options cannot run, or null when they can
     */
    public static function optionProblem(mixed $action, array $files, array $dirs): ?string
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return 'Name an action: --action=load, --action=unload or --action=reset';
        }

        if ($action !== 'unload' && $files === [] && $dirs === []) {
            return 'Name at least one fixture file with --file or directory with --dir';
        }

        return null;
    }

    /**
     * @param list<string> $files
     * @param list<array{string, list<string>|null}> $dirs each directory as given, with the names of the files in
     *                                                     it sorted, or null when there is no directory
     *
     * @return list<string> the files, then each directory's .yml and .yaml files, prefixed with the directory as
     *                      given
     *
     * @throws InvalidFixtureException naming each directory that is missing or holds no fixture file
     */
    public static function paths(array $files, array $dirs): array
    {
        $problems = [];
        foreach ($dirs as [$dir, $names]) {
            if ($names === null) {
                $problems[] = $dir . ': no directory at this path';
                continue;
            }

            $names = array_filter(
                $names,
                static fn (string $name): bool => in_array(pathinfo($name, PATHINFO_EXTENSION), ['yml', 'yaml'], true),
            );
            if ($names === []) {
                $problems[] = $dir . ': no .yml or .yaml file in this directory';
            }

            foreach ($names as $name) {
                $files[] = rtrim($dir, '/') . '/' . $name;
            }
        }

        if ($problems !== []) {
            throw new InvalidFixtureException($problems);
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    public static function reportLines(LoadedFixtures $loaded): array
    {
        $lines = [];
        foreach ($loaded->toArray() as $class => $identifiers) {
            foreach ($identifiers as $identifier => $id) {
                $lines[] = sprintf('%s.%s: %d', $class, $identifier, $id);
            }
        }

        return $lines;
    }
}
