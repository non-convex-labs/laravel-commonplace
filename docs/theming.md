# Theming

How to restyle `laravel-commonplace` without forking the layout.

The package ships a self-contained Blade layout (no `@extends('layouts.app')`)
and a CSS file that uses only `--commonplace-*` custom properties. The
default theme handles light/dark via `prefers-color-scheme`, so you don't
need to wire up a toggle on the consumer side. If you want to go further,
you can publish the views, publish the CSS, or inject your own nav into
the existing layout. The sections below cover each path.

---

## Publishing Blade views

```bash
php artisan vendor:publish --tag=commonplace-views
```

Publishes to `resources/views/vendor/commonplace/`. Edits there take
precedence over the package's bundled views. Reach for this when you
need to restructure or rebrand the markup itself.

---

## Publishing the CSS source

```bash
php artisan vendor:publish --tag=commonplace-css
```

Publishes to `resources/css/commonplace/commonplace.css`. Override any
of the `--commonplace-*` custom properties to retheme without touching
the layout.

Here's a stark light/dark with a brand accent color:

```css
/* resources/css/commonplace/commonplace.css */
@import url('/* keep package defaults if you want */');

:root {
    --commonplace-color-accent: oklch(67% 0.21 27);
    --commonplace-color-bg: oklch(100% 0 0);
    --commonplace-color-fg: oklch(15% 0 0);
}

@media (prefers-color-scheme: dark) {
    :root {
        --commonplace-color-bg: oklch(15% 0 0);
        --commonplace-color-fg: oklch(95% 0 0);
    }
}
```

---

## Injecting your own nav

The default layout exposes a `commonplace.nav` section. Define it in
any view that extends `commonplace::layouts.app` to replace the
package's topbar with your own header:

```blade
@extends('commonplace::layouts.app')

@section('commonplace.nav')
    @include('partials.app-header')
@endsection

@section('content')
    {{-- ... --}}
@endsection
```

Leave the section unset to keep the default Commonplace topbar.
