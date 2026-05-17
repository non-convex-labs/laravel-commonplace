# laravel-commonplace

A personal markdown knowledge vault for Laravel — wikilinks, version history, semantic search, and an MCP server for Claude Code.

> Status: pre-1.0. APIs may shift between minor versions.

## Requirements

- PHP 8.4+
- Laravel 13+

## Install

```bash
composer require non-convex-labs/laravel-commonplace
php artisan vendor:publish --tag=commonplace-config
php artisan migrate
php artisan commonplace:doctor
```

See [docs/](docs/index.md) for setup, configuration, and full documentation.

## License

MIT
