<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures;

use RuntimeException;

final class ResetRefusedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Clearing test data only runs in development and test environments.');
    }
}
