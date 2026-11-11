<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';
        $database = getenv('DB_DATABASE') ?: 'database/testing.sqlite';
        if (($driver === 'sqlite' && $database !== 'database/testing.sqlite')
            || ($driver !== 'sqlite' && $database !== 'xboard_test')) {
            throw new \RuntimeException('Tests require database/testing.sqlite or the dedicated xboard_test database.');
        }
        parent::setUp();
        // Setting invalidation explicitly names the redis cache store. Keep
        // tests isolated from any developer or production Redis instance.
        config()->set('cache.stores.redis', ['driver' => 'array', 'serialize' => false]);
        app('cache')->forgetDriver('redis');
        app()->forgetInstance(\App\Support\Setting::class);
    }
}
