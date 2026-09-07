<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        if (! $app->environment('testing') || ! preg_match('/(?:^|_)test(?:_|$)/', $database)) {
            throw new \RuntimeException('Tests require APP_ENV=testing and a dedicated database with a test name. Refusing to touch application data.');
        }

        return $app;
    }
}
