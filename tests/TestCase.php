<?php

declare(strict_types=1);

namespace Assistly\Channel\Tests;

use Assistly\Channel\AssistlyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AssistlyServiceProvider::class];
    }
}
