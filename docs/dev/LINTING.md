# Linting and Formatting

This document explains the linting, formatting, git hook, and continuous integration (CI) quality workflows for PeakURL.

PeakURL uses a modern, high-performance quality toolchain tailored to our open-source architecture:

- **Web & Dashboard UI**: Modern ESLint Flat Config (`eslint.config.js`) with TypeScript-ESLint, React Hooks, JSX A11y accessibility standards, and Prettier formatting.
- **PHP Application Runtime**: PHP_CodeSniffer and PHPCBF using WordPress Coding Standards (WordPressCS) via `phpcs.xml`.
- **Pre-commit Automation**: Husky + `lint-staged` ensuring staged files are formatted and linted before commits.
- **Continuous Integration**: GitHub Actions CI Quality Gate (`.github/workflows/ci.yml`) enforcing format checks, linting, syntax validation, and production builds on every PR and push to `main`.

---

## Main Commands

Run the full project lint pass (Web + PHP):

```bash
npm run lint
```

Run all formatting checks:

```bash
npm run format:check
```

Apply all code formatting automatically:

```bash
npm run format
```

Verify TypeScript compilation and build output:

```bash
npm run build
```

---

## JavaScript, TypeScript & Dashboard UI

The dashboard UI source code lives under `ui/` and is built using React 19, TypeScript, and Vite.

### Linting Commands

Lint the dashboard UI with caching enabled:

```bash
npm run lint:web
```

Automatically fix lintable issues:

```bash
npm run lint:web:fix
```

### ESLint Architecture & Rules

PeakURL utilizes the modern **ESLint Flat Config** (`eslint.config.js`) equipped with:

- **`typescript-eslint`**: Strict type checking rules, explicit return types on key boundaries, safe index access enforcement, and canonical type-only imports (`@typescript-eslint/consistent-type-imports`).
- **`eslint-plugin-jsx-a11y`**: Accessible HTML & ARIA attributes, keyboard navigation listeners for interactive elements, and accessible forms.
- **`eslint-plugin-react-hooks`**: React 19 hook rule enforcement and dependency verification.
- **`eslint-plugin-react-refresh`**: Fast Refresh architectural safety.
- **Caching (`.eslintcache`)**: Instant incremental lint runs on unchanged files.

### TypeScript Conventions

- Strict configuration is enforced via `tsconfig.app.json` (including `strict: true` and `noUncheckedIndexedAccess: true`).
- Type imports must use `import type { ... } from '@/...'` for contracts, DTOs, and component prop types.
- Array indexing and dictionary lookups must account for possible `undefined` bounds (e.g. `items[0] ?? fallback`).
- For detailed TypeScript guidelines, consult the [TypeScript guide](TYPESCRIPT.md).

---

## Prettier

Prettier is configured as the canonical formatter for all web code, configuration files, JSON, YAML, CSS, and Markdown.

Format web and configuration files:

```bash
npm run format:web
```

Check formatting without modifying files:

```bash
npm run format:web:check
```

### Formatting Conventions

Standardized through [`.prettierrc.json`](../../.prettierrc.json), [`.prettierignore`](../../.prettierignore), and [`.editorconfig`](../../.editorconfig):

- **Tabs enabled** for code, CSS, and JSON
- **Spaces (Width: 2)** strictly enforced for YAML files
- Tab width: `4`
- Single quotes
- Trailing commas where valid in ES5
- Build artifacts and vendor directories are ignored (`build/`, `release/`, `content/`, `app/vendor/`, and `package-lock.json`).

---

## PHP & Backend Runtime

The backend PHP runtime lives under `app/` and `site/`.

### Linting and Standards

Run WordPress Coding Standards checks:

```bash
npm run lint:php
```

Auto-format PHP files using PHPCBF:

```bash
npm run format:php
```

Run raw PHP syntax validation (`php -l`):

```bash
npm run lint:php:syntax
```

### PHP Scope & Standards

PHP standards are defined by the repository-level [phpcs.xml](../../phpcs.xml) ruleset.

Checked directories:

- `app/api/`
- `app/bin/`
- `app/controllers/`
- `app/http/`
- `app/includes/`
- `app/public/`
- `app/services/`
- `app/store.php`
- `app/traits/`
- `app/utils/`
- `site/`

Excluded from PHP_CodeSniffer:

- `app/vendor/`
- Runtime storage and uploads (`content/`)
- `site/config-sample.php`

---

## Git Pre-Commit Hook (Husky + lint-staged)

PeakURL includes automatic pre-commit quality checks using **Husky** and **`lint-staged`**.

When you stage changes and commit:

1. Staged TypeScript/TSX files are linted with ESLint autofix and formatted with Prettier.
2. Staged JSON, Markdown, and YAML files are formatted with Prettier.
3. Staged PHP files are auto-formatted with PHPCBF.

To manually install or reset the git hooks:

```bash
npm run prepare
```

Configuration lives in [`package.json`](../../package.json) under the `"lint-staged"` property and in [`.husky/pre-commit`](../../.husky/pre-commit).

---

## GitHub Actions CI Quality Gate

Continuous Integration is configured via [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml). Every pull request and push to the `main` branch undergoes automated quality verification:

1. **Environment Setup**: Node.js 24 and PHP 8.4 with required PDO/Zip extensions.
2. **Dependency Installation**: `npm ci` & `composer install` (in `app/`).
3. **Format Check**: `npm run format:check` (Prettier & PHPCS).
4. **Linters**: `npm run lint` (ESLint with Flat Config & PHPCS with WordPressCS).
5. **PHP Syntax Check**: `npm run lint:php:syntax` (`php -l` sweep).
6. **TypeScript & Production Build**: `npm run build` (`tsc -b && vite build`).

---

## Recommended Contributor Workflow

Before opening a pull request or pushing changes, run:

```bash
# 1. Format all code
npm run format

# 2. Run all linters
npm run lint

# 3. Check PHP syntax
npm run lint:php:syntax

# 4. Verify TypeScript and production build
npm run build
```

If you only worked on the React UI:

```bash
npm run format:web
npm run lint:web:fix
npm run build
```

If you only worked on the PHP runtime:

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
