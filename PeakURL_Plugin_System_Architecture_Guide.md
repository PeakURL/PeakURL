# PeakURL Plugin System Architecture Guide

**Reference Date:** October 29, 2026  
**Application Release:** PeakURL 1.8.0  
**Repository:** `PeakURL/PeakURL` (Core PHP Runtime & React Administrative Dashboard)  
**Document Status:** Internal Engineering Reference — Active Working Specification

---

## 1. System Overview & Architectural Boundaries

The PeakURL 1.8.0 Plugin Subsystem provides an extensible, modular foundation for self-hosted PeakURL instances. It is engineered with strict isolation, safety-first lifecycles, non-executing inspection, and transactional guarantees.

### Key Architectural Boundaries

1. **Separation of Concerns:**
    - **PeakURL Core (`PeakURL/PeakURL`):** Consumes local packages in `content/plugins/` and `content/mu-plugins/`, performs manifest and PHP header validation, executes authorized PHP logic via the hook system, serves protected browser assets, host-renders declarative administrative pages, and manages settings atomically.
    - **PeakURL.org (`PeakURL/PeakURL.org`):** Central publisher and directory. Hosts the canonical manifest schema (`https://peakurl.org/schemas/v1/plugin.json`), validates developer ZIP submissions, provides human review/moderation workflows, and promotes approved packages to Cloudflare R2 (`plugins.peakurl.org`).
2. **Execution Safety:**
    - Discovery, inventory listing, metadata extraction, and capability checks **never execute plugin PHP code**.
    - Activation is required before regular plugin code is loaded into memory.
    - Emergency recovery flags (`PEAKURL_SKIP_PLUGINS=1`, `PEAKURL_SKIP_MU_PLUGINS=1`) allow immediate recovery from faulty third-party code.

---

## 2. Directory Layout & Official Package Convention

### 2.1 Server Filesystem Layout

```text
/app/
├── content/
│   ├── mu-plugins/          # Must-Use plugins (auto-loaded in alphabetical order, read-only in UI)
│   │   └── enterprise-sso.php (or folder with plugin.json + plugin.php)
│   └── plugins/             # Regular plugins (activated/deactivated via database state)
│       └── example-plugin/
│           ├── plugin.json
│           └── plugin.php
```

### 2.2 Official Plugin Package Structure

PeakURL defines the following official recommended package layout:

```text
example-plugin/
├── plugin.json               # MANDATORY: Canonical JSON manifest (v1 schema)
├── plugin.php                # MANDATORY: Primary PHP entrypoint with docblock header
├── server/                   # OPTIONAL: Backend PHP classes and services
│   └── services/
│       └── social-metadata.php
├── client/                   # OPTIONAL: Frontend source code (TypeScript / React)
│   ├── main.ts
│   └── styles.css
├── assets/                   # OPTIONAL: Browser-ready compiled assets
│   ├── admin.js
│   └── admin.css
├── README.md                 # OPTIONAL: Developer documentation
├── readme.txt                # OPTIONAL: Directory listing metadata (WordPress-format sections)
└── LICENSE                   # OPTIONAL: License text
```

- **Mandatory Requirements:** Only `plugin.json` and `plugin.php` are required in the root directory.
- **Production Environment:** PeakURL servers never run Node.js, npm, Vite, or TypeScript to install or run plugins. All browser assets must be pre-compiled and placed in `assets/`.

---

## 3. Canonical Manifest & PHP Header Contracts

### 3.1 Canonical `plugin.json` (v1 Schema)

The manifest is the single authoritative source of truth for plugin identity and requirements.

#### Required Fields:

- `name` (string, 1-60 chars, trimmed): Display name.
- `version` (string): SemVer 2.0.0 format (`MAJOR.MINOR.PATCH` with optional `-prerelease` and `+build`).
- `requires` (string): Minimum PeakURL version required (`x.y.z`).
- `testedUpTo` (string): Maximum PeakURL version tested (`x.y.z`, must be `>= requires`).
- `php` (string): Minimum PHP version required (`8.3`, `8.4`, etc.).
- `license` (string, 1-50 chars, trimmed): SPDX identifier or license description (e.g., `MIT`, `GPL-2.0-or-later`).

#### Optional Canonical Fields:

- `pluginUri` (string, URL): Canonical plugin details or product page. Replaces ambiguous `homepage`.
- `description` (string, max 500 chars): Concise plain-text description for local directory listings.
- `author` (string, max 100 chars): Human-readable author or organization credit.
- `authorUri` (string, URL): Author website or profile URL.
- `licenseUri` (string, URL): Web link to full license text.
- `documentation` (string, URL): Documentation URL.
- `repository` (string, URL): Source code repository URL.
- `support` (string, URL): Support portal or forum.
- `issues` (string, URL): Public bug tracker or issues link.

_Note:_ Strict validation rejects unknown fields (`additionalProperties: false`), legacy field names (`homepage`, `homepageUrl`), and nested objects (`links`).

### 3.2 Non-Executing PHP Docblock Header

An optional docblock header in `plugin.php` is supported for developer convenience:

```php
<?php
/**
 * Plugin Name: Example Plugin
 * Plugin URI: https://example.com/plugins/example-plugin
 * Description: Extends PeakURL with custom links analytics and automated tagging.
 * Version: 1.0.0
 * Requires PeakURL: 1.8.0
 * Tested up to: 1.8.0
 * Requires PHP: 8.3
 * Author: Example Software
 * Author URI: https://example.com
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 */
```

#### Inspection & Validation Rules:

1. **Bounded Read:** `Validator::read_php_header()` reads only the first 8 KB of `plugin.php` using stream handles. It never includes, evaluates, or compiles the file.
2. **Comment Enforcement:** Only headers inside standard PHP docblocks (`/** ... */`) are parsed.
3. **No Overrides:** Header values never override `plugin.json`.
4. **Consistency Verification:** When a property exists in both `plugin.json` and the PHP header, values must match exactly (normalized case and trimmed whitespace). Discrepancies produce explicit validation errors.
5. **Rejection of Foreign Headers:** WordPress headers that have no PeakURL equivalent (e.g., `Network`, `Update URI`, `Requires Plugins`, `Text Domain`, `Domain Path`) are rejected with clear diagnostics.

---

## 4. Subsystem Components & Responsibilities

### 4.1 Registry (`server/core/plugins/registry.php`)

- Scans `content/plugins/` and `content/mu-plugins/`.
- Extracts package metadata via `Validator`.
- Caches inventory for the request duration.
- Never executes PHP files during scanning.

### 4.2 Validator (`server/core/plugins/validator.php`)

- Validates `plugin.json` against canonical v1 constraints.
- Reads and parses the non-executing PHP header.
- Cross-verifies manifest and header properties.
- Compares SemVer 2.0.0 versions (`Validator::compare_versions()`) including prerelease and build metadata.
- Validates host compatibility (`requires` vs current PeakURL version, `php` vs running PHP runtime).

### 4.3 Loader (`server/core/plugins/loader.php`)

- Runs early in the PeakURL application bootstrap (`Application::bootstrap_plugins()`).
- Loads Must-Use plugins first (`content/mu-plugins/*.php`), sorted alphabetically.
- Loads active regular plugins recorded in the `active_plugins` settings option.
- Fires `muplugins_loaded` and `plugins_loaded` actions.
- Automatically isolates plugin context in `Extensions` (`set_current_plugin()`) during require.

### 4.4 Extensions Registry (`server/core/plugins/extensions.php`)

- Central singleton for active plugins to declare administrative surfaces and extensions.
- Enforces strict `snake_case` registration contracts:
    - `register_page( string $plugin_slug, string $page_id, string $menu_title, array $options )`
    - `register_settings_schema( string $plugin_slug, array $schema )`
    - `register_action( string $plugin_slug, string $action_id, callable $callback, array $options )`
    - `register_ui_extension( string $plugin_slug, string $location, array $extension )`
    - `register_asset( string $plugin_slug, string $relative_path, string $type, array $options )`
- **Security & Integrity:**
    - Enforces current plugin context via `verify_plugin_namespace()`. A loaded plugin cannot register entities for another plugin's slug (throws 403 `ApiException`).
    - Rejects duplicate identifiers with 409 `ApiException` (prevents route hijacking and accidental collisions).
    - Validates UI extension locations (strictly allows `links_overview_actions`).

### 4.5 SystemService (`server/features/system/service.php`)

- **Plugin Management:** `get_plugins()`, `activate_plugin()`, `deactivate_plugin()`, `delete_plugin()`.
- **ZIP Installation & Local Upgrades (`upload_plugin()`):**
    - Concurrency control via non-blocking file locking (`.lock_<slug>`).
    - SemVer upgrade enforcement (`Validator::compare_versions()`). Downgrades are rejected with HTTP 400. Duplicate versions without overwrite flag are rejected with HTTP 409.
    - Safe transactional swapping: Stages new archive, moves active plugin to temporary backup, moves staged directory to target destination, runs post-move verification.
    - **Fail-Safe Rollback:** If post-move verification fails, attempts restoration. If restoration fails, **preserves the backup on disk**, sets `$backup_preserved = true`, and returns HTTP 500 containing the exact filesystem path of the backup. The `finally` block **never deletes a preserved backup**.
- **Settings Management (`update_plugin_settings()`):**
    - Validates incoming keys against the registered schema fields.
    - Validates types (`string`, `integer`, `number`, `boolean`, `select` with allowed options).
    - Rejects undeclared keys with HTTP 400.
    - Performs row-level atomic database updates using `SettingsApi::update_option_with_lock()`.
    - Fails closed on database errors (never falls back to an empty configuration).
- **Browser Asset Serving (`serve_plugin_asset()`):**
    - Authenticated administrative resource (HTTP 401 if unauthenticated; HTTP 403 if user lacks `access_dashboard` capability).
    - Requires asset to be explicitly declared via `Extensions::register_asset()`.
    - Rejects directory traversal, backslashes, URL decoding anomalies, and unnormalized paths with HTTP 400.
    - Prohibits `.php`, `.map` source maps, and sensitive configuration files with HTTP 403.
    - Emits `Cache-Control: private, no-transform, max-age=3600`, `ETag`, and correct MIME types.

---

## 5. React Dashboard Integration & Asset Lifecycle

### 5.1 Host-Rendered Declarative Admin Views

- Active plugin pages registered in `Extensions` are automatically rendered under `/dashboard/plugins/page/:pluginSlug/:pageId`.
- Menu items appear dynamically under the Dashboard Sidebar "Plugins" group.
- Declarative settings forms render native PeakURL UI components without running arbitrary third-party React scripts.
- Generic decorative icons (e.g. `Sparkles`) are eliminated in favor of clean semantic icons matching the PeakURL design system.

### 5.2 Browser Asset Lifecycle (`usePluginAssets`)

- Assets registered by active plugins are loaded only when navigating to relevant plugin admin views or extension points.
- **Script Single-Execution:** Removing a `<script>` tag from the DOM does not un-evaluate JavaScript in the global `window` scope. The client maintains an in-memory `executedScriptUrls` Set to guarantee scripts execute only once per browser session.
- **Style Scope & Cleanup:** CSS stylesheets are attached via `<link rel="stylesheet">` elements when the plugin view mounts, and removed from `<head>` when the user navigates away.

---

## 6. Verification & Test Coverage Summary

| Test Layer          | Framework      | Command                                                                                              | Assertion Count           |
| ------------------- | -------------- | ---------------------------------------------------------------------------------------------------- | ------------------------- |
| Core Unit Tests     | PHPUnit 11.5   | `docker exec peakurl-server vendor/bin/phpunit tests/Unit/Plugins/`                                  | 110 tests, 578 assertions |
| Integration Tests   | PHPUnit 11.5   | `docker exec peakurl-server vendor/bin/phpunit tests/Integration/Plugins/`                           | 9 tests, 36 assertions    |
| Coding Standards    | PHPCS 3.11     | `docker exec peakurl-server vendor/bin/phpcs --standard=../phpcs.xml core/plugins/ features/system/` | 0 errors, 0 warnings      |
| Frontend Typecheck  | TypeScript 5.7 | `npx tsc -b`                                                                                         | 0 errors                  |
| Frontend Linter     | ESLint 9       | `npm run lint:web`                                                                                   | 0 errors                  |
| Frontend Unit Tests | Playwright     | `npm run test:unit`                                                                                  | 253 passed                |
| End-to-End Tests    | Playwright     | `npx playwright test tests/e2e/dashboard/plugins.spec.ts`                                            | 5 passed (100%)           |
| Canonical Schema    | Node.js TSX    | `npx tsx tests/plugins/pluginManifestSchema.test.ts`                                                 | All scenarios passed      |
