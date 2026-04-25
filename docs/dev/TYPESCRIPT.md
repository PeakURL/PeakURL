# TypeScript Guide

This guide explains how TypeScript is used in the PeakURL dashboard codebase.

PeakURL is an open-source project, so the goal is not only to satisfy the compiler. The code should also stay easy for other contributors to read, review, and extend.

The main TypeScript surface in this repo is the React dashboard under `ui/`. The PHP runtime in `app/` and `site/` follows separate standards.

## Goals

PeakURL uses TypeScript to make the UI easier to change without breaking API contracts, feature flows, or shared components.

In practice, that means we try to:

- keep types close to the feature that owns them
- make exported shapes readable for other developers
- avoid duplicate utility guards and ad hoc error parsing
- improve typing incrementally without disabling checks

## Scope

This guide applies to:

- `ui/**/*.ts`
- `ui/**/*.tsx`
- supporting TS config and build files when relevant

## Core Rules

- Do not loosen a file back to untyped or JSX-only patterns as a shortcut.
- Prefer `unknown` plus narrowing over `any`.
- Prefer `import type` for type-only imports.
- Keep React component file names explicit, such as `LoginPage.tsx` or `DashboardLayout.tsx`.
- Prefer extensionless imports and existing folder barrels where practical.
- Treat `ui/utils/index.ts` as the public utilities surface and prefer `@/utils` over direct `@/utils/...` imports.

These are not meant to make simple changes harder. They are here to keep the codebase consistent as more contributors touch the same UI surface.

## Where Types Live

PeakURL generally uses a nearest-owner approach for types.

### Folder-level `types.ts`

Use the nearest folder-level `types.ts` for shared or reusable shapes in that area.

Examples:

- `ui/components/ui/types.ts`
- `ui/components/providers/types.ts`
- `ui/pages/dashboard/links/_components/types.ts`
- `ui/pages/dashboard/settings/_components/tabs/pages/types.ts`

### Component-folder `types.ts`

If a component folder contains multiple related files, keep its props and payload types in that folder's own `types.ts`.

Use this pattern for:

- component props shared by siblings
- local API payloads for that feature
- helper view models used by multiple files in the folder

### Root page wrapper types

If a type is only shared at the page-entry layer, keep it in `ui/pages/types.ts`.

Example:

- `AppLayoutProps`

### Utility helper types

If a type exists to support utility guards or shared helper logic, keep it in `ui/utils/types.ts`.

Examples:

- `ErrorRecord`
- `ApiErrorData`
- `NumericStatusQueryError`

### Try To Avoid

- filename-specific type files like `ComponentName.types.ts` unless there is already a strong local pattern that requires it
- scattering identical prop shapes across multiple files
- declaring reusable exported shapes inside random feature files when a nearby `types.ts` exists

If something starts local and later becomes shared, promote it into the nearest `types.ts` at that point. There is no need to over-abstract too early.

## TSDoc Expectations

Add TSDoc to exported types when the intent is not already obvious from the name alone.

Good TSDoc in PeakURL should:

- explain what the shape represents
- document fields that are easy to misread
- describe nested API payloads when helpful
- stay concise and factual

Avoid comments that only restate the property name. A short useful comment is better than a long generic one.

Preferred style:

```ts
/**
 * API response wrapper used by the links dashboard list and lookup queries.
 *
 * Encapsulates a collection of link records along with optional metadata
 * such as pagination or total counts.
 */
export interface GetUrlsResponse {
	/** Response payload returned from the API. */
	data?: {
		/** List of link records returned by the query. */
		items?: LinkRecord[];

		/** Additional metadata (e.g. pagination, totals). */
		meta?: LinksMeta;
	};
}
```

## Component Props

Component props should not be left as implicit destructured parameters.

Preferred:

```ts
import type { ClientProvidersProps } from './types';

function ClientProviders({ children }: ClientProvidersProps) {
	return <>{children}</>;
}
```

Avoid:

```ts
function ClientProviders({ children }: { children: ReactNode }) {
	return <>{children}</>;
}
```

Also avoid completely untyped destructuring:

```ts
function ComingSoonBadge({ className = '' }) {
	return <span className={className}>...</span>;
}
```

If the prop shape is reusable or exported, move it into the nearest `types.ts` and add TSDoc when needed.

## API Contracts And Error Handling

Keep RTK Query response contracts stable where practical.

For error parsing, avoid reintroducing local `typeof` chains across pages and components. Use the shared helpers in `ui/utils/errors.ts`:

- `extractErrorMessage(error)`
- `getErrorMessage(error, fallback)`
- `getErrorStatus(error)`

Those helpers are backed by shared utility guard types in `ui/utils/types.ts`.

Preferred:

```ts
notification?.error(
	__("Export failed"),
	getErrorMessage(
		error,
		__("PeakURL could not prepare the export right now.")
	)
);
```

Avoid:

```ts
if (
	error &&
	"object" === typeof error &&
	"data" in error &&
	"object" === typeof error.data
) {
	// repeated local parsing
}
```

## Events And Narrowing

Use the specific React event types that match the interaction:

- `SubmitEvent<HTMLFormElement>`
- `KeyboardEvent<HTMLInputElement>`
- `ChangeEvent<HTMLInputElement>`
- `MouseEvent<HTMLButtonElement>`
- `DragEvent<HTMLDivElement>`

When handling unknown values:

- start from `unknown`
- narrow with guards
- keep assertions narrow and local

## Migration Notes

When touching older UI files, prefer small, safe cleanup steps over broad rewrites.

A typical refactor looks like this:

1. Move shared local declarations into the nearest `types.ts`.
2. Add or improve TSDoc on exported types.
3. Replace repeated error parsing with `ui/utils/errors.ts`.
4. Replace inline or implicit prop typing with named props from `types.ts`.
5. Keep imports type-only where possible.
6. Re-run the validation commands.

## Review Checklist

Before merging UI TypeScript work, it helps to check:

- exported props and payload shapes live in the right `types.ts`
- new exported types have useful TSDoc
- no duplicated local `getErrorMessage` or `getErrorStatus` helpers
- no untyped destructured component props
- no avoidable `any`

## Validation Commands

For UI TypeScript changes, run:

```bash
npx tsc -b --pretty false
npm run lint:web
npm run build
```

For formatting:

```bash
npm run format:web
```

## Related Docs

- [Development Environment Setup](DEVELOPMENT.md)
- [Linting and Formatting](LINTING.md)
