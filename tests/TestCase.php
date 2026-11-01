<?php

namespace Kisantra\Watchpost\Tests;

use Kisantra\Watchpost\WatchpostServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WatchpostServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('watchpost.url', 'https://watchpost.test');
        $app['config']->set('watchpost.token', 'wp_test_token');
        $app['config']->set('watchpost.environments', ['testing']);
        $app['config']->set('watchpost.release', 'a1f9c3e');
        // The package root plays the website: tests/ is "app" code, vendor/ is framework.
        $app['config']->set('watchpost.base_path', dirname(__DIR__));
        $app['config']->set('cache.default', 'array');
        // The web middleware group encrypts cookies.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('w', 32)));
    }
}
