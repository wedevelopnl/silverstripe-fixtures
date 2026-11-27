<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\Model;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * @extends Extension<Plain>
 */
class PlainDefaultsExtension extends Extension implements TestOnly
{
    private static array $db = [
        'Note' => 'Varchar(255)',
    ];

    private static array $defaults = [
        'Note' => 'extension default',
    ];
}
