<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Load;

use SilverStripe\Control\Director;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses fixture files into records, reporting every structural problem instead of stopping at the first.
 */
final class FixtureReader
{
    private const string PUBLISHED = '@published';

    private const string LOCALES = '@locales';

    private const string SOURCE = '@source';

    private const string DRAFT = '@draft';

    /**
     * Parsed files for the life of the process, keyed by path; a changed modification time invalidates an entry.
     *
     * @var array<string, array{mtime: int|false, data: mixed}>
     */
    private static array $parsed = [];

    /**
     * @param array<string, string> $files absolute path by the label problems name the file with
     */
    public function read(array $files): FixtureSet
    {
        $records = [];
        $problems = [];
        $declaredIn = [];
        foreach ($files as $label => $path) {
            foreach ($this->readFile($label, $path, $problems) as $record) {
                // The class as SchemaMap resolves it: a leading backslash and the case of a class name change nothing
                $key = strtolower(ltrim($record->class, '\\')) . '.' . $record->identifier;
                if (isset($declaredIn[$key])) {
                    $problems[] = sprintf('%s: is also declared in %s', $record->label(), $declaredIn[$key]);
                    continue;
                }

                $declaredIn[$key] = $label;
                $records[] = $record;
            }
        }

        return new FixtureSet($records, $problems);
    }

    /**
     * @param list<string> $problems
     *
     * @return list<FixtureRecord>
     */
    private function readFile(string $label, string $path, array &$problems): array
    {
        try {
            $data = $this->parse($path);
        } catch (ParseException $exception) {
            $problems[] = sprintf('%s: cannot be parsed: %s', $label, $exception->getMessage());

            return [];
        }

        if ($data === null) {
            return [];
        }

        if (!is_array($data) || array_is_list($data)) {
            $problems[] = $label . ': expected classes at the top level';

            return [];
        }

        $records = [];
        foreach ($data as $class => $body) {
            $records = [...$records, ...$this->readClass($label, $path, (string) $class, $body, $problems)];
        }

        return $records;
    }

    private function parse(string $path): mixed
    {
        $mtime = filemtime($path);
        if (!isset(self::$parsed[$path]) || self::$parsed[$path]['mtime'] !== $mtime) {
            self::$parsed[$path] = ['mtime' => $mtime, 'data' => Yaml::parseFile($path)];
        }

        return self::$parsed[$path]['data'];
    }

    /**
     * @param list<string> $problems
     *
     * @return list<FixtureRecord>
     */
    private function readClass(string $label, string $path, string $class, mixed $body, array &$problems): array
    {
        $where = $label . ', ' . $class;
        if (!is_array($body)) {
            $problems[] = $where . ': expected records by identifier';

            return [];
        }

        // The class-level default applies to every record of the class, wherever it appears in the file
        $published = null;
        $declared = [];
        foreach ($body as $identifier => $values) {
            $identifier = (string) $identifier;
            if ($identifier === self::PUBLISHED) {
                $published = $this->readPublished($where, $values, $problems);
            } elseif ($identifier === self::DRAFT) {
                $problems[] = sprintf('%s: "%s" belongs on a record, not on a class', $where, self::DRAFT);
            } elseif (str_starts_with($identifier, '@')) {
                $problems[] = sprintf('%s: unknown loader key "%s"', $where, $identifier);
            } elseif (preg_match('/\s/', $identifier) === 1) {
                $problems[] = sprintf('%s.%s: an identifier cannot hold whitespace, as a reference could not name it', $where, $identifier);
            } elseif ($values === null || $values === [] || (is_array($values) && !array_is_list($values))) {
                $declared[] = [$identifier, $values ?? []];
            } else {
                $problems[] = sprintf('%s.%s: expected field values by name', $where, $identifier);
            }
        }

        $records = [];
        foreach ($declared as [$identifier, $values]) {
            $records[] = $this->readRecord($label, $path, $class, $identifier, $values, $published, $problems);
        }

        return $records;
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $problems
     */
    private function readRecord(
        string $label,
        string $path,
        string $class,
        string $identifier,
        array $values,
        ?bool $published,
        array &$problems,
    ): FixtureRecord {
        $where = sprintf('%s, %s.%s', $label, $class, $identifier);
        $fields = [];
        $locales = [];
        $source = null;
        $draft = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if ($key === self::PUBLISHED) {
                $published = $this->readPublished($where, $value, $problems) ?? $published;
            } elseif ($key === self::LOCALES) {
                $locales = $this->readLocales($where, $value, $problems);
            } elseif ($key === self::SOURCE) {
                $source = $this->readSource($where, $value, dirname($path), $problems);
            } elseif ($key === self::DRAFT) {
                $draft = $this->readDraft($where, $value, $problems);
            } elseif (str_starts_with($key, '@')) {
                $problems[] = sprintf('%s: unknown loader key "%s"', $where, $key);
            } else {
                $fields[$key] = $value;
            }
        }

        return new FixtureRecord($label, $class, $identifier, $fields, $published, $locales, $source, $draft);
    }

    /**
     * @param list<string> $problems
     */
    private function readPublished(string $where, mixed $value, array &$problems): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $problems[] = sprintf('%s: "%s" must be true or false', $where, self::PUBLISHED);

        return null;
    }

    /**
     * @param list<string> $problems
     *
     * @return array<string, array<string, mixed>>
     */
    private function readLocales(string $where, mixed $value, array &$problems): array
    {
        $locales = [];
        foreach (is_array($value) ? $value : [] as $locale => $fields) {
            if (!is_string($locale) || !is_array($fields) || ($fields !== [] && array_is_list($fields))) {
                $locales = [];
                break;
            }

            $locales[$locale] = array_combine(array_map(strval(...), array_keys($fields)), $fields);
        }

        if ($locales === []) {
            $problems[] = sprintf('%s: "%s" must map a locale code to field values', $where, self::LOCALES);
        }

        return $locales;
    }

    /**
     * @param list<string> $problems
     *
     * @return array<string, mixed>
     */
    private function readDraft(string $where, mixed $value, array &$problems): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            $problems[] = sprintf('%s: "%s" must map field values', $where, self::DRAFT);

            return [];
        }

        return array_combine(array_map(strval(...), array_keys($value)), $value);
    }

    /**
     * A relative source is relative to the fixture file, so a module's fixtures name its files wherever it is
     * installed.
     *
     * @param list<string> $problems
     *
     * @return ?string the absolute path
     */
    private function readSource(string $where, mixed $value, string $directory, array &$problems): ?string
    {
        if (is_string($value) && $value !== '') {
            return Director::is_absolute($value) ? $value : $directory . '/' . $value;
        }

        $problems[] = sprintf('%s: "%s" must be a path', $where, self::SOURCE);

        return null;
    }
}
