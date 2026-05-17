# Markdown rendering

How `laravel-commonplace` parses markdown, and where you can plug in
your own extensions.

The markdown pipeline is a configurable list of CommonMark extensions
plus an optional runtime hook. The defaults give you tables, autolinks,
strikethrough, task lists, footnotes, smart punctuation, emoji, and
`[[wikilinks]]` out of the box. You can add your own extensions through
config, register a callback from a service provider for anything that
needs the live `Environment`, or swap the wikilink resolver to point
links at a different model. The sections below cover each path.

---

## Defaults

Defaults ship in `config/commonplace.php` under `markdown.extensions`:

```php
'extensions' => [
    League\CommonMark\Extension\Table\TableExtension::class,
    League\CommonMark\Extension\Autolink\AutolinkExtension::class,
    League\CommonMark\Extension\Strikethrough\StrikethroughExtension::class,
    League\CommonMark\Extension\TaskList\TaskListExtension::class,
    League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension::class,
    League\CommonMark\Extension\Footnote\FootnoteExtension::class,
    League\CommonMark\Extension\SmartPunct\SmartPunctExtension::class,
    ElGigi\CommonMarkEmoji\EmojiExtension::class,
    NonConvexLabs\Commonplace\Markdown\Wikilink\WikilinkExtension::class,
],
```

---

## Order and precedence

Extensions are registered in array order. Within CommonMark, extensions
registered later can override parsers and renderers registered earlier,
so put narrower or more specific extensions at the end of the list.
Runtime extenders registered via `Commonplace::extendMarkdown()` (see
below) run AFTER the config list, so they always win on conflicts.

---

## Adding a parameterised extension

Entries can be either class strings (resolved through the container) or
already-constructed `ExtensionInterface` instances. Use the instance
form for extensions that take constructor args:

```php
'extensions' => [
    // ... defaults ...
    new League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension(),
    new League\CommonMark\Extension\ExternalLink\ExternalLinkExtension(),
],
```

---

## Removing `DisallowedRawHtmlExtension` is an XSS regression

Without it, raw `<script>` tags pass through to the output. Keep it
unless you have your own sanitizer downstream.

---

## Runtime extension hook

For custom inline parsers, renderers, or event listeners that don't fit
the class-string or instance config form, register a callback from your
service provider's `boot()` method:

```php
use Illuminate\Support\ServiceProvider;
use League\CommonMark\Environment\Environment;
use NonConvexLabs\Commonplace\Facades\Commonplace;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Commonplace::extendMarkdown(function (Environment $env): void {
            // Add your own inline parser, renderer, or event listener.
            $env->addInlineParser(new MyAdmonitionInlineParser, priority: 100);
        });
    }
}
```

**Register at boot time only.** Calling `extendMarkdown` per-request
under Octane or queue workers will accumulate callbacks across requests
and leak memory.

---

## Swapping the wikilink resolver

`[[wikilink]]` syntax is implemented as a CommonMark extension that
delegates target resolution to a swappable `WikilinkResolver`. The
default (`Services\WikilinkParser`) resolves against the `Note` model.
Bind your own implementation to point wikilinks elsewhere:

```php
use NonConvexLabs\Commonplace\Contracts\WikilinkResolver;
use NonConvexLabs\Commonplace\Markdown\Wikilink\ResolvedWikilink;

class WikiResolver implements WikilinkResolver
{
    public function resolve(string $target): ?ResolvedWikilink
    {
        // Look the target up in your own model, an external API, etc.
        return new ResolvedWikilink(
            href: route('docs.show', ['slug' => Str::slug($target)]),
            title: $target,
        );
    }
}

// In your service provider's register():
$this->app->bind(WikilinkResolver::class, WikiResolver::class);
```

Returning `null` produces a broken-link `<a>` with class
`vault-link-broken` (uses `commonplace.routes.prefix` as the fallback
href base).
