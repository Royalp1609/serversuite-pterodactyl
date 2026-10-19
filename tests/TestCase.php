<?php

namespace ServerSuite\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ServerSuite\Providers\ModManagerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ModManagerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('cache.default', 'array');
        // Encrypted casts (DNS tokens) need an app key.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));

        // Enable all repos, give CurseForge a dummy key so isAvailable() passes
        $app['config']->set('serversuite.repositories.modrinth', true);
        $app['config']->set('serversuite.repositories.curseforge', true);
        $app['config']->set('serversuite.repositories.hangar', true);
        $app['config']->set('serversuite.curseforge_api_key', 'test-key');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
