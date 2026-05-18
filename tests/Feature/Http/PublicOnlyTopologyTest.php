<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Http;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class PublicOnlyTopologyTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commonplace.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);

        // Public-only mirror: authenticated group disabled, public group on.
        $app['config']->set('commonplace.routes.enabled', false);
        $app['config']->set('commonplace.routes.public.enabled', true);
    }

    public function test_public_show_renders_without_authenticated_asset_route_registration(): void
    {
        Note::factory()->create([
            'path' => 'notes/welcome',
            'content' => 'Body.',
            'visibility' => 'public',
        ]);

        // The public template references `route('commonplace.asset.css')`.
        // Asset routes must register even when the authenticated group is off,
        // otherwise rendering the template throws RouteNotFoundException.
        $this->get('/commonplace/public/notes/welcome')
            ->assertOk()
            ->assertSee('/commonplace/assets/commonplace.css');
    }

    public function test_asset_route_resolves_with_auth_group_disabled(): void
    {
        $this->assertTrue($this->app['router']->has('commonplace.asset.css'));
    }

    public function test_authenticated_show_is_not_registered(): void
    {
        $this->assertFalse($this->app['router']->has('commonplace.show'));
    }
}
