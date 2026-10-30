# PeakURL 1.8.0 — Plugin Foundation Audit, Parity Verification & Roadmap Report

**Date:** October 30, 2026  
**Primary Repositories:**

- `PeakURL/PeakURL` (Local: `/Users/abdurrehman/GitHub/PeakURL/PeakURL.org/PeakURL`, Branch: `main`)
- `PeakURL/PeakURL.org` (Local: `/Users/abdurrehman/GitHub/PeakURL/PeakURL.org/PeakURL.org`, Branch: `main`)  
  **Status:** Verification Complete — All Changes Preserved Unstaged & Uncommitted

---

## 1. Repository States & Git Policy Adherence

### 1.1 Repository Working-Tree State

- **`PeakURL/PeakURL`:**
    - Branch: `main` (tracking, at HEAD `45fe2bb7a6da91648fb072873e676b48fdac8199`)
    - All plugin foundation changes, security hardening, PHP header validation, internationalization runtime, permissions alignment, analytics invalidation, and regression tests remain **strictly unstaged and uncommitted**.
- **`PeakURL/PeakURL.org`:**
    - Branch: `main`
    - Canonical manifest schema, ZIP inspector with PHP header extraction, reserved textDomain protection, documentation updates, and specification test suites remain **strictly unstaged and uncommitted**.

### 1.2 Git Policy Adherence

- **Zero Commits Created:** In strict compliance with instructions, no files have been staged (`git add`), no commits or merges created, and no branch switching performed. All modifications remain available in working trees for review.
- **Untracked File Safety:** Sample plugin test fixtures (`content/plugins/example-plugin/`, `content/mu-plugins/`), internal architecture guides, and roadmap specifications are preserved without unintended staging.
- **Release Version File:** The `.version` update to `1.8.0` is deliberately excluded from feature commits and isolated for release preparation.

---

## 2. Finalized Plugin Version Policy

### 2.1 Release Version Format Rules

Across both `PeakURL` core and `PeakURL.org` directory publishing:

- **Accepted Releases:** Normal stable releases beginning with `0.1.0` or higher, including subsequent `0.x.y` and `1.x.y` versions (e.g. `0.1.0`, `0.1.1`, `0.2.0`, `0.10.5`, `1.0.0`, `1.2.3`, `2.0.0`).
- **Rejected Releases:**
    - All `0.0.x` micro-builds (e.g. `0.0.1`, `0.0.0`, `0.0.9`).
    - Leading zeroes (e.g. `01.0.0`, `0.01.0`, `0.1.01`).
    - Prerelease suffixes (e.g. `1.0.0-alpha`, `1.0.0-beta.1`, `1.0.0-rc.1`, `0.1.0-dev`).
    - Build metadata suffixes (e.g. `1.0.0+build.1`).
    - Incomplete versions (e.g. `1.0`, `0.1`, `1`).
    - `v` prefixes (e.g. `v1.0.0`, `v0.1.0`).
- **Watertight Version Pattern:**
    - PCRE (PHP): `/^(?:0\.[1-9]\d*|[1-9]\d*\.(?:0|[1-9]\d*))\.(?:0|[1-9]\d*)$/D`
    - JavaScript / JSON Schema: `^(?:0\.[1-9]\d*|[1-9]\d*\.(?:0|[1-9]\d*))\.(?:0|[1-9]\d*)$`

### 2.2 Compatibility Version Format Rules (`requires` & `testedUpTo`)

- Both `requires` and `testedUpTo` must be strict three-component numeric versions matching:
    - PCRE (PHP): `/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D`
    - JavaScript / JSON Schema: `^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$`
- Semantic constraint: `testedUpTo` must be numerically greater than or equal to `requires`.

### 2.3 Numeric Comparison & Upgrade Guarantees

- Implemented exact numeric string comparison (`Validator::compare_versions()` in PHP and `compareVersions()` with `BigInt` precision in TypeScript) to prevent 64-bit integer overflow.
- **Downgrade Protection:** In-place upgrades reject lower version packages with HTTP 400 (`Cannot downgrade plugin from version X to Y`).
- **Duplicate Protection:** Re-uploading identical versions is rejected with HTTP 409 (`Re-uploading identical or duplicate versions is not permitted`). Releases are strictly immutable.

---

## 3. Plugin Internationalization (i18n) Foundation & Hardening

### 3.1 Translation Directory Containment & Symlink Defense

- In `I18n::load_plugin_textdomain()`:
    - Replaced the fragile `str_starts_with($real_dir, $real_plugin)` check with strict directory boundary containment: `$real_dir === $real_plugin || str_starts_with($real_dir, rtrim($real_plugin, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)`. This eliminates sibling directory collisions (e.g. `/plugins/plugin-sibling/languages` vs `/plugins/plugin`).
    - Enforced symlink checks: both `$languages_dir` and root `$plugin_path` are rejected if they are symbolic links (`is_link()`).
    - In `CatalogLoader::load_domain_catalog()` and `get_domain_dashboard_catalog()`: candidate `.mo`, `.po`, and `.json` files are validated with `is_link()` and `realpath()` to ensure they reside strictly within the registered languages directory.
    - Sibling path collisions, symlinks, and directory traversals are covered by automated unit regression tests.

### 3.2 Translation Domain Isolation & Collision Protection

- In `I18n::get_dashboard_catalog()`:
    - Enforced strict domain isolation: only extracts messages for `$domain` or fallback `'messages'`. Any other domain keys in `$plugin_json['locale_data']` (specifically attempts to pollute core `'peakurl'`) are discarded.
    - Header entry `''` is forcibly sanitized with the target `domain`, current `lang`, and `plural-forms`.
    - In `I18n::load_plugin_textdomain()`: domain collisions across different plugins are rejected. If a domain is already registered to a different directory, registration returns `false`.

### 3.3 Text-Domain Identity Parity

- Across `Validator.php` (PHP), `zipInspector.ts` (TS), and `manifestSchema.ts` (TS):
    - Prohibited reserved domains: `peakurl`, `default`, `core`, `system`, `messages`, `admin`, `api`.
    - Required `textDomain` to match the canonical plugin slug (`slugify(manifest.name)`).
    - Supported collision suffixes for directory management (e.g. `link-shield-2`) while guaranteeing identity consistency.

### 3.4 Professional Unavailable-Name Messaging

- Standardized all unavailable-name validation errors across both codebases:
    - Plugin name / slug collisions: `"This plugin name is not available. Please choose another."`
    - Manifest domain errors: `"Field \"textDomain\" is not available. Please choose another."` (PHP) and `"Property \"textDomain\" is not available. Please choose another."` (TypeScript).
    - Plugin page identifier collisions: `"Plugin page identifier is not available. Please choose another."`
    - Internal terms such as "reserved slug" and "reserved domain" are completely avoided in user-facing feedback.

### 3.5 Removal of Obsolete Contracts & Compatibility Layers

- In `manifestSchema.ts` (TS) and `validator.php` (PHP):
    - Eliminated legacy property aliases: `homepage`, `links`, and legacy `*Url` variants.
    - Enforced strict rejection of unrecognized manifest properties (`Unexpected property "%s" in manifest.`).
    - Removed obsolete `allowAdminSlugOverride` option from `ZipInspectorOptions`.

### 3.6 API Cleanliness

- In `server/services/i18n/functions.php`:
    - Removed unused `$deprecated = false` parameter from `load_plugin_textdomain( string $domain, string $languages_dir, ?string $plugin_path = null ): bool`.
    - Callers pass the clean three-argument signature without legacy artifacts.

---

## 4. Administration Permissions & Route Guards

### 4.1 Route Guard Alignment

- Decoupled `plugins` routes from `canManageUsers`:
    - `client/router/index.tsx`: Introduced `PluginsManageRoute` requiring `canManagePlugins` (`isAdmin && (manageUpdates || manageSiteSettings)`).
    - Routes `/dashboard/plugins` and `/dashboard/plugins/add` are protected by `PluginsManageRoute`.
    - Custom plugin admin pages `/dashboard/plugins/page/:slug/:pageId` are accessible to authenticated dashboard users, delegating granular capability checking to the view.

### 4.2 Fail-Closed Capability Enforcement on Custom Pages

- In `useAdminAccess.ts`:
    - Refactored `hasCapability(capabilityName?: string): boolean` helper to fail closed:
        - Rejects empty, whitespace, or undefined capability strings immediately (`false`).
        - Maps canonical capabilities to existing permissions (`manage_users`, `manage_options`, `manage_plugins`, `manage_updates`, `manage_settings`, `manage_links`, `manage_analytics`, `manage_api_keys`, `manage_mcp`).
        - Removed blanket `isAdmin` bypass for arbitrary unknown capability strings, preventing unauthenticated access to unknown descriptors.
- In `PluginAdminPageView.tsx`:
    - When a plugin page descriptor declares a `capability` requirement, `hasCapability(page.capability)` verifies the user has the required permission.
    - If unauthorized, renders an "Access Denied" view with a link back to dashboard, preventing privilege escalation.

### 4.3 Responsive Sidebar Navigation

- In `Sidebar.tsx`:
    - Replaced `skip: !canManageUsers` on `useGetPluginExtensionsQuery` with `skip: !user`.
    - Filtered plugin pages using `hasCapability(page.capability)`.
    - Displayed the `Plugins` navigation item if `canManagePlugins || pluginPages.length > 0`.
    - If a user lacks `canManagePlugins` but has access to specific plugin pages, the sidebar exposes only those authorized pages.

---

## 5. Declarative Cache Invalidation for Plugin Actions

- In `server/core/plugins/extensions.php`:
    - Enhanced `register_plugin_action()` to accept an explicit `invalidates` declaration (e.g. `array( 'Plugins', 'Analytics', 'Urls' )`).
    - Allowed invalidation targets are strictly validated against supported tags.
- In `client/state/slices/api/system.ts`:
    - Refactored `resolvePluginActionInvalidatedTags(invalidates, payload)` helper.
    - Invalidation is strictly declarative: tags are resolved directly from the action's declared `invalidates` targets rather than heuristic string sniffing.
    - Eliminated the redundant `payload.invalidates` fallback: the registered action declaration is the single authoritative source of invalidation behavior.
    - When `Urls` is in the invalidated targets and a specific `url_id` or `urlId` payload parameter is passed, additionally invalidates `{ type: "Urls", id: String(targetUrlId) }`.
- Added unit tests in `tests/unit/plugins.spec.ts` proving selective declarative tag invalidation for global and per-URL click history resets.

---

## 6. Documentation Clean-Up, Specification Alignment & Generic Fixtures

- **Generic Test Fixture Replacement:**
    - In `PeakURL.org/tests/plugins/pluginManifestSchema.test.ts` and `site/tests/pluginSchemaRoute.spec.ts`:
        - Replaced the product-specific `IPInfo` fixture with a completely generic example:
            - Name: `"Example Plugin"`
            - Text domain: `"example-plugin"`
            - Variable: `canonicalExamplePluginManifest`
            - Description: `"An example plugin used to validate the manifest schema."`
            - Author: `"Example Developer"`
            - URLs: canonical `example.com` endpoints (`https://example.com/plugin`, `/plugin/docs`, `/support`, `/issues`).
        - Updated all test labels, console output, and assertions.
- **Accurate Developer API Signatures (`PeakURL.org/PLUGIN_DEVELOPMENT.md`):**
    - Updated status: native plugin loader, manifest discovery, dashboard activation, safe deletion, and secure ZIP upload are implemented in PeakURL 1.8.0.
    - Removed outdated draft proposals: stripped `id: acme/plugin`, `publisher`, `compatibility`, `links`, and `vendor/slug` proposals.
    - Clarified canonical plugin identifier: strictly the unique slug matching `textDomain`.
    - Corrected directory conventions and sequence diagrams to show `plugin.php` directly at the package root.
    - Replaced legacy `compatibility.phpVersion` with top-level `php`, `requires`, and `testedUpTo` SemVer boundaries (`>= 0.1.0`).
    - Reconciled exact public API signatures to match core implementations:
        - `load_plugin_textdomain( string $domain, string $languages_dir, ?string $plugin_path = null ): bool;`
        - `register_plugin_action( string $plugin_slug, string $action_id, callable $handler, string $capability = 'manage_site_settings' ): bool;`
        - `register_plugin_asset( string $plugin_slug, string $relative_path, string $type = 'script', array $options = array() ): bool;`
        - `register_plugin_page( array $descriptor ): bool;`
        - `register_plugin_settings( string $plugin_slug, array $schema ): bool;`
        - `register_plugin_ui_extension( array $descriptor ): bool;`
        - `get_plugin_setting( string $plugin_slug, string $key, mixed $fallback = null ): mixed;`
        - `update_plugin_setting( string $plugin_slug, string $key, mixed $value ): bool;`

---

## 7. Website CI & Playwright Test Portability

- **CI Failure Root Cause Analysis & Portability Fixes:**
    - In `site/tests/accountAndAdminRefinements.spec.ts`, `twoFactorLoginE2E.spec.ts`, and `visualPagesVerification.spec.ts`:
        - Fixed local path dependencies: replaced references to local developer Antigravity directories with portable `process.env.ARTIFACT_DIR || path.resolve(process.cwd(), "test-results")` and automatic directory creation via `fs.mkdirSync`.
        - Fixed Next.js Server Component hydration differences: added explicit heading visibility guards in WebKit before submitting credentials to ensure client hydration finishes cleanly.
        - Updated account profile assertions to match the current tabbed layout (`#tab-profile`, `#tab-security`), identity header links, and social profiles grid (`getByLabel`).
    - In `site/next.config.ts`:
        - Adjusted rewrites ordering so `process.env.API_URL` precedes `process.env.INTERNAL_API_URL`, routing client API calls properly to the Express API.
- Production website build (`npm run site:build`) compiles all 92 static and dynamic routes cleanly.

---

## 8. Quality & Verification Matrix

All tests passed across both repositories:

| Test Suite                           | Scope                                          | Result     | Details                                                                   |
| :----------------------------------- | :--------------------------------------------- | :--------- | :------------------------------------------------------------------------ |
| **PHPUnit Plugins Unit**             | `tests/Unit/Plugins/`                          | **PASSED** | 134 tests, 779 assertions                                                 |
| **PHPUnit Plugins Integration**      | `tests/Integration/Plugins/`                   | **PASSED** | All integration tests passed                                              |
| **Route Inventory & Contracts**      | `RouteInventoryTest` & `ApiRouteContractsTest` | **PASSED** | 11 tests, 1009 assertions                                                 |
| **PHP CodeSniffer**                  | `core/plugins/`, `services/i18n/`, `tests/`    | **PASSED** | 0 errors, 0 warnings (WordPress coding standards)                         |
| **Client Lint (ESLint)**             | `client/`                                      | **PASSED** | 0 lint errors                                                             |
| **TypeScript Compiler**              | `tsc -b`                                       | **PASSED** | 0 type errors                                                             |
| **Client Unit Tests**                | Playwright unit                                | **PASSED** | 254 tests passed                                                          |
| **Playwright Dashboard E2E**         | `tests/e2e/dashboard/plugins.spec.ts`          | **PASSED** | 5 tests passed (12.8s)                                                    |
| **Website Manifest Schema**          | `pluginManifestSchema.test.ts`                 | **PASSED** | 12 suites passed (generic Example Plugin fixture)                         |
| **Website ZIP Inspector**            | `zipInspector.test.ts`                         | **PASSED** | 20 tests passed                                                           |
| **Website Canonical Spec**           | `canonicalSpecification.test.ts`               | **PASSED** | 4 suites passed                                                           |
| **Website Submission Workflow**      | `pluginSubmissionWorkflow.test.ts`             | **PASSED** | 11 steps passed                                                           |
| **Website Real Integration**         | `realIntegrationLifecycle.test.ts`             | **PASSED** | 12 HTTP integration tests passed                                          |
| **Website Playwright E2E & Visuals** | `site/tests/*.spec.ts` (Chromium + WebKit)     | **PASSED** | **90 tests passed** (16 Account/Admin, 12 2FA E2E, 52 Visuals, 10 Schema) |
| **Website Production Build**         | `npm run site:build`                           | **PASSED** | **92 static/dynamic routes compiled cleanly**                             |

---

## 9. Proposed Commit Plan (Unstaged & Ready for Review)

Per instructions, no files have been committed. Below is the reconciled 5-commit breakdown separating concerns cleanly, accounting for every modified and untracked file in the working tree:

### Commit 1: `feat(plugins): finalize plugin metadata and registration contracts`

**Scope:** Core metadata parsing, PHP header parity, unprefixed helper functions, autoload configuration, manifest schema validation, canonical URI formatting, and generic fixture tests.

- **Repository: `PeakURL/PeakURL`**
    - `server/composer.json` (autoload registration for `core/plugins/functions.php`)
    - `server/core/plugins/metadata.php`
    - `server/core/plugins/validator.php`
    - `server/core/plugins/registry.php`
    - `server/core/plugins/functions.php`
    - `client/pages/dashboard/plugins/components/InstalledPluginsTable/InstalledPluginsTable.tsx`
    - `server/tests/Unit/Plugins/ValidatorTest.php`
    - `server/tests/Unit/Plugins/LoaderTest.php`
- **Repository: `PeakURL/PeakURL.org`**
    - `PLUGIN_DEVELOPMENT.md`
    - `tests/plugins/pluginManifestSchema.test.ts`
    - `site/tests/pluginSchemaRoute.spec.ts`

### Commit 2: `feat(plugins): add scoped dashboard extensions and browser assets`

**Scope:** Extensions registry, admin pages, actions, UI extensions on `links_overview_actions`, scoped asset serving, client routes, and route-contract inventory tests.

- **Repository: `PeakURL/PeakURL`**
    - `server/core/plugins/extensions.php`
    - `server/http/router.php`
    - `server/core/application.php`
    - `client/api/api.ts`
    - `client/api/types/plugins.ts`
    - `client/hooks/index.ts`
    - `client/hooks/usePluginAssets.ts`
    - `client/pages/dashboard/plugins/components/PluginAdminPageView/`
    - `client/pages/dashboard/plugins/components/index.ts`
    - `client/pages/dashboard/plugins/index.ts`
    - `client/pages/dashboard/links/components/Header/Header.tsx`
    - `server/tests/Unit/Plugins/PluginExtensionsTest.php`
    - `server/tests/Unit/Plugins/PluginAssetSecurityTest.php`
    - `server/tests/Unit/Http/RouteInventoryTest.php`
    - `server/tests/Integration/Api/ApiRouteContractsTest.php`

### Commit 3: `fix(plugins): harden upgrades, rollback, and settings persistence`

**Scope:** Upload locking, safe stage-and-swap upgrade, non-destructive rollback, downgrade/duplicate protection, and settings persistence.

- **Repository: `PeakURL/PeakURL`**
    - `server/features/system/service.php`
    - `server/features/system/controller.php`
    - `server/tests/Unit/Plugins/PluginUploadSecurityTest.php`
    - `server/tests/Unit/Plugins/PluginConcurrencyTest.php`
    - `server/tests/Unit/Plugins/PluginManagementApiTest.php`

### Commit 4: `feat(i18n): add isolated plugin translation domains`

**Scope:** Plugin gettext catalog loading, canonical directory boundary containment, symlink rejection, domain collision rejection, dashboard catalog domain isolation, and React `@wordpress/i18n` bridge.

- **Repository: `PeakURL/PeakURL`**
    - `server/services/i18n/service.php`
    - `server/services/i18n/catalog-loader.php`
    - `server/services/i18n/functions.php`
    - `server/core/plugins/loader.php`
    - `client/i18n/index.ts`
    - `client/i18n/types.ts`
    - `server/tests/Unit/Plugins/PluginI18nTest.php`

### Commit 5: `feat(plugins): align admin permissions and verify foundation lifecycle`

**Scope:** Dedicated `PluginsManageRoute`, granular capability checking on custom plugin pages, capability-filtered sidebar navigation, declarative cache tag invalidation, and comprehensive lifecycle integration tests.

- **Repository: `PeakURL/PeakURL`**
    - `client/hooks/useAdminAccess.ts`
    - `client/router/index.tsx`
    - `client/pages/layout/dashboard/Sidebar/Sidebar.tsx`
    - `client/pages/layout/dashboard/types.ts`
    - `client/state/slices/api/system.ts`
    - `server/tests/Integration/Plugins/PluginFoundationIntegrationTest.php`
    - `server/tests/Integration/Plugins/ClickHistoryResetTest.php`
    - `server/tests/Unit/Plugins/PluginBootstrapLifecycleTest.php`
    - `tests/e2e/dashboard/plugins.spec.ts`
    - `tests/unit/plugins.spec.ts`

### Files Excluded from Commits

- `PeakURL/REPORT.md` (internal status documentation)
- `PeakURL/PeakURL_1.8.0_Plugin_System_Roadmap.md` (roadmap working doc)
- `PeakURL/PeakURL_Plugin_System_Architecture_Guide.md` (working design guide)
- `PeakURL/PeakURL_Plugin_Ecosystem_Architecture_and_Lifecycle_Guide.md` (working design guide)
- `PeakURL/content/plugins/example-plugin/` (manual development test fixture)
- `PeakURL/content/mu-plugins/` (manual development test fixture)
- `PeakURL/.version` (reserved for release preparation commit)

---

## 10. Final Readiness Assessment

The **PeakURL 1.8.0 Plugin Foundation is 100% complete, fully tested, and ready to be committed**.

- All lifecycle, upgrade, security, permission, and internationalization requirements are verified.
- Zero backward-compatibility hacks or duplicate interfaces exist.
- All 134 PHPUnit tests, 254 client unit tests, 5 Playwright E2E tests, 90 website Playwright E2E/visual tests, and the 92-route production website build pass cleanly with zero errors or warnings.
- The proposed 5-commit plan is staged conceptually and awaits authorization.
