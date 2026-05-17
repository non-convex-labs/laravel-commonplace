# Markdown rendering

Notes render through a configurable [league/commonmark](https://commonmark.thephpleague.com/) pipeline. There's also a boot-time hook for anything that needs the live `Environment`.

## Basic usage

To add a CommonMark extension, append its class string to `commonplace.markdown.extensions`:

```php
// config/commonplace.php
'markdown' => [
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
],
```

The defaults above give you tables, autolinks, strikethrough, task lists, footnotes, smart punctuation, emoji, and `[[wikilinks]]`. Code-block syntax highlighting is wired in separately via `tempest/highlight` and toggled with `markdown.highlight.enabled`.

## Order and precedence

CommonMark applies extensions in array order. Later entries can override earlier ones, so put narrower or more specific extensions at the end of the list. Runtime extenders registered via `Commonplace::extendMarkdown()` run after the config list, so they always win on conflicts.

## Parameterised extensions

Entries that need constructor args go in as already-constructed instances instead of class strings:

```php
'extensions' => [
    // ...defaults...
    new League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension(),
    new League\CommonMark\Extension\ExternalLink\ExternalLinkExtension(),
],
```

Class-string entries resolve through Laravel's container, so anything you can bind is fair game.

## Runtime extension hook

Register a callback when you need the live `Environment`. This covers custom inline parsers, renderers, or event listeners:

```php
use Illuminate\Support\ServiceProvider;
use League\CommonMark\Environment\Environment;
use NonConvexLabs\Commonplace\Facades\Commonplace;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Commonplace::extendMarkdown(function (Environment $env): void {
            $env->addInlineParser(new MyAdmonitionInlineParser, priority: 100);
        });
    }
}
```

> [!WARNING]
> Call `extendMarkdown()` from a service provider's `boot()` only. Calling it per-request under Octane or queue workers accumulates callbacks across requests and leaks memory. The registry freezes the first time the converter is built. Later calls throw `LogicException`.

## Swapping the wikilink resolver

The `[[wikilink]]` syntax is a CommonMark extension that delegates target lookup to a swappable [`WikilinkResolver`](../src/Contracts/WikilinkResolver.php). The default ([`Services\WikilinkParser`](../src/Services/WikilinkParser.php)) resolves against the [`Note` model](user-model.md). You can bind your own to point links at a different model or external URL:

```php
use NonConvexLabs\Commonplace\Contracts\WikilinkResolver;
use NonConvexLabs\Commonplace\Markdown\Wikilink\ResolvedWikilink;

class WikiResolver implements WikilinkResolver
{
    public function resolve(string $target): ?ResolvedWikilink
    {
        return new ResolvedWikilink(
            href: route('docs.show', ['slug' => Str::slug($target)]),
            title: $target,
        );
    }
}

// In your service provider's register():
$this->app->bind(WikilinkResolver::class, WikiResolver::class);
```

Returning `null` produces a broken-link `<a class="vault-link vault-link-broken">` whose `href` falls back to `commonplace.routes.prefix` joined to the raw target. Style the two classes from your theme. See [Theming](theming.md).

## XSS hardening

> [!WARNING]
> Removing `DisallowedRawHtmlExtension` is an XSS regression. Without it, raw `<script>` tags pass through to the output. Keep it unless you have your own sanitizer downstream.

The Environment is also configured with `html_input => 'allow'` and `allow_unsafe_links => false`, so `javascript:` URLs in user content get dropped at render time.
