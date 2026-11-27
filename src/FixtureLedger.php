<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures;

use SilverStripe\ORM\DataObject;

/**
 * Per load and per table with its own ID sequence, the ID range the load inserted. A table, not a file, so a
 * load and a reset in different processes share it and it rolls back with a test transaction.
 *
 * @property ?string $TableName
 * @property int $FirstID
 * @property int $LastID
 */
class FixtureLedger extends DataObject
{
    private static string $table_name = 'FixtureLedger';

    private static array $db = [
        'TableName' => 'Varchar(255)',
        'FirstID' => 'Int',
        'LastID' => 'Int',
    ];
}
