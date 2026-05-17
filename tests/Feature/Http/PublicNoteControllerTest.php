<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class PublicNoteControllerTest extends TestCase
{
    use InteractsWithCommonplaceDatabase;
    use RefreshDatabase;

    /**
     * @param  \Illuminate\Foundation\Application  $app
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
        Note::factory()->create([
            'path' => 'notes/team',
            'title' => 'Team',
            'content' => 'Team body.',
            'visibility' => 'shared',
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
}
