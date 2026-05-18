<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Http;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

/**
 * Regression for #97 / S-PUB-06. When the public group is disabled,
 * URLs under the default public prefix must 404 from the framework
 * boundary — not 302 to /login from the auth catch-all.
 */
class PublicNoteControllerDisabledTest extends TestCase
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

        $app['config']->set('commonplace.routes.public.enabled', false);
    }

    public function test_get_under_public_prefix_returns_404_when_disabled(): void
    {
        $this->get('/commonplace/public/public/handbook')->assertNotFound();
    }

    public function test_put_under_public_prefix_returns_404_when_disabled(): void
    {
        $this->put('/commonplace/public/public/handbook')->assertNotFound();
    }

    public function test_delete_under_public_prefix_returns_404_when_disabled(): void
    {
        $this->delete('/commonplace/public/public/handbook')->assertNotFound();
    }

    public function test_post_under_public_prefix_returns_404_when_disabled(): void
    {
        $this->post('/commonplace/public/public/handbook')->assertNotFound();
    }

    public function test_bare_public_prefix_returns_404_when_disabled(): void
    {
        $this->get('/commonplace/public/')->assertNotFound();
        $this->get('/commonplace/public')->assertNotFound();
    }

    public function test_authenticated_index_still_works_when_public_disabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/commonplace')->assertOk();
    }

    public function test_authenticated_user_with_note_under_public_path_also_gets_404(): void
    {
        // Documented contract: when public routes are disabled, the
        // `<auth-prefix>/public/*` URL space is reserved package-wide.
        // A vault note at path `public/handbook` is therefore not
        // reachable at `/commonplace/public/handbook` even for the
        // authenticated owner. Operators who want that vault path back
        // can move the public group to a non-conflicting prefix via
        // `COMMONPLACE_PUBLIC_ROUTES_PREFIX`.
        $user = User::factory()->create();

        Note::factory()->create([
            'user_id' => $user->id,
            'path' => 'public/handbook',
            'visibility' => 'private',
        ]);

        $this->actingAs($user)->get('/commonplace/public/handbook')->assertNotFound();
    }
}
