<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Http;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Models\Share;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class PublicNoteControllerTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // The trait's defineEnvironment is shadowed because this class
        // redeclares the method; inline the user_model setup it would
        // have done so the migration's User FK resolves.
        $app['config']->set('commonplace.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);

        // Toggle the public route group on BEFORE the package boots —
        // setting this in setUp() is too late, since routes are loaded
        // by the service provider's boot pass.
        $app['config']->set('commonplace.routes.public.enabled', true);
    }

    public function test_public_note_is_accessible_without_auth(): void
    {
        Note::factory()->create([
            'path' => 'notes/welcome',
            'title' => 'Welcome',
            'content' => 'Welcome body.',
            'visibility' => 'public',
        ]);

        $response = $this->get('/commonplace/public/notes/welcome');

        $response->assertOk();
        $response->assertSee('Welcome body.');
    }

    public function test_public_raw_endpoint_returns_plain_text(): void
    {
        Note::factory()->create([
            'path' => 'notes/raw',
            'title' => 'Raw',
            'content' => 'Plain raw body.',
            'visibility' => 'public',
        ]);

        $response = $this->get('/commonplace/public/raw/notes/raw');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame('Plain raw body.', $response->getContent());
    }

    public function test_private_note_returns_404_to_unauthenticated_visitor(): void
    {
        Note::factory()->create([
            'path' => 'notes/secret',
            'title' => 'Secret',
            'content' => 'Private body.',
            'visibility' => 'private',
        ]);

        $response = $this->get('/commonplace/public/notes/secret');

        // 404 not 403 — leaking existence would let an attacker
        // enumerate the private vault.
        $response->assertNotFound();
    }

    public function test_shared_note_is_not_exposed_via_public_route(): void
    {
        // Sharing is per-user via the Share model, not a visibility state.
        // A private note with an explicit Share row must still 404 to
        // unauthenticated visitors — only `visibility = public` is exposed.
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $note = Note::factory()->create([
            'user_id' => $owner->id,
            'path' => 'notes/team',
            'title' => 'Team',
            'content' => 'Team body.',
            'visibility' => 'private',
        ]);

        Share::create([
            'note_id' => $note->id,
            'user_id' => $recipient->id,
            'permission' => 'read',
        ]);

        $this->get('/commonplace/public/notes/team')->assertNotFound();
    }

    public function test_route_is_registered_under_public_namespace_when_enabled(): void
    {
        // Class-level defineEnvironment has enabled the public group,
        // so the named route must exist. The disabled-mode case is
        // validated by inspection in another harness (a test that
        // re-bootstraps to flip the flag is brittle in testbench).
        $this->assertTrue($this->app['router']->has('commonplace.public.show'));
    }

    public function test_public_show_does_not_render_edit_or_delete_affordances(): void
    {
        Note::factory()->create([
            'path' => 'public/handbook',
            'title' => 'Handbook',
            'content' => 'Body.',
            'visibility' => 'public',
        ]);

        $response = $this->get('/commonplace/public/public/handbook');

        $response->assertOk();
        $response->assertDontSee('/commonplace/edit/');
        $response->assertDontSee('cp-delete-btn');
        $response->assertDontSee('cp-delete-form');
        $response->assertDontSee('/commonplace/download/');
    }

    public function test_public_show_does_not_render_authenticated_nav_links(): void
    {
        Note::factory()->create([
            'path' => 'public/handbook',
            'title' => 'Handbook',
            'content' => 'Body.',
            'visibility' => 'public',
        ]);

        $response = $this->get('/commonplace/public/public/handbook');

        $response->assertOk();
        $response->assertDontSee('/commonplace/create');
        $response->assertDontSee('/commonplace/search');
        $response->assertDontSee('/commonplace/graph');
        $response->assertDontSee('commonplace-topbar');
        $response->assertDontSee('commonplace-nav');
    }

    public function test_public_show_view_markdown_link_points_to_public_raw_endpoint(): void
    {
        Note::factory()->create([
            'path' => 'public/handbook',
            'title' => 'Handbook',
            'content' => 'Body.',
            'visibility' => 'public',
        ]);

        $response = $this->get('/commonplace/public/public/handbook');

        $response->assertOk();
        $response->assertSee('/commonplace/public/raw/public/handbook', false);
        // The auth-gated /commonplace/raw/{path} must not leak into the
        // public template — that would 302 visitors to /login.
        $response->assertDontSee('href="/commonplace/raw/', false);
    }

    public function test_put_on_public_url_returns_405_not_419(): void
    {
        // Regression for #97 / S-PUB-05. PUT on a public URL must not
        // reach the authenticated catch-all's CSRF middleware — that
        // would 419. The no-middleware method trap inside the public
        // group returns 405 from a clean boundary.
        $this->put('/commonplace/public/public/handbook')->assertStatus(405);
    }

    public function test_delete_on_public_url_returns_405_not_419(): void
    {
        $this->delete('/commonplace/public/public/handbook')->assertStatus(405);
    }

    public function test_patch_on_public_url_returns_405(): void
    {
        $this->patch('/commonplace/public/public/handbook')->assertStatus(405);
    }

    public function test_post_on_public_url_returns_405(): void
    {
        $this->post('/commonplace/public/public/handbook')->assertStatus(405);
    }

    public function test_bare_public_prefix_with_trailing_slash_returns_404(): void
    {
        // Regression for #96 / S-PUB-04. With the public group enabled
        // and *no* path component, `/{prefix}/public/` should 404 — not
        // fall through to the auth catch-all (302 to /login), a folder
        // browser, or render an empty list. The public surface must
        // not leak the existence of a public catalog.
        $response = $this->get('/commonplace/public/');

        $response->assertNotFound();
    }

    public function test_bare_public_prefix_without_trailing_slash_returns_404(): void
    {
        $response = $this->get('/commonplace/public');

        $response->assertNotFound();
    }

    public function test_public_show_returns_404_for_private_note_without_leaking_auth_template(): void
    {
        Note::factory()->create([
            'path' => 'notes/hidden',
            'title' => 'Hidden',
            'content' => 'Hidden body.',
            'visibility' => 'private',
        ]);

        $response = $this->get('/commonplace/public/notes/hidden');

        $response->assertNotFound();
        // The 404 path must not render the authenticated layout chrome,
        // which would suggest the wrong template path was reached.
        $response->assertDontSee('commonplace-topbar');
        $response->assertDontSee('Hidden body.');
    }
}
