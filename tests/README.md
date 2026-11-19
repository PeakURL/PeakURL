# Frontend Unit & End-to-End Test Suite (`tests/`)

Playwright test suite covering frontend utilities, API client contracts, and browser end-to-end workflows for PeakURL.

## Directory Structure

```text
tests/
├── unit/                  # Fast, isolated TypeScript unit specs (Playwright `unit` project)
└── e2e/                   # Browser E2E specs (Playwright `chromium` project)
    ├── activity/          # Audit log viewing and filtering
    ├── auth/              # Login, logout, protected routes, and 2FA flows
    ├── dashboard/         # Dashboard overview and plugin management UI
    ├── fixtures/          # Shared auth/dashboard/drawer/theme fixtures and PHP render helpers
    ├── global/            # Shared layout, drawers, overlays, search, themes, and responsive checks
    ├── links/             # Short-link creation, editing, expiration, and bulk management
    ├── public/            # Installer, CAPTCHA, and public link/error status pages
    ├── settings/          # Settings tabs and webhook workflows
    ├── tools/             # Import/export tools and scheduled jobs UI
    └── users/             # User administration and role capability management
```

## Runtime & Fixture Requirements

- **Node.js 26** and npm (`npm ci`)
- **Playwright Chromium** (`npx playwright install chromium`) for `e2e/` tests
- For live-stack E2E specs (auth, links, users, settings persistence): a running local Docker stack (`docker compose up --build` serving `https://peakurl.dev`) and `PEAKURL_TEST_PASSWORD` (plus `PEAKURL_TEST_IDENTIFIER`, defaulting to `admin`).
- Pure `unit/` tests and mocked `e2e/global/` UI specs do not require database mutations; `e2e/public/` status-page/installer checks invoke `php` CLI against local fixtures in `e2e/fixtures/`.

## Running Tests

From the repository root:

```bash
# Run frontend unit tests (fast, no browser or Docker stack required)
npm run test:unit

# Run a single unit spec
npx playwright test --project=unit tests/unit/links-url.spec.ts

# Run all browser E2E tests against the local stack
npx playwright test --project=chromium

# Run a targeted E2E domain or spec
npx playwright test --project=chromium tests/e2e/auth/
npx playwright test --project=chromium tests/e2e/global/overlays.spec.ts

# Discover tests without running them
npx playwright test --list
```

## Test Isolation

- `tests/e2e/fixtures/test-environment.ts` enforces hostname safety checks so E2E tests target local development/test hosts (`peakurl.dev`, `localhost`, `127.0.0.1`) and restore modified role baselines on teardown.
- Mocked UI specs (such as `dashboard.fixture.ts` and `drawer.fixture.ts`) intercept `/api/v1/**` routes so they never write to an active database.
- Never point `PEAKURL_TEST_URL` at a production deployment.

## CI Execution

- `.github/workflows/ci.yml` runs `npm run test:unit` (alongside Prettier, ESLint, and `npm run build`) on pull requests and pushes affecting frontend files.
- `.github/workflows/e2e.yml` starts the full Docker Compose stack on pull requests touching application code, runs `npm run smoke:auth`, and executes `npx playwright test --project=chromium`.

## Maintenance Guidelines

- **Choose the right layer:** Put pure TypeScript helper, state, and contract checks in `tests/unit/`; put DOM, routing, accessibility, responsive layout, and user workflow checks in `tests/e2e/`.
- **Reuse fixtures:** Use `tests/e2e/fixtures/` (`auth.fixture.ts`, `dashboard.fixture.ts`, `drawer.fixture.ts`) rather than duplicating login flows or session mocks.
- **Keep tests focused:** Test user-visible behavior, security boundaries, and layout contracts rather than static implementation details.
