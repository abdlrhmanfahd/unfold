# Unfold

Interactive engineering project presenter built as a WordPress plugin (`wp-content/plugins/unfold`) and block theme (`wp-content/themes/unfold-theme`). Renders block diagrams, subsystem simulations, and results vs. targets from a structured project JSON document.

## Repository Structure

- `wp-content/plugins/unfold/` — Core Unfold plugin (PHP classes, schema, sample project, JS/CSS, vendored front-end libraries).
- `wp-content/themes/unfold-theme/` — Custom WordPress block theme (`theme.json`, templates, parts).
- `tests/` — PHPUnit unit tests, JS/schema/theme integrity tests, and WordPress Studio smoke test.
- `.github/workflows/ci.yml` — Automated CI checks running on every PR and push to `main`.

## Local Development (WordPress Studio)

```powershell
studio status
studio start --skip-browser
```

Run WP-CLI commands through Studio:

```powershell
studio wp plugin list
studio wp eval-file tests/studio-smoke.php
```

## Running Tests & Checks

```powershell
npm run check
```

PHP tests (requires PHP 8.4+ and Composer):

```powershell
composer install
vendor/bin/phpunit
```

## Workflow

All changes go through feature/fix branches and Pull Requests against `main`. See [CONTRIBUTING.md](CONTRIBUTING.md) for details.
