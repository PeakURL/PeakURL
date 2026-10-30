# PeakURL 1.8.0 — Plugin System Roadmap

**Reference revision:** 1.4 — October 29, 2026  
**Core source reviewed:** [`PeakURL/PeakURL@dd71c3b8`](https://github.com/PeakURL/PeakURL/commit/dd71c3b86aecad89fc2dec9c65b873e3e83cdcdd)  
**Official manifest currently published:** [`https://peakurl.org/schemas/v1/plugin.json`](https://peakurl.org/schemas/v1/plugin.json)  
**Comprehensive companion:** `PeakURL_Plugin_Ecosystem_Architecture_and_Lifecycle_Guide_v1_4.md`, Sections 24–26  
**Ownership boundary:** Core foundation now. Official website/R2 publication **later**.  
**In-flight work:** Completed. All core foundation stages (P4-E1, P4-E2, P4-F, and End-to-End Integration Gate) are fully implemented, verified with tests, and committed with GPG signatures (`a78dfce4`, `72359b39`, `51aee29f`, `3c77df21`).

## 1. What is already implemented

| Phase                                     | Actual status                        | What is covered                                                                                                                                            |
| ----------------------------------------- | ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **P4-A — Hook foundation**                | Implemented                          | WordPress-inspired `add_action` / `add_filter` and lifecycle hooks, including published CAPTCHA filter                                                     |
| **P4-B — Registry and validation**        | Implemented                          | Regular and MU plugin folder discovery, strict `plugin.json` v1, compatibility, safe metadata and diagnostics; inventory does not execute PHP              |
| **P4-C — Runtime lifecycle**              | Implemented                          | Loader executes valid MU first and enabled regular afterward; `muplugins_loaded` / `plugins_loaded`; early HTTP, API, cron bootstrap; emergency skip flags |
| **P4-D — Local management**               | Implemented                          | Inventory, active state, activate/deactivate, safe delete, Installed/Must-Use views, conditional tabs, Add New browser                                     |
| **P4-E1 — Admin pages & settings**        | Implemented (`72359b39`, `51aee29f`) | Host-rendered declarative settings UI, namespaced routes, SettingsApi persistence, capability gates, custom action handlers                                |
| **P4-E2 — Assets & UI slots**             | Implemented (`72359b39`, `51aee29f`) | Narrow capability-scoped asset delivery, traversal/MIME security, scoped asset mounting without global injection, links overview actions slot              |
| **P4-F — ZIP install & local upgrades**   | Implemented (`a78dfce4`)             | Authorized ZIP install & update, SemVer progression, downgrade prevention, atomic directory swap with rollback, active state preservation                  |
| **Final gate — Acceptance tests**         | Implemented (`3c77df21`)             | End-to-end integration test suite demonstrating class loading, hooks, pages, settings, assets, action dispatch, lifecycle, and upgrades                    |
| **P4-G — Public catalog display portion** | Implemented                          | Public API browsing in Add New, search/sort and detail/download links; **not** automatic approved-remote installation                                      |

The most recent core UI commit is [`dd71c3b8`](https://github.com/PeakURL/PeakURL/commit/dd71c3b86aecad89fc2dec9c65b873e3e83cdcdd). Reported PHPUnit/Playwright results are development verification records; this document did not rerun suites. The local/untracked sample plugins are intentionally not part of the committed core.

## 2. Core foundation to finish before marketplace work

| Priority | Phase          | Stage                                         | Required result                                                                                                                           | Status                                      |
| -------- | -------------- | --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------- |
| 1        | **P4-E1**      | Native plugin admin pages and settings        | Namespaced pages/menus and protected settings/API handlers, using existing PHP hooks and host React components                            | **Implemented (`72359b39`, `51aee29f`)**    |
| 2        | **P4-E2**      | Compiled browser assets and typed UI slots    | Narrow, capability-scoped asset delivery; load JS/CSS only where used; safe extension action in an existing screen                        | **Implemented (`72359b39`, `51aee29f`)**    |
| 3        | **P4-F**       | Local regular-plugin upgrades and rollback    | Validate new ZIP, same plugin identity, increasing SemVer, atomic replacement, original data/state retained, failed upgrade rolled back   | **Implemented (`a78dfce4`)**                |
| 4        | **P4-H**       | Official package metadata and PHP headers     | Developer convention, optional bounded header reader, schema/manifest parity across PHP and website, precise mismatch diagnostics         | **Planned next**                            |
| 5        | **Final gate** | End-to-end PHP + JS example and documentation | Real plugin demonstrates safe hooks, API, UI, assets, activation, 1.0.0→1.1.0, fallback/recovery, tests and developer guide               | **Implemented (`3c77df21`)**                |
| 6        | **P4-G later** | PeakURL.org distribution integration          | Independent release moderation, existing `peakurl` R2 bucket publication, public checksum API, direct installs and approved update checks | **Deferred until core foundation complete** |

No central `PeakURL/plugins` GitHub registry; individual GitHub repositories are optional source references, not the published package authority.

## 3. Official plugin package convention

```text
example-plugin/
├── plugin.json                   # REQUIRED
├── plugin.php                    # REQUIRED
├── server/                       # Optional plugin PHP
│   └── services/
│       └── social-metadata.php
├── client/                       # Optional development JS/TS
│   ├── main.ts
│   └── styles.css
├── assets/                       # Optional compiled JS/CSS
│   ├── admin.js
│   └── admin.css
├── README.md                     # Recommended local guide
├── readme.txt                    # Recommended directory readme
└── LICENSE
```

Official standard: only the two root files are mandatory. Names of internal directories are a **recommended developer convention**, not a hard requirement in the ZIP validator. The loader executes only root `plugin.php`; PHP classes are explicitly loaded. `client/` source must be built by authors before distribution; no Node/Vite dependency on the self-hosted server. `assets/` is **not** automatically public or executed until P4-E2 defines its scoped registration and serving contract.

## 4. PHP metadata header plan

WordPress's documented `Plugin Name`, `Plugin URI`, `Description`, `Version`, `Requires at least`, `Requires PHP`, `Author`, `Author URI`, `License` and other PHP header fields are a useful ergonomic model, but **PeakURL is not binary-compatible with WordPress plugins**. WordPress documentation: <https://developer.wordpress.org/plugins/plugin-basics/header-requirements/> (updated March 11, 2026).

**Decision:** Keep `plugin.json` required and authoritative. Support an optional, familiar `plugin.php` header, parsed **without evaluating PHP**. If a header repeats `name`, `version`, PeakURL/PHP minimum, tested-up-to or license values, reject contradictory metadata. The PHP-only header must never become a manifest fallback.

Suggested official labels: `Plugin Name`, `Plugin URI`, `Description`, `Version`, **`Requires PeakURL`**, `Tested Up To`, `Requires PHP`, `Author`, `Author URI`, `License`, `License URI`.

Proposed _optional_ JSON fields (not yet supported by published v1): `description` (short plain text), `author` (claimed credit string) and `authorUri` (HTTP/S URL). Do not add WordPress-only `Network`, `Update URI`, or `Requires Plugins` until PeakURL provides corresponding features. Keep `slug`, `id`, `entry`, nested `links` and arbitrary author objects outside the contract. Publisher verification belongs to the website account/moderation system and must not be inferred from an `Author` comment.

**Schema-release caution:** the public v1 schema already rejects `description` and `author`; an old strict validator would also reject a new optional field. Choose either synchronized pre-release v1 refinement or explicitly publish v2 if third-party compatibility has already been promised. Do not change website/PHP source until the current Antigravity work has been reviewed; update both validators, public schema, PHP header reader and tests in one reviewed stage.

## 5. Phase-specific engineering acceptance

### P4-E1 — Admin pages / settings

- Registered menu IDs and page routes are namespaced; core routes and sibling plugin IDs cannot be overwritten.
- Page descriptors are typed and host-rendered; no arbitrary HTML or unmanaged React injection.
- Backend checks authorization for read/write operations, independent of browser visibility.
- Settings namespaced to plugin identity; invalid data rejected; secrets remain server-side.
- Pages disappear from navigation and handlers are unavailable when regular plugin deactivated.

### P4-E2 — Browser assets / existing-page actions

- Only named `assets/` files under a validated, contained, active plugin package are served.
- Prevent traversal, symlinks, dotfiles, `.php`, configuration files, unauthorized use and spoofed asset types.
- Correct content type, cache policy, CSP and scope; no dashboard-wide loading of all plugin code.
- React renders well-defined extension points, and PHP API controls permission and request validation.
- Verify a concrete, useful dashboard action without undocumented DOM selectors or a separate frontend framework.

### P4-F — Local upgrades

- Initial install and in-place upgrade are distinct operations; no unintended overwrite of installed IDs.
- New version identity matches the installed ID, with strictly newer SemVer and compatible PHP/PeakURL requirements.
- Preserve active state and plugin data, stage under destination filesystem, verify before and after swap and rollback on failure.
- Existing MU packages are not updated through the dashboard; old code already included during an HTTP request is not retroactively unloaded.
- Recovery works under `PEAKURL_SKIP_PLUGINS=1` and `PEAKURL_SKIP_MU_PLUGINS=1`.

### P4-H — Manifest/header parity

- Main PHP file read only within a bounded prefix (e.g. 8 KiB), without execution.
- Header missing = permitted; manifest missing/invalid = rejected. Recognized duplicates, wrong PHP header source or contradictory values = clear rejection.
- Test BOM, CRLF, invalid UTF-8/control chars, long values, unsafe URLs, header outside opening comment and inconsistent SemVer/PHP minimum.
- Both Node ZIP inspector and PHP Registry/Validator agree on the same ZIP fixtures, as do public schema and TypeScript types.
- Author credit is not presented as verified publisher identity without account provenance.

### Final acceptance

- Create a **development-only** PHP+JS example demonstrating real hooks, protected API, page, JS/CSS, native UI action, settings, activation, deactivation and local upgrade.
- Verify root redirects, regular API requests, admin UI, CLI/cron, error recovery and core update preservation.
- Run focused PHPUnit, PHPCS, TypeScript, ESLint, Playwright including browser light/dark/mobile; record actual counts and evidence.
- Update the ecosystem guide, core architecture guide and roadmap **in place** after code acceptance, using observed behavior rather than planned contracts.

## 6. Git and workstream safety

Antigravity is already working on P4-E/P4-F. Do not overlap modifications until it returns a detailed report and diff. Keep subsequent metadata/header work separate and reviewable. Stage **only** reviewed files; do not stage sample plugins, internal docs, temporary reports, unrelated untracked files, or `.env`. Use concise signed **local commits**, as the user now permits, but **do not push**. Report exact commits, tests, and residual risks. Do not reset, stash, merge, or switch branches.

## 7. Next Phase Roadmap — Community Plugin Submission, Moderation, R2 Publication & Remote Updates

Now that the core plugin foundation (Phase 4) is complete and verified across both repositories with complete contract parity, the next milestone focuses on the public ecosystem lifecycle:

### Phase 5.1 — Developer Submission & Draft Package Staging

- **Developer Submission Portal:**
    - Route: `/account/plugins/new` and `/account/plugins/[id]` on `PeakURL.org`.
    - Author uploads release candidate ZIP; in-memory `inspectPluginZipPackage` enforces strict v1 schema, PHP header agreement, SemVer `>= 0.1.0`, security constraints, and media validation.
    - Staged packages stored in private staging directory (or private staging bucket prefix) pending human moderation.
    - Generates package SHA-256 and records initial draft release record with audit log.

### Phase 5.2 — Admin Moderation & Approval Workflow

- **Moderation Queue:**
    - Admin review dashboard at `/admin/plugins` on `PeakURL.org`.
    - Shows pending submissions, parsed manifest, extracted PHP headers, security inspection findings, sanitized `readme.txt` preview, and developer audit logs.
    - Moderation states: `draft` -> `submitted` -> `changes_requested` <-> `re_submitted` -> `approved` -> `published` (or `rejected` / `suspended`).
    - Strict isolation: unapproved or suspended submissions are completely hidden from the public directory.

### Phase 5.3 — Immutable Cloudflare R2 Publication (`plugins.peakurl.org`)

- **Artifact Promotion:**
    - On admin approval, promoted automatically to the official Cloudflare R2 bucket (`peakurl`).
    - Key convention: `plugin/{slug}-{version}.zip`.
    - Media key convention: `plugin/{slug}/icon.webp`, `plugin/{slug}/cover.webp`, `plugin/{slug}/screenshot-{n}.webp`.
    - Public hostname: `https://plugins.peakurl.org`.
    - Immutable caching: `Cache-Control: public, max-age=31536000, immutable`.
    - Re-upload / overwriting of an existing version tag is strictly prohibited.
    - API provides official SHA-256 checksum for cryptographic verification.

### Phase 5.4 — Self-Hosted Directory Discovery & Remote Update Integration

- **Directory API Client:**
    - Self-hosted PeakURL instances poll `https://peakurl.org/api/v1/plugins` for directory browsing, search, and remote update notifications.
    - Remote update check compares current host version and plugin versions using `Validator::compare_versions()`.
    - One-click install/update downloads signed release package directly from `https://plugins.peakurl.org/plugin/{slug}-{version}.zip`.
    - Verifies SHA-256 checksum before invoking `SystemService::upload_plugin()` pipeline.
    - Preserves local settings, active state, and file locking guarantees.

Full moderation, R2, cache, costs, security withdrawal, recovery and operations runbooks are preserved in the ecosystem guide v1.4, Sections 1–23. Those website/R2 features are ready to begin now that the foundation is verified.
