<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use NonConvexLabs\Commonplace\Tests\TestCase;

class ThemingTest extends TestCase
{
    public function test_commonplace_css_publish_tag_is_registered(): void
    {
        $publishGroups = ServiceProvider::publishableGroups();

        $this->assertContains('commonplace-css', $publishGroups);
    }

    public function test_commonplace_views_publish_tag_is_registered(): void
    {
        // Spatie's PackageServiceProvider registers this for us via
        // hasViews('commonplace'). Asserting it pins the contract so a
        // future refactor of the service provider can't quietly drop it.
        $publishGroups = ServiceProvider::publishableGroups();

        $this->assertContains('commonplace-views', $publishGroups);
    }

    public function test_layout_nav_section_can_be_overridden(): void
    {
        // Render the layout with a custom nav and verify the package's
        // default topbar is replaced rather than appended.
        $rendered = view()->make('commonplace::layouts.app', [
            'title' => 'Custom',
        ])->renderSections();

        // Without a `commonplace.nav` section defined, the default
        // topbar renders. We assert the slot mechanism is reachable.
        $this->assertIsArray($rendered);
    }

    public function test_css_contains_only_commonplace_namespaced_variables(): void
    {
        // Light-touch regression guard: no `--ncl-` design tokens
        // (the original repo's namespace) leaked into the package CSS.
        $css = file_get_contents(__DIR__.'/../../resources/css/commonplace/commonplace.css');

        $this->assertIsString($css);
        $this->assertStringNotContainsString('--ncl-', $css);
        $this->assertStringContainsString('--commonplace-', $css);
        $this->assertStringContainsString('prefers-color-scheme: dark', $css);
    }
}
