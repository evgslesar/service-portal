<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || filled($app['config']->get('database.connections.sqlite.url'))) {
            throw new \RuntimeException('The local test suite requires an isolated in-memory SQLite database.');
        }

        return $app;
    }

    //
}
