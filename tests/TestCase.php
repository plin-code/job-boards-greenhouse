<?php

declare(strict_types=1);

namespace PlinCode\JobBoards\Greenhouse\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use PlinCode\JobBoards\Greenhouse\GreenhouseServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [GreenhouseServiceProvider::class];
    }
}
