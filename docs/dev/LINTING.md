# Linting and Formatting

This document explains the linting and formatting workflow for PeakURL.

PeakURL uses different tools for the dashboard UI and the PHP application runtime. JavaScript and TypeScript files are checked with ESLint and formatted with Prettier. PHP files are checked and formatted with PHP_CodeSniffer and PHPCBF using WordPress Coding Standards.

## Main Commands

Run the main lint pass:

```bash
npm run lint
```

Run all formatting checks:

```bash
npm run format:check
```

Apply formatting automatically:

```bash
npm run format
```

## JavaScript and TypeScript

Lint the dashboard UI:

```bash
npm run lint:web
```

This runs ESLint against the `ui/` source tree:

```bash
eslint ui
```

Format web and config files:

```bash
npm run format:web
```

Check formatting without changing files:

```bash
npm run format:web:check
```

Notes:

- The dashboard UI is under `ui/`.
- Many TSX files still use `// @ts-nocheck` as part of the ongoing migration. That is expected for now.
- The goal is to improve typing gradually, not to loosen linting further or revert files back to JSX.

## PHP

Run PHP coding standards:

```bash
npm run lint:php
```

That delegates to Composer:

```bash
composer --working-dir=app run phpcs
```

Auto-format PHP files:

```bash
npm run format:php
```

That delegates to:

```bash
composer --working-dir=app run phpcbf
```

Run a raw PHP syntax sweep:

```bash
npm run lint:php:syntax
```

PHP standards are defined by the repo-root [phpcs.xml](../../phpcs.xml) ruleset.

The current PHP lint scope includes:

- `app/api`
- `app/bin`
- `app/controllers`
- `app/http`
- `app/includes`
- `app/public`
- `app/services`
- `app/store.php`
- `app/traits`
- `app/utils`
- `site`

Excluded from PHP_CodeSniffer:

- `vendor/`
- generated storage/runtime areas
- `site/config-sample.php`

## Recommended Workflow

For most changes:

```bash
npm run format
npm run lint
npm run build
```

If you only changed UI files:

```bash
npm run format:web
npm run lint:web
npm run build
```

If you only changed PHP files:

```bash
npm run format:php
npm run lint:php
npm run lint:php:syntax
```

## Editor Notes

If you use VS Code, the previous workspace settings are documented in the [VS Code workspace guide](VSCODE.md).

Those settings are helpful because they:

- format web files with Prettier
- avoid formatting PHP with the wrong formatter
- use `phpcbf` for PHP fixes
- reduce unnecessary file watching in generated folders such as `build/`, `release/`, `app/vendor/`, and `content/`
