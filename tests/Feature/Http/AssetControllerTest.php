<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Http;

use NonConvexLabs\Commonplace\Tests\TestCase;

class AssetControllerTest extends TestCase
{
    private string $publishedPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishedPath = resource_path('css/commonplace/commonplace.css');
    }

    protected function tearDown(): void
    {
        // Each test owns its own published-override fixture; clean up so
        // the next test starts from a known state. Removing the parent
        // directory if empty keeps Testbench's workbench tidy.
        if (is_file($this->publishedPath)) {
            @unlink($this->publishedPath);
        }
        $parent = dirname($this->publishedPath);
        if (is_dir($parent) && (@rmdir($parent) || false)) {
            @rmdir(dirname($parent));
        }

        parent::tearDown();
    }

    public function test_css_route_serves_bundled_copy_when_no_override_published(): void
    {
        $response = $this->get('/commonplace/assets/commonplace.css');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->assertStringContainsString(
            'Commonplace — self-contained styles for the package HTTP layer',
            (string) $response->getContent(),
        );
    }

    public function test_css_route_serves_published_override_when_present(): void
    {
        // S-INT-17 contract: publishing the CSS and editing the published
        // file must change what /commonplace/assets/commonplace.css
        // returns, without forcing the consumer to wire up Vite.
        $marker = '/* INT-EXT-PUBLISHED-MARKER */';
        $this->writePublishedOverride(<<<CSS
            {$marker}
            :root { --commonplace-color-accent: oklch(67% 0.21 27); }
            CSS);

        $response = $this->get('/commonplace/assets/commonplace.css');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $body = (string) $response->getContent();
        $this->assertStringContainsString($marker, $body);
        $this->assertStringContainsString('oklch(67% 0.21 27)', $body);
        $this->assertStringNotContainsString(
            'Commonplace — self-contained styles for the package HTTP layer',
            $body,
            'The bundled file must NOT be served when an override is present.',
        );
    }

    public function test_css_route_emits_no_store_when_app_debug_is_on(): void
    {
        // Issue #121: with debug on (local dev, staging chasing a
        // prod-shaped bug, etc.) the consumer is iterating on theme
        // variables. The route must opt out of caching so refreshes
        // actually re-fetch — filenames are unversioned, so the
        // consumer can't add a query-string buster on their side.
        //
        // Symfony's HeaderBag normalises Cache-Control: it alphabetises
        // the directives and adds `private` when `no-store` is set.
        // Assert on directive *presence* rather than exact string match
        // so changes in normalisation don't fail the test.
        config(['app.debug' => true]);

        $response = $this->get('/commonplace/assets/commonplace.css');

        $response->assertOk();
        $header = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $header);
        $this->assertStringNotContainsString('max-age', $header);
        $this->assertStringNotContainsString('public', $header);
    }

    public function test_css_route_emits_long_cache_when_app_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $response = $this->get('/commonplace/assets/commonplace.css');

        $response->assertOk();
        $header = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=3600', $header);
        $this->assertStringContainsString('public', $header);
        $this->assertStringNotContainsString('no-store', $header);
    }

    public function test_css_cache_header_is_the_same_for_published_and_bundled(): void
    {
        // Regression guard: the no-store / max-age decision is keyed on
        // app.debug, not on whether an override is present. A consumer
        // editing the published file must see the same caching policy
        // they'd see if no override existed.
        config(['app.debug' => false]);

        $bundledHeader = (string) $this->get('/commonplace/assets/commonplace.css')
            ->headers->get('Cache-Control');

        $this->writePublishedOverride("/* override */\n");

        $publishedHeader = (string) $this->get('/commonplace/assets/commonplace.css')
            ->headers->get('Cache-Control');

        $this->assertSame($bundledHeader, $publishedHeader);
        $this->assertStringContainsString('max-age=3600', $bundledHeader);
    }

    public function test_js_route_ignores_resource_path_and_serves_bundled_copy(): void
    {
        // JS is not a published asset (no `commonplace-js` publish tag
        // exists). Dropping a file at resources/js/commonplace/ must NOT
        // turn into an undocumented script-injection extension point.
        $jsPath = resource_path('js/commonplace/commonplace.js');
        $jsDir = dirname($jsPath);
        $createdDir = ! is_dir($jsDir) && @mkdir($jsDir, 0755, true);

        try {
            file_put_contents($jsPath, "/* PUBLISHED-JS-MARKER */\n");

            $response = $this->get('/commonplace/assets/commonplace.js');

            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
            $this->assertStringNotContainsString(
                'PUBLISHED-JS-MARKER',
                (string) $response->getContent(),
                'JS must always serve the bundled copy; published JS overrides are not a supported extension point.',
            );
        } finally {
            @unlink($jsPath);
            if ($createdDir) {
                @rmdir($jsDir);
                @rmdir(dirname($jsDir));
            }
        }
    }

    private function writePublishedOverride(string $contents): void
    {
        $dir = dirname($this->publishedPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->publishedPath, $contents);
    }
}
