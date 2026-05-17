<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Mcp;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\McpServiceProvider;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

/**
 * Auth wiring for the MCP transport. The Registrar inside laravel/mcp
 * registers a POST plus a 405-`Allow: POST` GET and DELETE. Chaining
 * middleware onto the returned POST `Route` would leave the 405 stubs
 * unauthenticated; the package wraps `Mcp::web()` in a
 * `Route::middleware(...)->group(...)` so the stack covers all three.
 *
 * The default middleware in `config/commonplace.php` is
 * `['auth:sanctum']`, but Sanctum isn't a `require` dep of this
 * package and isn't installed in the test environment. These tests
 * use the core `auth` middleware (session guard, always available)
 * to assert the structural property — same group wrap, same
 * rejection — without depending on Sanctum.
 */
class McpAuthTest extends TestCase
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
        $app['config']->set('commonplace.mcp.middleware', ['auth']);
    }

    public function test_unauthenticated_post_is_rejected(): void
    {
        $this->postJson('/mcp/commonplace', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertStatus(401);
    }

    /**
     * GET / DELETE on the MCP transport are 405 stubs from the registrar.
     * The point of group-wrapping the middleware is that they fail auth
     * before they fail method-not-allowed. A chain-wrap would have
     * returned 405 here without ever consulting the guard.
     */
    public function test_unauthenticated_get_is_rejected_by_auth_not_by_405(): void
    {
        $this->getJson('/mcp/commonplace')->assertStatus(401);
    }

    public function test_unauthenticated_delete_is_rejected_by_auth_not_by_405(): void
    {
        $this->deleteJson('/mcp/commonplace')->assertStatus(401);
    }

    public function test_authenticated_user_can_post(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/mcp/commonplace', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ])
            ->assertOk();
    }
}
