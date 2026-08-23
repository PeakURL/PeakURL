export const DEFAULT_PAGE_SIZE_OPTIONS: readonly number[] = [25, 50, 100, 150];
export const DEFAULT_PAGE_SIZE_MAX = 250;

export function normalizePageSize(
	value: number | string | null | undefined,
	fallback: number = DEFAULT_PAGE_SIZE_OPTIONS[0] ?? 25,
	max = DEFAULT_PAGE_SIZE_MAX
): number {
	const parsed = Number(value);

	if (!Number.isFinite(parsed) || parsed < 1) {
		return fallback;
	}

	return Math.min(max, Math.max(1, Math.round(parsed)));
}
