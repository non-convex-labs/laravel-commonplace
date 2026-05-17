<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Mcp;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\McpServiceProvider;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\TestCase;

/**
 * Belt-and-braces guard: with the middleware stack explicitly empty
 * at route-registration time, the transport is open and the per-tool
 * `$request->user()` fail-closed is the only thing keeping data from
 * leaking. Documents what `commonplace:doctor`'s "empty middleware"
 * failure mode is preventing — and why it's `fail`, not `warn`.
 *
 * Separate class because `commonplace.mcp.middleware` only takes
 * effect when the route group registers; setting it post-boot
 * doesn't re-register routes.
 */
class McpAuthUnauthenticatedFallbackTest extends TestCase
{
    use InteractsWithCommonplaceDatabase {
        InteractsWithCommonplaceDatabase::defineEnvironment as defineCommonplaceEnvironment;
    }
    use RefreshDatabase;

    /**
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            McpServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $this->defineCommonplaceEnvironment($app);

        $app['config']->set('commonplace.mcp.enabled', true);
        $app['config']->set('commonplace.mcp.prefix', 'mcp/commonplace');
        $app['config']->set('commonplace.mcp.middleware', []);
    }

    public function test_empty_middleware_stack_does_not_reject_unauthenticated_requests(): void
    {
        $response = $this->postJson('/mcp/commonplace', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $this->assertNotSame(
            401,
            $response->getStatusCode(),
            'With middleware empty, the MCP transport must not reject unauthenticated requests by auth — '
            .'the per-tool fail-closed is the only remaining safety, which is exactly the case '
            .'`commonplace:doctor` flags as failing.',
        );
    }
}
