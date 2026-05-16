<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Fixtures;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;

trait InteractsWithCommonplaceDatabase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('commonplace.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'login stub')->name('login');
    }
}
