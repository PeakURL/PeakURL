# Development Environment Setup

This guide covers the local development workflow for PeakURL.

PeakURL runs a Docker-based development stack with:

- a Vite-powered dashboard UI
- a PHP application runtime and API (with `apcu` and `redis` extensions loaded)
- a Redis in-memory cache instance (`peakurl-redis`)
- a local MySQL instance
- phpMyAdmin
- a packaged release test site served from `release/peakurl`

## Prerequisites

Install the following tools before starting:

- Docker Desktop with `docker compose`
- Node.js 24
- npm
- PHP 8.3+ (PHP 8.3, 8.4, or 8.5) for local CLI checks
- Composer
- `mkcert` for trusted local HTTPS certificates

On macOS, the local-domain setup script also updates `/etc/hosts`, so it will prompt for your password.

## One-Time Local Setup

Run the local setup script from the repository root:

```bash
./scripts/setup-local.sh
```

This script:

- installs and trusts a local certificate authority through `mkcert`
- adds the required local domains to `/etc/hosts`
- generates certificates used by the local proxy

## Start the Stack

Bring up the full development environment:

```bash
docker compose up --build
```

Main local URLs:

- Dashboard UI: `https://peakurl.dev`
- PHP app and API: `https://api.peakurl.dev`
- Release test site: `https://peakurl.test`
- phpMyAdmin: `http://phpmyadmin.localhost`

Fallback direct ports:

- UI: `http://localhost:5173`
- API: `http://localhost:8000`
- phpMyAdmin: `http://localhost:8081`
- MySQL: `127.0.0.1:3307`
- Redis: `127.0.0.1:6379`

## Local Services

The default Docker services are:

- `peakurl-proxy`
- `peakurl-client`
- `peakurl-server`
- `peakurl-test`
- `peakurl-db`
- `peakurl-db-init`
- `peakurl-redis`
- `peakurl-phpmyadmin`

## Working on the App

Most day-to-day development happens against:

- `https://peakurl.dev` for the dashboard UI
- `https://api.peakurl.dev` for the PHP backend

Vite hot reload is available on the client service. If the dashboard appears stale after deeper runtime changes, restart the stack or rebuild the affected service:

```bash
docker compose up --build
```

## Automated Testing

PeakURL includes automated test suites covering backend PHP logic, frontend utilities and components, browser end-to-end workflows, and authentication security.

### Test Suites and Directory Structure

- **Backend Tests (PHPUnit)**:
    - `server/tests/Unit/`: Unit tests for classes, utilities, configuration, validation rules, hooks, and services without database dependencies.
    - `server/tests/Integration/`: Integration tests that verify database persistence, schema migrations, role authorization, authentication lifecycles, and API route contracts against MySQL.
- **Frontend and E2E Tests (Playwright)**:
    - `tests/unit/`: Playwright unit tests for frontend utilities, dates, CSV handling, API client contracts, and component logic.
    - `tests/e2e/`: Browser end-to-end tests exercising user workflows across authentication, link creation and expiration, settings, tools, drawers, and modal interactions.

### Running Tests

Execute the test suites locally using the following commands:

```bash
# Run PHP unit and integration tests via PHPUnit
npm run test:server

# Run Playwright frontend unit tests
npm run test:unit

# Run server test suites (unit + integration) and frontend unit tests
npm test

# Run browser E2E tests in Chromium
npx playwright test --project=chromium

# Run authentication and secure-cookie smoke tests
npm run smoke:auth
```

> [!NOTE]
> `npm test` runs both `npm run test:server` and `npm run test:unit`. Because the backend PHPUnit suite includes integration tests (`server/tests/Integration/`) that verify database operations and migrations against MySQL, running backend tests requires an active MySQL test database configured to match `server/phpunit.xml` (by default, `127.0.0.1:3307` with database `peakurl_test`, as provided by the local Docker stack).
>
> The `npm run test:e2e` script runs `playwright test` without project filtering, which executes the complete Playwright test set across both configured projects (`unit` and `chromium`). When you want to run only browser E2E tests, target Chromium directly with `npx playwright test --project=chromium`.

### Environment Requirements for E2E and Smoke Tests

Unlike unit tests, browser E2E tests and auth smoke tests interact with a live application instance:

- **Local Stack Running**: The local Docker development stack must be active and healthy (`docker compose up -d --build`).
- **Base URLs**:
    - Playwright E2E tests target `https://peakurl.dev` by default, configurable via `PEAKURL_TEST_URL`.
    - The auth smoke test (`scripts/smoke-auth.sh`) connects to `https://peakurl.dev` and `https://api.peakurl.dev` by default, configurable via `PEAKURL_URL`, `API_URL`, and `APP_URL`.
- **Test Credentials**: Smoke and E2E tests require administrative credentials. Provide your admin password via `PEAKURL_TEST_PASSWORD` (and optionally `PEAKURL_TEST_IDENTIFIER`, which defaults to `admin`):

```bash
PEAKURL_TEST_PASSWORD="your-admin-password" npm run smoke:auth
PEAKURL_TEST_PASSWORD="your-admin-password" npx playwright test --project=chromium
```

- **Built Public Assets**: When testing standalone public scripts (such as the installer, status pages, or CAPTCHA interstitials) against mocked providers without database dependencies:

```bash
PEAKURL_TEST_BUILT_ASSETS=1 npx playwright test tests/e2e/public --project=chromium
```

## Release Testing

PeakURL includes a dedicated local release test site at `https://peakurl.test`.

Build the packaged release first:

```bash
npm run release:build
```

That command assembles the release into:

- `release/peakurl`
- `release/peakurl-1.0.0.zip` or the current versioned archive
- `release/release-metadata.json`

The `peakurl-test` container serves `release/peakurl` directly, so rebuilding the release updates the local packaged installer and runtime.

For local release testing, the installer is prefilled with Docker-friendly database defaults:

- `Database Host`: `db`
- `Database Name`: `peakurl_test`
- `Database Username`: `root`
- `Database Password`: `root`

## Important Note About `release/`

Do not remove the entire `release/` directory while `peakurl-test` is running.

The release test container bind-mounts that folder, so deleting it can cause filesystem errors or leave the test site in an invalid state. If you need a clean release output, rebuild it in place:

```bash
npm run release:build
```

## Common Commands

Start or rebuild the stack:

```bash
docker compose up --build
```

Stop the stack:

```bash
docker compose down
```

Build dashboard and public assets:

```bash
npm run build
```

`npm run build` builds the React dashboard and compiles the standalone public scripts (`server/features/links/publicCaptcha.js`, `server/services/install/installer.js`, and `server/services/install/languagePicker.js`) into `build/server/`. Release assembly copies these minified bundles over the corresponding PHP runtime assets.

PHP embeds the compiled scripts inline so public link access checks, installer screens, and language selection do not load the full dashboard bundle. If you are only modifying these standalone scripts, you can rebuild them quickly without rebuilding the dashboard:

```bash
npm run build:public
```

Build the packaged release:

```bash
npm run release:build
```

Run code standards and syntax checks:

```bash
npm run lint
npm run lint:php:syntax
```

Run automated tests (see [Automated Testing](#automated-testing) for prerequisites and suite details):

```bash
npm test                               # Server test suites and frontend unit tests
npm run test:server                    # PHPUnit unit and integration tests (requires MySQL test DB)
npm run test:unit                      # Playwright frontend unit tests
npx playwright test --project=chromium # Browser E2E tests (requires running local stack)
npm run smoke:auth                     # Authentication smoke tests (requires running local stack)
```

For linting and formatting details, see the [Linting and Formatting guide](LINTING.md).

## Runtime Environment Configuration

PeakURL separates runtime filesystem layout from diagnostics and execution mode:

- **`PEAKURL_DEV`**: Controls runtime filesystem layout.
    - `true`: Development layout. The application runtime source lives under `server/` (e.g., in git source checkouts and Docker development containers). If set to `true` and the `server/` directory is missing, PeakURL fails immediately with a descriptive exception.
    - `false` (or unset / default): Production release layout. The application runtime is flattened at the root (e.g., in self-hosted release zip extractions). Never relies on runtime path probing or heuristic filesystem guesses.
    - _Example (`compose.yaml` or `.env`)_: `PEAKURL_DEV=true`

- **`PEAKURL_DEBUG`**: Controls diagnostics and error reporting.
    - `true`: Detailed error messages are displayed and logged to `content/debug.log`.
    - `false` (default): Production error handling; errors are suppressed from visitor responses.
    - _Example (`config.php` or environment)_: `define('PEAKURL_DEBUG', true);` or `PEAKURL_DEBUG=true`

**Important Distinction**: `PEAKURL_DEBUG=true` enables diagnostic logging and does _not_ imply development layout. Conversely, `PEAKURL_DEV=true` only dictates filesystem path resolution and does not enable debug logging.

## GeoLite2 Location Data

PeakURL uses a local MaxMind GeoLite2 City database for location analytics.

- default path: `content/uploads/geoip/GeoLite2-City.mmdb`
- refresh command: `php server/bin/update-geoip.php`

In both development and production environments, MaxMind credentials are persisted securely in encrypted settings storage within the database.

## Object Caching in Local Development

The development environment supports testing all cache drivers locally:

- **Redis**: Served by the `peakurl-redis` container on `127.0.0.1:6379` and connected via `PEAKURL_REDIS_HOST: redis`.
- **APCu**: Pre-installed and enabled with `apc.enable_cli=1` for testing in-memory PHP shared cache.
- **Filesystem**: Stores sharded transient cache files in `content/cache/`.
- **Auto Negotiation**: Defaults to `auto`, which prioritizes Redis, gracefully falls back to APCu, then disk cache, and finally direct database queries.

You can inspect cache diagnostics, tune positive/negative TTL policies, and trigger on-demand cache purges under **Settings -> Performance** or **Tools -> System Status** in the dashboard.

## Optional Editor Setup

If you use VS Code, see the [VS Code workspace guide](VSCODE.md) for the local workspace settings and recommended extensions that were previously tracked in `.vscode/`.
