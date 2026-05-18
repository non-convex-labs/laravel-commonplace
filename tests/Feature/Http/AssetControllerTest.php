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
