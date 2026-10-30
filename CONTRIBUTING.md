# Contributing to PeakURL

Thank you for your interest in contributing to PeakURL!

PeakURL is an open-source, self-hosted URL shortener and link management platform designed to be secure, fast, and easy to maintain. We welcome contributions of all kinds, including bug fixes, feature enhancements, documentation improvements, and quality assurance.

---

## Before You Begin

Before getting started, please take a moment to review:

- [Project README](README.md)
- [Development Environment Setup](docs/dev/DEVELOPMENT.md)
- [Linting and Formatting Guide](docs/dev/LINTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Security Policy](SECURITY.md)

---

## Starting with an Issue

To keep development coordinated and avoid duplicate effort, we ask contributors to open or join a discussion on a GitHub Issue before submitting a pull request.

### Why We Start with Issues

- **Shared Alignment**: Discussing scope, requirements, and implementation early helps ensure your solution fits the project direction and saves you time.
- **Triage and Reproducibility**: For bug reports, an issue gives us a shared space to reproduce the behavior, gather environment details, and identify the root cause together.
- **Community Visibility**: Open issues let the broader community know what is actively being planned or developed.

### Recommended Process

1. **Check Existing Issues**: Search the [issue tracker](https://github.com/PeakURL/PeakURL/issues) to see if the topic is already being discussed or worked on.
2. **Open an Issue**:
    - **Bug reports**: Include steps to reproduce the issue, expected versus actual behavior, system environment details (OS, PHP version, Node.js version, browser), and relevant logs.
    - **Feature proposals**: Describe the goal, anticipated benefits, and your proposed technical approach.
3. **Align with Maintainers**: For architectural changes or new features, please discuss the approach with maintainers before starting implementation.
4. **Link the Issue in Your Pull Request**: Reference the issue in your pull request description (for example, `Fixes #123` or `Closes #123`).

> [!NOTE]
> If a pull request arrives without an accompanying issue or context, we will usually pause review so we can discuss the goals and scope with you first.

---

## Architectural Principles

PeakURL is intentionally designed as a focused, self-hosted application. Please keep these principles in mind when contributing:

- **Single-Domain Architecture**: PeakURL is built for single-instance, self-hosted deployments. We avoid multi-tenant domain models, team tiers, or SaaS-specific billing features.
- **Minimal Dependencies**: We prefer built-in runtime APIs and lightweight patterns over adding new third-party dependencies.
- **System Integrity**: The installer, database migrations, and runtime configuration layers should remain dependable, idempotent, and secure.

---

## Local Development Environment

Please consult the [Development Environment Setup](docs/dev/DEVELOPMENT.md) guide for instructions on:

- Initializing the local Docker environment (`compose.yaml`).
- Developing within the React dashboard (`client/`) and PHP backend runtime (`server/`).
- Accessing local development domains (`https://peakurl.dev`, `https://api.peakurl.dev`, and `https://peakurl.test`).

---

## Code Quality and Verification

Our continuous integration suite verifies code formatting, linting, syntax, and automated tests on pull requests. Running these checks locally before opening a pull request helps ensure a smooth review process.

### 1. Automated Testing

Automated tests help us keep PeakURL reliable as the project evolves. We ask contributors to include relevant tests when adding features, fixing bugs, or changing existing behavior.

#### Testing Expectations

- **New features:** Include unit tests for business logic and browser E2E tests for new or modified user workflows.
- **Bug fixes:** Add regression tests that reproduce the problem and confirm the fix works as intended.
- **Existing functionality:** Update affected tests whenever behavior, schemas, or API contracts change.
- **Security and permissions:** Include tests for authorization boundaries, input validation, failure modes, and access-denied scenarios (such as role restrictions or expired sessions).
- **Backend changes:** Use PHPUnit unit tests (`server/tests/Unit/`) or integration tests (`server/tests/Integration/`), as appropriate.
- **Frontend changes:** Use Playwright unit tests (`tests/unit/`) for testable client logic and utilities, and browser E2E tests (`tests/e2e/`) for user interactions.
- **Documentation changes:** Changes limited to documentation do not require automated tests.

#### Focus on Meaningful Behavior

Tests should focus on real-world behavior and important edge cases. If a particular test layer does not apply to your change (for example, a backend-only fix with no frontend impact, or a non-visual refactor), simply let us know why in your pull request description.

#### Testing Commands

```bash
# Run PHP unit and integration tests (PHPUnit)
npm run test:server

# Run Playwright frontend unit tests
npm run test:unit

# Run server test suites (unit + integration) and frontend unit tests
npm test

# Run browser E2E tests against the local development stack
npx playwright test --project=chromium

# Run authentication smoke tests (requires local Docker stack)
npm run smoke:auth
```

> [!NOTE]
> `npm test` runs both server tests (including integration tests that require the local MySQL test database) and frontend unit tests. The `npm run test:e2e` script runs the complete Playwright test suite across all configured projects (`unit` and `chromium`); use `npx playwright test --project=chromium` when you only want to run browser E2E tests. For full environment requirements and setup, see the [Automated Testing guide](docs/dev/DEVELOPMENT.md#automated-testing).

### 2. Code Formatting (Prettier and PHPCS)

```bash
# Check formatting
npm run format:check

# Format files automatically
npm run format
```

### 3. Linting (ESLint and PHP_CodeSniffer)

```bash
# Run all linters
npm run lint

# Lint frontend assets only
npm run lint:web

# Lint backend PHP files only
npm run lint:php
```

### 4. PHP Syntax Validation

```bash
npm run lint:php:syntax
```

### 5. TypeScript Compilation and Production Build

```bash
npm run build
```

For complete specifications regarding coding standards, ESLint configuration, and PHPCS rules, please refer to the [Linting and Formatting Guide](docs/dev/LINTING.md).

---

## Pull Request Guidelines

When preparing a pull request, please keep the following tips in mind:

1. **Branch Naming**: Use concise, descriptive branch names with a prefix (such as `fix/redirect-trailing-slash`, `feat/export-filters`, or `docs/setup-guide`).
2. **Focused Changes**: Keep each pull request focused on a single issue or improvement. This keeps reviews manageable and speeds up feedback.
3. **Pull Request Description**: Please fill out the [Pull Request Template](.github/pull_request_template.md), including the linked issue reference, a summary of your changes, and how you verified them.
4. **Documentation**: When adding or updating functionality, please update the corresponding documentation in the same pull request.

---

## Security Vulnerability Reporting

If you discover a potential security vulnerability, please do not report it through public GitHub issues, discussions, or pull requests.

Instead, please report security concerns privately following the disclosure process outlined in our [Security Policy](SECURITY.md). This gives maintainers time to investigate and resolve issues before they are disclosed publicly.
