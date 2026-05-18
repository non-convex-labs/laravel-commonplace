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

        // The named route picks up the override too — view templates that
        // call route('commonplace.public.showRaw', ...) stay correct.
        $this->assertSame(
            url('/commonplace/share/raw/notes/welcome'),
            route('commonplace.public.showRaw', ['path' => 'notes/welcome']),
        );
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

    public function test_bare_overridden_prefix_returns_404(): void
    {
        // The S-PUB-04 / #96 fix follows the active prefix — `/commonplace/share/`
        // (the overridden public root) is sealed off too, not just the default.
        $this->get('/commonplace/share/')->assertNotFound();
        $this->get('/commonplace/share')->assertNotFound();
    }
}
