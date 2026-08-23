# Contributing to PeakURL

Thank you for your interest in contributing to PeakURL.

This project is committed to maintaining a secure, performant, and well-structured self-hosted link management platform. We welcome contributions including bug fixes, feature enhancements, documentation improvements, and quality assurance.

---

## Before You Begin

Please review the following foundational documents prior to contributing:

- [Project README](README.md)
- [Development Environment Setup](docs/dev/DEVELOPMENT.md)
- [Linting and Formatting Guide](docs/dev/LINTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Security Policy](SECURITY.md)

---

## Issue-First Workflow

To ensure coordinated development and maintain project architectural integrity, **contributors must open a GitHub Issue and receive maintainer alignment before submitting a Pull Request.**

### Rationale

- **Technical Alignment**: Establishing consensus on the scope, requirements, and design prevents redundant effort and misaligned implementations.
- **Triage and Reproducibility**: For defect reports, an issue enables maintainers and contributors to verify reproduction steps and identify root causes systematically.
- **Community Transparency**: Open issues inform the broader community of active development tracks and prevent duplicated work.

### Contribution Process

1. **Review Existing Issues**: Search the [issue tracker](https://github.com/PeakURL/PeakURL/issues) to confirm the defect or feature has not already been reported or planned.
2. **Open a Detailed Issue**:
    - **Bug Reports**: Provide precise reproduction steps, expected versus actual behavior, system environment details (Operating System, PHP version, Node.js version, browser), and relevant application logs.
    - **Feature Requests**: Clearly state the problem, the anticipated user benefit, and the proposed technical implementation.
3. **Obtain Maintainer Confirmation**: For non-trivial or architectural changes, please wait for maintainer review and approval before proceeding with implementation.
4. **Reference the Issue in the Pull Request**: Every pull request must explicitly cite the related issue in its description (e.g., `Fixes #123` or `Closes #123`).

> [!IMPORTANT]
> Pull requests submitted without a corresponding issue or adequate context will be placed on hold until an issue is opened and the scope is reviewed with maintainers.

---

## Architectural Scope and Boundaries

PeakURL is designed as a single-domain, self-hosted deployment. All contributions must adhere to the following product boundaries:

- **Single-Domain Architecture**: Do not reintroduce multi-tenant domain models, SaaS-specific billing tables, or team-based administrative tiers.
- **Minimal Dependencies**: Prefer standard runtime APIs and lightweight patterns over introducing extraneous external dependencies.
- **System Integrity**: Preserve the reliability, security, and idempotency of the installer, database migration services, and runtime configuration layers.

---

## Local Development Environment

Consult the [Development Environment Setup](docs/dev/DEVELOPMENT.md) guide for comprehensive instructions on:

- Initializing the local Docker environment (`compose.yaml`).
- Developing within the React dashboard (`ui/`) and PHP backend runtime (`app/`).
- Verifying local domains (`https://peakurl.dev`, `https://api.peakurl.dev`, and `https://peakurl.test`).

---

## Code Quality and Verification Standards

All code submissions must satisfy automated validation via the **CI Quality Gate** (`.github/workflows/ci.yml`) before merging. Contributors are expected to execute and pass all verification checks locally:

### 1. Code Formatting (Prettier and PHPCS)

```bash
# Verify formatting compliance
npm run format:check

# Automatically format all files
npm run format
```

### 2. Linting (ESLint and PHP_CodeSniffer)

```bash
# Execute all linters
npm run lint

# Lint frontend assets only
npm run lint:web

# Lint backend PHP files only
npm run lint:php
```

### 3. PHP Syntax Validation

```bash
npm run lint:php:syntax
```

### 4. TypeScript Compilation and Production Build

```bash
npm run build
```

For complete specifications regarding coding standards, ESLint configuration, and PHPCS rules, refer to the [Linting and Formatting Guide](docs/dev/LINTING.md).

---

## Pull Request Guidelines

When submitting a pull request, ensure compliance with the following standards:

1. **Branch Naming**: Use concise, prefixed branch names (e.g., `fix/redirect-trailing-slash`, `feat/export-filters`, `docs/setup-guide`).
2. **Atomic Commits**: Keep pull requests focused on a single logical change corresponding to the linked issue. Do not combine unrelated modifications.
3. **Pull Request Description**: Complete all sections of the [Pull Request Template](.github/pull_request_template.md), including the linked issue reference, summary of changes, and verification evidence.
4. **Documentation**: Update all corresponding documentation files within the same pull request whenever modifying user-facing functionality, configuration options, or development workflows.

---

## Security Vulnerability Reporting

Security vulnerabilities must **not** be reported through public GitHub issues or pull requests.

Please adhere to the private disclosure protocol outlined in the [Security Policy](SECURITY.md).
