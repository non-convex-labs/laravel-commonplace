<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Unit\Mcp;

use Illuminate\Foundation\Application;
use Laravel\Mcp\Server\McpServiceProvider;
use NonConvexLabs\Commonplace\Enums\Visibility;
use NonConvexLabs\Commonplace\Mcp\CommonplaceMcpServer;
use NonConvexLabs\Commonplace\Tests\TestCase;
use ReflectionClass;

/**
 * Recurrence-prevention guard for the "fictional `shared` visibility"
 * bug. Walks every tool the MCP server exposes; for any input-schema
 * property literally named `visibility`, asserts the surfaced
 * description contains every Visibility enum token and none of the
 * forbidden words that historically drifted in. Containment (not
 * equality) so tools may keep their own context prefix
 * (`"Filter by visibility: "`, `"New visibility: "`).
 */
class SchemaDriftTest extends TestCase
{
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

    public function test_visibility_property_descriptions_track_the_enum(): void
    {
        $server = new ReflectionClass(CommonplaceMcpServer::class);
        $toolClasses = $server->getDefaultProperties()['tools'] ?? [];

        $this->assertNotEmpty($toolClasses, 'CommonplaceMcpServer registered no tools.');

        $allowedTokens = Visibility::values();
        $forbiddenTokens = ['shared', 'protected', 'internal', 'pending'];

        $sawVisibilityProperty = false;

        foreach ($toolClasses as $toolClass) {
            $tool = $this->app->make($toolClass);
            $schema = $tool->toArray()['inputSchema'] ?? [];
            $properties = $schema['properties'] ?? [];

            if (! is_array($properties) || ! array_key_exists('visibility', $properties)) {
                continue;
            }

            $sawVisibilityProperty = true;
            $description = $properties['visibility']['description'] ?? null;

            $this->assertIsString(
                $description,
                "{$toolClass}: visibility property has no description — "
                .'schema-drift test relies on description text. Use '
                .'`->description(...)` to surface it.'
            );

            foreach ($allowedTokens as $token) {
                $this->assertStringContainsString(
                    $token,
                    $description,
                    "{$toolClass}: visibility description must mention '{$token}'. "
                    ."Got: '{$description}'."
                );
            }

            foreach ($forbiddenTokens as $token) {
                $this->assertStringNotContainsString(
                    $token,
                    $description,
                    "{$toolClass}: visibility description must not mention '{$token}' — "
                    ."it is not a real Visibility value. Got: '{$description}'."
                );
            }
        }

        $this->assertTrue(
            $sawVisibilityProperty,
            'No tool exposed a `visibility` input property — '
            .'either the test is stale or the tools removed it. '
            .'If intentional, delete this test.'
        );
    }
}
