<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Http;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;

class PublicNoteControllerPrefixOverrideTest extends TestCase
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

        $app['config']->set('commonplace.routes.public.enabled', true);
        $app['config']->set('commonplace.routes.public.prefix', 'commonplace/share');
    }

    public function test_overridden_prefix_serves_public_notes(): void
    {
        Note::factory()->create([
            'path' => 'notes/welcome',
            'content' => 'Body.',
            'visibility' => 'public',
        ]);

        $this->get('/commonplace/share/notes/welcome')->assertOk()->assertSee('Body.');
        $this->get('/commonplace/share/raw/notes/welcome')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function test_default_public_prefix_is_not_registered_when_overridden(): void
    {
        Note::factory()->create([
            'path' => 'notes/welcome',
            'content' => 'Body.',
            'visibility' => 'public',
        ]);

        // The original /commonplace/public/* paths fall through to the
        // authenticated catch-all and redirect to /login (302), proving
        // the public group is no longer mounted there.
        $this->get('/commonplace/public/notes/welcome')->assertStatus(302);
    }
}
