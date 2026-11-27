<?php

declare(strict_types=1);

use SilverStripe\Security\Group;
use WeDevelop\Fixtures\FixtureLoader;

require __DIR__ . '/bootstrap.php';

// Usage: cli.php load <fixture> | cli.php count <id,id,...>
[, $command, $argument] = $argv;
if ($command === 'load') {
    echo json_encode(array_values(FixtureLoader::singleton()->load($argument)->toArray()[Group::class]));
} else {
    echo Group::get()->filter('ID', array_map(intval(...), explode(',', $argument)))->count();
}
