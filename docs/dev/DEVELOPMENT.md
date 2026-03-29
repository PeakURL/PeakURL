# Development Environment Setup

This guide covers the local development workflow for PeakURL.

PeakURL runs a Docker-based development stack with:

- a Vite-powered dashboard UI
- a PHP application runtime and API
- a local MySQL instance
- phpMyAdmin
- a packaged release test site served from `release/peakurl`

## Prerequisites

Install the following tools before starting:

- Docker Desktop with `docker compose`
- Node.js 24
- npm
- PHP 8.4 for local CLI checks
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

## Local Services

The default Docker services are:

- `peakurl-proxy`
- `peakurl-ui`
- `peakurl-app`
- `peakurl-test`
- `peakurl-db`
- `peakurl-db-init`
- `peakurl-phpmyadmin`

## Working on the App

Most day-to-day development happens against:

- `https://peakurl.dev` for the dashboard UI
- `https://api.peakurl.dev` for the PHP backend

Vite hot reload is available on the UI service. If the dashboard appears stale after deeper runtime changes, restart the stack or rebuild the affected service:

```bash
docker compose up --build
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

Build the dashboard:

```bash
npm run build
```

Build the release package:

```bash
npm run release:build
```

Run the main lint pass:

```bash
npm run lint
```

Run PHP standards checks:

```bash
composer --working-dir=app run phpcs
```

Run PHP syntax checks:

```bash
npm run lint:php:syntax
```

Run the auth smoke test:

```bash
npm run smoke:auth
```

For linting and formatting details, see the [Linting and Formatting guide](LINTING.md).

## GeoLite2 Location Data

PeakURL uses a local MaxMind GeoLite2 City database for location analytics.

- default path: `content/uploads/geoip/GeoLite2-City.mmdb`
- refresh command: `php app/bin/update-geoip.php`

In the source checkout, Location Data settings save into `app/.env`. In installed releases, the same settings are written to the generated root `config.php`.

## Optional Editor Setup

If you use VS Code, see the [VS Code workspace guide](VSCODE.md) for the local workspace settings and recommended extensions that were previously tracked in `.vscode/`.
