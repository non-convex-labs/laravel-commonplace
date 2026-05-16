<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Mcp;

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\McpServiceProvider;
use NonConvexLabs\Commonplace\Tests\TestCase;

class McpDisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            McpServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('commonplace.mcp.enabled', false);
        $app['config']->set('commonplace.mcp.prefix', 'mcp/commonplace');
    }

    public function test_routes_do_not_register_when_disabled(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->all();

        $this->assertNotContains('mcp/commonplace', $routes);
    }
}
