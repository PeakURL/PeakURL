# Contributing to PeakURL

Thank you for taking the time to contribute to PeakURL.

This project is intended to stay approachable, well-structured, and practical for real self-hosted deployments. Contributions are welcome across code, documentation, bug fixes, testing, and product polish.

## Before You Start

Please review these documents first:

- [Project README](README.md)
- [Development Environment Setup](docs/dev/DEVELOPMENT.md)
- [Linting and Formatting](docs/dev/LINTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Security Policy](SECURITY.md)

## Ways to Contribute

- Report bugs
- Improve documentation
- Propose or implement focused features
- Fix UI or runtime regressions
- Improve tests, release tooling, or installer behavior

## Development Expectations

PeakURL is a self-hosted dashboard product. Contributions should align with that direction.

Please avoid changes that:

- reintroduce the old multi-domain SaaS-style admin surface
- add dead product areas that are not part of the self-hosted experience
- weaken the current installer, release, or runtime flow

## Setting Up Locally

Use the local development guide:

- [Development Environment Setup](docs/dev/DEVELOPMENT.md)

That guide covers the Docker stack, local domains, release testing, and the main development workflow.

## Linting and Verification

Before opening a pull request, run the relevant checks for your changes.

Typical full check:

```bash
npm run lint
npm run build
```

If your changes affect PHP runtime behavior, also run:

```bash
npm run lint:php:syntax
```

For more detail, see:

- [Linting and Formatting](docs/dev/LINTING.md)

## Pull Requests

When opening a pull request:

- keep the scope focused
- explain the user-facing or runtime impact clearly
- mention any tradeoffs or follow-up work
- include verification steps when relevant

If your change affects installation, auth, release packaging, or runtime behavior, please test both the normal development flow and the packaged release flow where practical.

## Documentation Changes

If a contribution changes behavior, commands, setup, or release expectations, update the relevant documentation in the same pull request.

## Questions and Product Direction

If a change is large, architectural, or product-shaping, it is better to discuss the direction before investing heavily in implementation.
