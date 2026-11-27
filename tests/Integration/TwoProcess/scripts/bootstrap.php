<?php

declare(strict_types=1);

// Boots the harness app outside PHPUnit, on the database its environment names (not a temporary one)
require '/app/vendor/autoload.php';

$kernel = new SilverStripe\Core\CoreKernel('/app');
$kernel->boot();
