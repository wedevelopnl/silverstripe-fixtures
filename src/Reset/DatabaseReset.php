<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Reset;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ManyManyThroughList;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\ORM\Queries\SQLSelect;
use WeDevelop\Fixtures\FixtureLedger;

/**
 * Deletes the records the ledger holds and the records of the configured classes, then empties the ledger: reads
 * what LedgerReset asks and runs the deletes it plans. DML only: a schema statement would end an open test
 * transaction.
 */
final class DatabaseReset
{
    /**
     * @param list<class-string<DataObject>> $resetClasses
     *
     * @return list<string> classes whose records were deleted
     */
    public function reset(array $resetClasses): array
    {
        $schema = DataObject::getSchema();
        /** @var array<string, string> $tables */
        $tables = DB::table_list();
        $reset = new LedgerReset($tables);
        $ledgerTable = (string) $schema->tableName(FixtureLedger::class);

        $ledger = SQLSelect::create(['"TableName"', '"FirstID"', '"LastID"'], '"' . $ledgerTable . '"')->execute();
        /** @var array{TableName: string, FirstID: int|string, LastID: int|string} $entry */
        foreach ($ledger as $entry) {
            $class = $schema->tableClass($entry['TableName']);
            $isBaseTable = $class !== null && $schema->baseDataTable($class) === $entry['TableName'];
            $reset->addLedgerEntry($entry['TableName'], $isBaseTable, (int) $entry['FirstID'], (int) $entry['LastID']);
        }

        foreach ($resetClasses as $class) {
            $classNames = array_values(ClassInfo::subclassesFor($class));
            $this->addRecords($reset, $schema->baseDataTable($class), LedgerReset::classCondition($classNames));
        }

        // Join records first: they are deleted as records, with their own chain
        $joins = [];
        foreach ($this->joins($schema) as $join) {
            if ($join['joinBase'] === null) {
                $joins[] = $join;
            } elseif (($references = $reset->references($join)) !== null) {
                $this->addRecords($reset, $join['joinBase'], $references);
            }
        }

        $classNames = [];
        foreach ($reset->classNameReads() as $baseTable => $reads) {
            $classNames[$baseTable] = [];
            foreach ($reads as [$query, $parameters]) {
                /** @var list<string> $found */
                $found = DB::prepared_query($query, $parameters)->column();
                $classNames[$baseTable] = [...$classNames[$baseTable], ...$found];
            }

            $classNames[$baseTable] = array_values(array_unique($classNames[$baseTable]));
        }

        $deleted = array_values(array_unique(array_merge([], ...array_values($classNames))));
        foreach ($reset->deletes($classNames, $this->classTables($schema, $deleted), $joins) as [$table, $where]) {
            SQLDelete::create('"' . $table . '"')->addWhere($where)->execute();
        }

        SQLDelete::create('"' . $ledgerTable . '"')->execute();

        return $deleted;
    }

    /**
     * @param array<string, list<int|string>> $where
     */
    private function addRecords(LedgerReset $reset, string $baseTable, array $where): void
    {
        foreach ($reset->recordTables($baseTable) as $table => $column) {
            /** @var list<int|string> $ids */
            $ids = SQLSelect::create('DISTINCT "' . $column . '"', '"' . $table . '"', $where)->execute()->column();
            $reset->addIds($baseTable, array_map(intval(...), $ids));
        }
    }

    /**
     * Every many_many relation, once per join table or join class: a belongs_many_many shares its many_many's.
     *
     * @return list<array{join: string, joinBase: string|null, parentBase: string|null, childBase: string|null, parentField: string, childField: string}>
     *                                                     joinBase is the join class's base table for a through
     */
    private function joins(DataObjectSchema $schema): array
    {
        $joins = [];
        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            $manyMany = Config::inst()->get($class, 'many_many', Config::UNINHERITED);
            foreach (array_keys(is_array($manyMany) ? $manyMany : []) as $name) {
                /** @var array{relationClass: string, parentClass: class-string<DataObject>, childClass: class-string<DataObject>, parentField: string, childField: string, join: string} $join */
                $join = $schema->manyManyComponent($class, (string) $name);
                $joins[$join['join'] . '.' . $join['parentField'] . '.' . $join['childField']] = [
                    'join' => $join['join'],
                    'joinBase' => $join['relationClass'] === ManyManyThroughList::class ? $schema->baseDataTable($join['join']) : null,
                    'parentBase' => $join['parentClass'] === DataObject::class ? null : $schema->baseDataTable($join['parentClass']),
                    'childBase' => $join['childClass'] === DataObject::class ? null : $schema->baseDataTable($join['childClass']),
                    'parentField' => $join['parentField'],
                    'childField' => $join['childField'],
                ];
            }
        }

        return array_values($joins);
    }

    /**
     * @param list<string> $classNames
     *
     * @return array<string, list<string>> the tables of each class, of which a class removed from the code base
     *                                     has none
     */
    private function classTables(DataObjectSchema $schema, array $classNames): array
    {
        $tables = [];
        foreach ($classNames as $className) {
            $tables[$className] = [];
            foreach (ClassInfo::ancestry($className, true) as $tableClass) {
                $tables[$className][] = (string) $schema->tableName($tableClass);
            }
        }

        return $tables;
    }
}
