<?php

declare(strict_types=1);

namespace Codeception;

use Codeception\Test\Metadata;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestResult;

interface TestInterface extends Test
{
    public function getMetadata(): Metadata;

    public function getTestResultObject(): TestResult;
}
