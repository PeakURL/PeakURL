# Backend PHPUnit Test Suite (`server/tests/`)

Automated unit and integration tests for the PeakURL PHP backend runtime (`server/`).

## Directory Structure

```text
server/tests/
├── Unit/          # Isolated tests without database or network dependencies
├── Integration/   # Multi-component and MySQL-backed persistence/lifecycle tests
├── Support/       # Shared test doubles and fixture helpers (GeoIP, SettingsApi, filesystem)
└── bootstrap.php  # PHPUnit autoloader and ABSPATH bootstrap
```

## Runtime Requirements

- **PHP 8.3+** (CI runs against PHP 8.3, 8.4, and 8.5) with extensions `pdo`, `pdo_mysql`, `json`, `mbstring`, `openssl`, `curl` (and `zip` for archive/update tests)
- **Composer** dependencies installed under `server/` (`composer --working-dir=server install`)
- **MySQL 8.4** (or compatible) for the `Integration` suite (`peakurl_test` database, port `3307` in Docker Compose)

## Running Tests

From the repository root:

```bash
# Run the full backend suite (Unit + Integration)
npm run test:server

# Run only isolated unit tests (no database required)
server/vendor/bin/phpunit -c server/phpunit.xml --testsuite Unit

# Run only integration tests (requires provisioned peakurl_test database)
server/vendor/bin/phpunit -c server/phpunit.xml --testsuite Integration

# Run a targeted test file or directory
server/vendor/bin/phpunit -c server/phpunit.xml server/tests/Unit/Auth/
server/vendor/bin/phpunit -c server/phpunit.xml server/tests/Integration/Webhooks/WebhooksTest.php
```

## Provisioning the Isolated Test Database

Before running `Integration` tests locally, ensure the `peakurl_test` database is running and initialized (separate from your development `peakurl` database):

```bash
DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=peakurl_test DB_USERNAME=root DB_PASSWORD=root DB_PREFIX=peakurl_ \
PEAKURL_DEV=true PEAKURL_OWNER_FIRST_NAME=Workspace PEAKURL_OWNER_LAST_NAME=Owner \
PEAKURL_OWNER_USERNAME=admin PEAKURL_OWNER_EMAIL=owner@example.com \
PEAKURL_OWNER_PASSWORD=admin12345 PEAKURL_WORKSPACE_NAME=Workspace PEAKURL_WORKSPACE_SLUG=workspace \
php server/bin/setup-database.php
```

`server/phpunit.xml` configures `PEAKURL_ENV=testing`, `PEAKURL_DEV=true`, and `DB_DATABASE=peakurl_test` by default. Never point `DB_DATABASE` at a production or non-disposable database.

## CI Execution

`.github/workflows/ci.yml` provisions an ephemeral MySQL 8.4 `peakurl_test` service, runs WordPress Coding Standards (`phpcs`) and PHP syntax checks (`php -l`), initializes the schema with `server/bin/setup-database.php`, and executes `composer --working-dir=server test` across PHP 8.3, 8.4, and 8.5.

## Maintenance Guidelines

- **Unit vs. Integration:** Place pure logic, validation, and mock-isolated tests in `Unit/`; reserve `Integration/` for tests that exercise real MySQL persistence, schema upgrades, process execution, or multi-component filesystem/lifecycle behavior.
- **Shared helpers:** Reuse `PeakURL\Tests\Support\InMemorySettingsApi`, `FilesystemHelper`, and `GeoipTestFixture` instead of duplicating test doubles or temporary-directory cleanup helpers.
- **Keep tests behavioral:** Prefer public service/controller contracts over internal implementation details, and clean up temporary files or test records in `tearDown()` or `finally`.
