<?php

declare(strict_types=1);

use SilverStripe\Security\Group;
use WeDevelop\Fixtures\FixtureLoader;

require __DIR__ . '/bootstrap.php';

// Usage: cli.php load <fixture> | cli.php count <id,id,...> | cli.php reset
[, $command] = $argv;
$argument = $argv[2] ?? '';
if ($command === 'load') {
    echo json_encode(array_values(FixtureLoader::singleton()->load($argument)->toArray()[Group::class]));
} elseif ($command === 'reset') {
    FixtureLoader::singleton()->reset();
} else {
    echo Group::get()->filter('ID', array_map(intval(...), explode(',', $argument)))->count();
}
