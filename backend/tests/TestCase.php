<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs before setUpTraits(), i.e. before RefreshDatabase can drop anything.
     * Checks the database name Laravel actually resolved (env vars from $_SERVER
     * win over phpunit.xml <env>), and refuses anything that is not *_test.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against [{$database}]: only *_test databases are allowed.");
        }

        return $app;
    }
}
