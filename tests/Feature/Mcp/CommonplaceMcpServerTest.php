<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Tests\Feature\Mcp;

use ErrorException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Mockery;
use NonConvexLabs\Commonplace\Exceptions\PublicMessage;
use NonConvexLabs\Commonplace\Mcp\CommonplaceMcpServer;
use NonConvexLabs\Commonplace\Mcp\Tools\BacklinksTool;
use NonConvexLabs\Commonplace\Mcp\Tools\CreateNoteTool;
use NonConvexLabs\Commonplace\Mcp\Tools\DeleteNoteTool;
use NonConvexLabs\Commonplace\Mcp\Tools\EditNoteTool;
use NonConvexLabs\Commonplace\Mcp\Tools\HistoryTool;
use NonConvexLabs\Commonplace\Mcp\Tools\HubNotesTool;
use NonConvexLabs\Commonplace\Mcp\Tools\ListTool;
use NonConvexLabs\Commonplace\Mcp\Tools\MoveTool;
use NonConvexLabs\Commonplace\Mcp\Tools\NeighborhoodTool;
use NonConvexLabs\Commonplace\Mcp\Tools\OrphanNotesTool;
use NonConvexLabs\Commonplace\Mcp\Tools\ReadNoteTool;
use NonConvexLabs\Commonplace\Mcp\Tools\SearchTool;
use NonConvexLabs\Commonplace\Mcp\Tools\SemanticSearchTool;
use NonConvexLabs\Commonplace\Mcp\Tools\ShortestPathTool;
use NonConvexLabs\Commonplace\Mcp\Tools\SuggestedLinksTool;
use NonConvexLabs\Commonplace\Mcp\Tools\UpdateNoteTool;
use NonConvexLabs\Commonplace\Models\Note;
use NonConvexLabs\Commonplace\Services\Commonplace;
use NonConvexLabs\Commonplace\Tests\Fixtures\InteractsWithCommonplaceDatabase;
use NonConvexLabs\Commonplace\Tests\Fixtures\User;
use NonConvexLabs\Commonplace\Tests\TestCase;
use RuntimeException;

class CommonplaceMcpServerTest extends TestCase
{
    use InteractsWithCommonplaceDatabase {
        InteractsWithCommonplaceDatabase::defineEnvironment as defineCommonplaceEnvironment;
    }
    use RefreshDatabase;

    private User $owner;

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

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $this->defineCommonplaceEnvironment($app);

        $app['config']->set('commonplace.mcp.enabled', true);
        $app['config']->set('commonplace.mcp.prefix', 'mcp/commonplace');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_mcp_routes_register_when_enabled(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->all();

        $this->assertContains('mcp/commonplace', $routes);
    }

    public function test_each_registered_tool_has_a_well_formed_schema(): void
    {
        $tools = [
            CreateNoteTool::class,
            ReadNoteTool::class,
            UpdateNoteTool::class,
            EditNoteTool::class,
            DeleteNoteTool::class,
            ListTool::class,
            SearchTool::class,
            SemanticSearchTool::class,
            BacklinksTool::class,
            MoveTool::class,
            HistoryTool::class,
            NeighborhoodTool::class,
            ShortestPathTool::class,
            HubNotesTool::class,
            OrphanNotesTool::class,
            SuggestedLinksTool::class,
        ];

        foreach ($tools as $toolClass) {
            $tool = $this->app->make($toolClass);
            $array = $tool->toArray();

            $this->assertIsString($array['name'], "{$toolClass} missing name");
            $this->assertNotSame('', $array['name'], "{$toolClass} has empty name");
            $this->assertIsString($array['description'], "{$toolClass} missing description");
            $this->assertNotSame('', $array['description'], "{$toolClass} has empty description");
            $this->assertArrayHasKey('inputSchema', $array, "{$toolClass} missing inputSchema");
            $this->assertSame('object', $array['inputSchema']['type'] ?? null, "{$toolClass} inputSchema not object");
        }
    }

    public function test_server_exposes_all_sixteen_tools(): void
    {
        $server = $this->app->make(CommonplaceMcpServer::class, [
            'transport' => new FakeTransporter,
        ]);

        $context = $server->createContext();

        $this->assertCount(16, $context->tools());
    }

    public function test_tools_list_returns_all_tools_in_a_single_page(): void
    {
        $server = $this->app->make(CommonplaceMcpServer::class, [
            'transport' => new FakeTransporter,
        ]);

        $request = new JsonRpcRequest(id: 1, method: 'tools/list', params: []);
        $response = (new ListTools)->handle($request, $server->createContext());

        $result = $response->toArray()['result'];

        $this->assertCount(16, $result['tools']);
        $this->assertArrayNotHasKey('nextCursor', $result, 'tools/list must not paginate — all tools should fit in the first page so MCP clients see every tool during discovery');
        $this->assertContains('suggested-links-tool', array_column($result['tools'], 'name'));
    }

    public function test_create_note_tool_creates_note(): void
    {
        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(CreateNoteTool::class, [
            'path' => 'projects/from-mcp',
            'content' => '# From MCP',
            'tags' => ['ai'],
            'visibility' => 'private',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('commonplace_notes', [
            'path' => 'projects/from-mcp',
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_read_note_tool_returns_content(): void
    {
        Note::factory()->create([
            'path' => 'projects/readable',
            'title' => 'Readable',
            'content' => 'Body content',
            'user_id' => $this->owner->id,
        ]);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(ReadNoteTool::class, [
            'path' => 'projects/readable',
        ]);

        $response->assertOk()->assertSee('Body content');
    }

    public function test_read_note_tool_errors_on_missing_note(): void
    {
        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(ReadNoteTool::class, [
            'path' => 'does/not/exist',
        ]);

        $response->assertHasErrors(['Note not found.']);
    }

    public function test_read_note_tool_collapses_inaccessible_into_not_found(): void
    {
        Note::factory()->create([
            'path' => 'private/owners-note',
            'user_id' => $this->owner->id,
            'visibility' => 'private',
        ]);

        $other = User::factory()->create();

        $response = CommonplaceMcpServer::actingAs($other)->tool(ReadNoteTool::class, [
            'path' => 'private/owners-note',
        ]);

        $response->assertHasErrors(['Note not found.']);
    }

    public function test_backlinks_tool_collapses_inaccessible_into_not_found(): void
    {
        Note::factory()->create([
            'path' => 'private/owners-note',
            'user_id' => $this->owner->id,
            'visibility' => 'private',
        ]);

        $other = User::factory()->create();

        $response = CommonplaceMcpServer::actingAs($other)->tool(BacklinksTool::class, [
            'path' => 'private/owners-note',
        ]);

        $response->assertHasErrors(['Note not found.']);
    }

    public function test_history_tool_collapses_inaccessible_into_not_found(): void
    {
        Note::factory()->create([
            'path' => 'private/owners-note',
            'user_id' => $this->owner->id,
            'visibility' => 'private',
        ]);

        $other = User::factory()->create();

        $response = CommonplaceMcpServer::actingAs($other)->tool(HistoryTool::class, [
            'path' => 'private/owners-note',
        ]);

        $response->assertHasErrors(['Note not found.']);
    }

    public function test_update_note_tool_updates_content(): void
    {
        Note::factory()->create([
            'path' => 'projects/draft',
            'content' => 'v1',
            'user_id' => $this->owner->id,
        ]);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(UpdateNoteTool::class, [
            'path' => 'projects/draft',
            'content' => 'v2',
        ]);

        $response->assertOk();
        $this->assertSame('v2', Note::where('path', 'projects/draft')->first()->content);
    }

    public function test_edit_note_tool_replaces_substring(): void
    {
        Note::factory()->create([
            'path' => 'projects/edit-me',
            'content' => 'hello world',
            'user_id' => $this->owner->id,
        ]);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(EditNoteTool::class, [
            'path' => 'projects/edit-me',
            'old_string' => 'world',
            'new_string' => 'universe',
        ]);

        $response->assertOk();
        $this->assertSame('hello universe', Note::where('path', 'projects/edit-me')->first()->content);
    }

    public function test_edit_note_tool_returns_not_found_for_missing_path(): void
    {
        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(EditNoteTool::class, [
            'path' => 'does/not/exist',
            'old_string' => 'foo',
            'new_string' => 'bar',
        ]);

        $response->assertHasErrors(['Note not found.']);
    }

    public function test_edit_note_tool_returns_auth_error_for_non_owner(): void
    {
        Note::factory()->create([
            'path' => 'projects/private-note',
            'content' => 'hello world',
            'user_id' => $this->owner->id,
        ]);

        $other = User::factory()->create();

        $response = CommonplaceMcpServer::actingAs($other)->tool(EditNoteTool::class, [
            'path' => 'projects/private-note',
            'old_string' => 'world',
            'new_string' => 'universe',
        ]);

        $response->assertHasErrors(['You do not have access to this note.']);
    }

    public function test_edit_note_tool_returns_current_content_envelope_when_old_string_is_ambiguous(): void
    {
        Note::factory()->create([
            'path' => 'projects/ambiguous',
            'content' => 'foo bar foo',
            'user_id' => $this->owner->id,
        ]);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(EditNoteTool::class, [
            'path' => 'projects/ambiguous',
            'old_string' => 'foo',
            'new_string' => 'baz',
            'replace_all' => false,
        ]);

        $response->assertHasErrors([
            'old_string appears 2 times in the note.',
            "--- current note content ---\nfoo bar foo",
        ]);
    }

    public function test_delete_note_tool_removes_note(): void
    {
        Note::factory()->create([
            'path' => 'projects/delete-me',
            'user_id' => $this->owner->id,
        ]);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(DeleteNoteTool::class, [
            'path' => 'projects/delete-me',
        ]);

        $response->assertOk()->assertSee('Note deleted');
        $this->assertDatabaseMissing('commonplace_notes', ['path' => 'projects/delete-me']);
    }

    public function test_list_tool_returns_user_notes(): void
    {
        Note::factory()->create([
            'path' => 'projects/one',
            'title' => 'One',
            'user_id' => $this->owner->id,
        ]);
        Note::factory()->create([
            'path' => 'projects/two',
            'title' => 'Two',
            'user_id' => $this->owner->id,
        ]);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(ListTool::class, []);

        $response->assertOk()
            ->assertSee('projects/one')
            ->assertSee('projects/two');
    }

    public function test_search_tool_finds_matching_notes(): void
    {
        Note::factory()->create([
            'path' => 'projects/searchable',
            'title' => 'Searchable Title',
            'content' => 'unique-needle text',
            'user_id' => $this->owner->id,
        ]);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(SearchTool::class, [
            'query' => 'unique-needle',
        ]);

        $response->assertOk()->assertSee('projects/searchable');
    }

    public function test_backlinks_tool_lists_inbound_links(): void
    {
        $target = Note::factory()->create([
            'path' => 'reference/target',
            'user_id' => $this->owner->id,
        ]);
        Note::factory()->create([
            'path' => 'projects/source',
            'content' => 'See [[reference/target]].',
            'user_id' => $this->owner->id,
        ]);

        $this->app->make(Commonplace::class)
            ->updateNote('projects/source', ['content' => 'See [[reference/target]].'], $this->owner);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(BacklinksTool::class, [
            'path' => 'reference/target',
        ]);

        $response->assertOk()->assertSee('projects/source');
    }

    public function test_move_tool_renames_note(): void
    {
        Note::factory()->create([
            'path' => 'projects/old-path',
            'user_id' => $this->owner->id,
        ]);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(MoveTool::class, [
            'from_path' => 'projects/old-path',
            'to_path' => 'projects/new-path',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('commonplace_notes', ['path' => 'projects/new-path']);
        $this->assertDatabaseMissing('commonplace_notes', ['path' => 'projects/old-path']);
    }

    public function test_history_tool_returns_versions(): void
    {
        $commonplace = $this->app->make(Commonplace::class);

        $commonplace->createNote(
            path: 'projects/versioned',
            content: 'v1',
            tags: [],
            visibility: 'private',
            owner: $this->owner,
        );
        $commonplace->updateNote(
            path: 'projects/versioned',
            data: ['content' => 'v2'],
            user: $this->owner,
        );

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(HistoryTool::class, [
            'path' => 'projects/versioned',
        ]);

        $response->assertOk()->assertSee('content_hash');
    }

    public function test_orphan_notes_tool_returns_unlinked_notes(): void
    {
        Note::factory()->create([
            'path' => 'projects/orphan',
            'title' => 'Orphan',
            'user_id' => $this->owner->id,
        ]);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(OrphanNotesTool::class, []);

        $response->assertOk()->assertSee('projects/orphan');
    }

    public function test_semantic_search_tool_requires_pgvector(): void
    {
        $this->markTestSkipped('requires pgvector — issue #1');
    }

    public function test_neighborhood_tool_requires_pgvector(): void
    {
        $this->markTestSkipped('requires pgvector — issue #1 (recursive CTE + ARRAY syntax is PostgreSQL-only)');
    }

    public function test_shortest_path_tool_requires_pgvector(): void
    {
        $this->markTestSkipped('requires pgvector — issue #1 (recursive CTE + ARRAY syntax is PostgreSQL-only)');
    }

    public function test_hub_notes_tool_requires_pgvector(): void
    {
        $this->markTestSkipped('requires pgvector — issue #1 (raw SQL uses PostgreSQL-specific aggregate patterns)');
    }

    public function test_suggested_links_tool_requires_pgvector(): void
    {
        $this->markTestSkipped('requires pgvector — issue #1 (depends on vector distance operator)');
    }

    /**
     * S-AI-25 regression: any Throwable escaping a tool handler — not
     * just the domain exceptions individual tools opt to catch — must
     * be surfaced as a JSON-RPC `result.isError` envelope at the
     * transport level, never as an HTTP 500 / generic JSON-RPC error.
     *
     * Under the fail-closed allowlist (#118), an unmarked exception
     * (here: a bare `RuntimeException` not implementing `PublicMessage`)
     * MUST collapse to the fixed generic string — its `getMessage()`
     * is NEVER on the wire. Operators still see the full stack via
     * `report()`.
     *
     * See docs/scenarios/ai-agent.md → S-AI-25 and issues #110 / #118.
     */
    public function test_unhandled_throwable_in_tool_is_converted_to_is_error_envelope(): void
    {
        $mock = Mockery::mock(Commonplace::class);
        $mock->shouldReceive('listNotes')
            ->once()
            ->andThrow(new RuntimeException('embedding provider rate-limited: voyage-3.5'));

        $this->app->instance(Commonplace::class, $mock);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(ListTool::class, []);

        $raw = (fn (): array => $this->response->toArray())
            ->call($response);

        // Envelope shape — required by S-AI-25.
        $this->assertSame('2.0', $raw['jsonrpc']);
        $this->assertArrayHasKey('result', $raw);
        $this->assertArrayNotHasKey('error', $raw);
        $this->assertTrue($raw['result']['isError']);
        $this->assertSame('text', $raw['result']['content'][0]['type']);

        // Fail-closed contract (#118) — unmarked Throwables collapse.
        $this->assertSame(
            'The tool failed to complete the request.',
            $raw['result']['content'][0]['text'],
        );
        $this->assertStringNotContainsString(
            'voyage-3.5',
            $raw['result']['content'][0]['text'],
            'unmarked RuntimeException::getMessage() must not reach the wire',
        );
    }

    /**
     * #118 contract: exceptions that implement the [[PublicMessage]]
     * marker interface opt their `getMessage()` into the envelope.
     * Used by tool authors for hand-curated, agent-actionable error
     * strings that are known not to embed PII.
     */
    public function test_public_message_marker_lets_exception_message_through(): void
    {
        $marked = new class('curated agent-actionable hint') extends RuntimeException implements PublicMessage {};

        $mock = Mockery::mock(Commonplace::class);
        $mock->shouldReceive('listNotes')->once()->andThrow($marked);
        $this->app->instance(Commonplace::class, $mock);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(ListTool::class, []);

        $raw = (fn (): array => $this->response->toArray())->call($response);

        $this->assertTrue($raw['result']['isError']);
        $this->assertSame(
            'curated agent-actionable hint',
            $raw['result']['content'][0]['text'],
        );
    }

    /**
     * #118 edge case: a `PublicMessage`-implementing exception with an
     * empty `getMessage()` falls back to the generic string rather
     * than emitting an empty `text` field. An empty envelope text
     * would be agent-hostile UX *and* would hide a constructor bug
     * in the consumer's exception class.
     */
    public function test_public_message_marker_with_empty_message_falls_back_to_generic_string(): void
    {
        $emptyMarked = new class('') extends RuntimeException implements PublicMessage {};

        $mock = Mockery::mock(Commonplace::class);
        $mock->shouldReceive('listNotes')->once()->andThrow($emptyMarked);
        $this->app->instance(Commonplace::class, $mock);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(ListTool::class, []);

        $raw = (fn (): array => $this->response->toArray())->call($response);

        $this->assertTrue($raw['result']['isError']);
        $this->assertSame(
            'The tool failed to complete the request.',
            $raw['result']['content'][0]['text'],
        );
    }

    /**
     * #118 regression: `ErrorException` from PHP's filesystem builtins
     * (`file_get_contents`, `fopen`) embeds the absolute path of the
     * file the call tried to access. Before the fail-closed sanitiser
     * that path would land verbatim in the agent client's view of the
     * tool error, leaking host filesystem layout. The envelope now
     * collapses to the generic string; the operator still gets the
     * full ErrorException via `report()`.
     */
    public function test_error_exception_with_filesystem_path_is_redacted(): void
    {
        $leakyPath = '/var/www/secrets/app-bearer-token.txt';
        $errorException = new ErrorException(
            "file_get_contents({$leakyPath}): Failed to open stream: No such file or directory",
            severity: E_WARNING,
        );

        $mock = Mockery::mock(Commonplace::class);
        $mock->shouldReceive('listNotes')->once()->andThrow($errorException);
        $this->app->instance(Commonplace::class, $mock);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(ListTool::class, []);

        $raw = (fn (): array => $this->response->toArray())->call($response);
        $envelopeText = $raw['result']['content'][0]['text'];

        $this->assertTrue($raw['result']['isError']);
        $this->assertSame('The tool failed to complete the request.', $envelopeText);
        $this->assertStringNotContainsString($leakyPath, $envelopeText);
        $this->assertStringNotContainsString('/var/www', $envelopeText);
        $this->assertStringNotContainsString('app-bearer-token', $envelopeText);
    }

    /**
     * #118 regression: HTTP client exception classes
     * (`Illuminate\Http\Client\ConnectionException`, Guzzle's
     * `ConnectException` / `RequestException`, Symfony's
     * `TransportException`) vary in whether they embed the full
     * URL, the cURL error string, the response body, or some
     * combination — depending on the client, the failure mode, and
     * how userland wraps them. Rather than try to reproduce any one
     * vendor's exact format, this test pins the sanitiser-side
     * contract: an *unmarked* exception whose message embeds a URL
     * with auth-bearing query parameters (the worst-case shape) MUST
     * collapse to the generic string, with nothing of the URL
     * surviving. The fixture is constructed to exercise that
     * collapse — it does NOT claim to mirror Guzzle's wire format.
     */
    public function test_http_client_exception_with_url_is_redacted(): void
    {
        $leakyUrl = 'https://internal.example.com/v1/keys?token=AKIA-secret&signature=abc123';
        $httpException = new ConnectionException(
            "cURL error 6: Could not resolve host: internal.example.com (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for {$leakyUrl}",
        );

        $mock = Mockery::mock(Commonplace::class);
        $mock->shouldReceive('listNotes')->once()->andThrow($httpException);
        $this->app->instance(Commonplace::class, $mock);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(ListTool::class, []);

        $raw = (fn (): array => $this->response->toArray())->call($response);
        $envelopeText = $raw['result']['content'][0]['text'];

        $this->assertTrue($raw['result']['isError']);
        $this->assertSame('The tool failed to complete the request.', $envelopeText);
        $this->assertStringNotContainsString('internal.example.com', $envelopeText);
        $this->assertStringNotContainsString('AKIA-secret', $envelopeText);
        $this->assertStringNotContainsString('token=', $envelopeText);
    }

    /**
     * #118 regression: Eloquent's `ModelNotFoundException::getMessage()`
     * reads "No query results for model [App\Models\Note] {id}",
     * leaking the consumer's model namespace. Tools normally catch
     * this internally and collapse to "Note not found." (see the
     * `*_collapses_inaccessible_into_not_found` tests above), but if
     * one bubbles past — e.g. a relation `firstOrFail` inside a service
     * method — the envelope must NOT carry the class name through.
     */
    public function test_model_not_found_exception_with_class_name_is_redacted(): void
    {
        $modelClass = 'App\\Models\\InternalAuditLog';
        $modelException = (new ModelNotFoundException)->setModel($modelClass);

        $mock = Mockery::mock(Commonplace::class);
        $mock->shouldReceive('listNotes')->once()->andThrow($modelException);
        $this->app->instance(Commonplace::class, $mock);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(ListTool::class, []);

        $raw = (fn (): array => $this->response->toArray())->call($response);
        $envelopeText = $raw['result']['content'][0]['text'];

        $this->assertTrue($raw['result']['isError']);
        $this->assertSame('The tool failed to complete the request.', $envelopeText);
        $this->assertStringNotContainsString('InternalAuditLog', $envelopeText);
        $this->assertStringNotContainsString('App\\Models', $envelopeText);
        $this->assertStringNotContainsString('No query results', $envelopeText);
    }

    /**
     * Issue #115 regression: a QueryException reaching the MCP envelope
     * must not leak DB connection metadata (Host/Port/Database), the
     * parameterized SQL, the offending row from PDO's `DETAIL:` line, or
     * constraint names. The envelope keeps just the SQLSTATE category so
     * callers can still tell apart "unique violation" from "deadlock".
     * Operators still see the full exception via report().
     */
    public function test_query_exception_redacts_connection_and_sql_details(): void
    {
        $pdoMessage = 'SQLSTATE[23505]: Unique violation: 7 ERROR:  '
            .'duplicate key value violates unique constraint "commonplace_notes_path_unique"'."\n"
            .'DETAIL:  Key (path)=(public/handbook) already exists.';

        // SQLSTATE codes can be alphanumeric (e.g. "23505", "HY000"). PDO
        // stores them as a string on $code; Exception's constructor takes
        // int. Use an anonymous subclass to set the code to the SQLSTATE
        // string the real PDO driver would emit.
        $pdoException = new class($pdoMessage) extends \PDOException
        {
            public function __construct(string $message)
            {
                parent::__construct($message, 0);
                $this->code = '23505';
            }
        };

        $queryException = new QueryException(
            connectionName: 'pgsql',
            sql: 'insert into "commonplace_notes" ("path") values (?) returning "id"',
            bindings: ['public/handbook'],
            previous: $pdoException,
        );

        // Sanity-check the fixture: the raw QueryException really does
        // carry the SQL and DETAIL row we expect to redact. Host/Port/
        // Database segments are appended by Laravel's formatMessage only
        // when those config keys exist on the connection, so they're
        // present in the wild Postgres case (per #115) but not in the
        // SQLite test driver. The fix strips them all regardless.
        $this->assertStringContainsString('SQL: insert into', $queryException->getMessage());
        $this->assertStringContainsString('DETAIL', $queryException->getMessage());

        $mock = Mockery::mock(Commonplace::class);
        $mock->shouldReceive('createNote')
            ->once()
            ->andThrow($queryException);

        $this->app->instance(Commonplace::class, $mock);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(CreateNoteTool::class, [
            'path' => 'public/handbook',
            'content' => 'dup',
            'visibility' => 'private',
        ]);

        $raw = (fn (): array => $this->response->toArray())
            ->call($response);

        $envelopeText = $raw['result']['content'][0]['text'];

        $this->assertTrue($raw['result']['isError']);

        // No connection metadata, no SQL trace, no DETAIL row data, no
        // constraint name leaks.
        $this->assertStringNotContainsString('Host:', $envelopeText);
        $this->assertStringNotContainsString('Port:', $envelopeText);
        $this->assertStringNotContainsString('Database:', $envelopeText);
        $this->assertStringNotContainsString('Connection:', $envelopeText);
        $this->assertStringNotContainsString('SQL:', $envelopeText);
        $this->assertStringNotContainsString('DETAIL', $envelopeText);
        $this->assertStringNotContainsString('public/handbook', $envelopeText);
        $this->assertStringNotContainsString('commonplace_notes_path_unique', $envelopeText);
        $this->assertStringNotContainsString('127.0.0.1', $envelopeText);

        // Callers still get the SQLSTATE category so unique-violation vs
        // deadlock vs check-constraint stays distinguishable.
        $this->assertStringContainsString('SQLSTATE[23505]', $envelopeText);
    }

    /**
     * Issue #118 regression: DeadlockException extends PDOException
     * directly — not QueryException — so the #115 redaction branch
     * doesn't catch it. Its PDO-level message embeds DETAIL row data
     * on Postgres. The MCP envelope must collapse bare PDOExceptions
     * (including DeadlockException) to a "Database error: SQLSTATE[...]"
     * — same shape as QueryException, so retry-aware clients can still
     * discriminate. Operators still see the full exception via report().
     */
    public function test_bare_pdo_exception_is_redacted_to_database_error_with_sqlstate(): void
    {
        $leakyMessage = 'SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  '
            .'deadlock detected'."\n"
            .'DETAIL:  Process 1234 waits for ShareLock on transaction 5678; '
            .'blocked by process 9012.'."\n"
            .'CONTEXT:  while updating tuple (0,123) in relation "private_users"';

        $pdoException = new class($leakyMessage) extends \PDOException
        {
            public function __construct(string $message)
            {
                parent::__construct($message, 0);
                $this->code = '40P01';
            }
        };

        $mock = Mockery::mock(Commonplace::class);
        $mock->shouldReceive('listNotes')
            ->once()
            ->andThrow($pdoException);

        $this->app->instance(Commonplace::class, $mock);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(ListTool::class, []);

        $raw = (fn (): array => $this->response->toArray())
            ->call($response);

        $envelopeText = $raw['result']['content'][0]['text'];

        $this->assertTrue($raw['result']['isError']);
        // SQLSTATE is preserved (so retry-aware clients can still
        // discriminate deadlock from constraint violation), but the
        // DETAIL row, CONTEXT relation, and process/transaction IDs
        // are all gone.
        $this->assertSame('Database error: SQLSTATE[40P01]', $envelopeText);
        $this->assertStringNotContainsString('DETAIL', $envelopeText);
        $this->assertStringNotContainsString('CONTEXT', $envelopeText);
        $this->assertStringNotContainsString('private_users', $envelopeText);
        $this->assertStringNotContainsString('Deadlock detected', $envelopeText);
    }

    /**
     * Issue #118 regression (defense in depth): LostConnectionException
     * extends LogicException, NOT PDOException, so it bypasses the
     * QueryException + PDOException branches. Laravel itself throws it
     * with a fixed string ("Lost connection and no reconnector
     * available.") that carries no PII, but userland subclasses can
     * embed connection details. Test the contract by throwing a
     * subclass — any LostConnectionException, framework or userland,
     * collapses to the same fixed string.
     */
    public function test_lost_connection_exception_subclass_is_redacted(): void
    {
        $leakySubclass = new class extends LostConnectionException
        {
            public function __construct()
            {
                parent::__construct(
                    "Lost connection to the 'tenant_42_replica' MySQL server "
                    .'(host: db-internal.example.com, user: app_rw).'
                );
            }
        };

        $mock = Mockery::mock(Commonplace::class);
        $mock->shouldReceive('listNotes')
            ->once()
            ->andThrow($leakySubclass);

        $this->app->instance(Commonplace::class, $mock);

        $response = CommonplaceMcpServer::actingAs($this->owner)->tool(ListTool::class, []);

        $raw = (fn (): array => $this->response->toArray())
            ->call($response);

        $envelopeText = $raw['result']['content'][0]['text'];

        $this->assertTrue($raw['result']['isError']);
        $this->assertSame('Database connection lost.', $envelopeText);
        $this->assertStringNotContainsString('tenant_42_replica', $envelopeText);
        $this->assertStringNotContainsString('db-internal.example.com', $envelopeText);
        $this->assertStringNotContainsString('app_rw', $envelopeText);
    }
}
