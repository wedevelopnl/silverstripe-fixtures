<?php

declare(strict_types=1);

use WeDevelop\Fixtures\FixtureLoader;

require __DIR__ . '/bootstrap.php';

FixtureLoader::singleton()->reset();
echo 'reset by ' . (function_exists('posix_geteuid') ? posix_geteuid() : 'unknown');
