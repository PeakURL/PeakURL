export const DEFAULT_PAGE_SIZE_OPTIONS: readonly number[] = [25, 50, 100, 150];

export function normalizePageSize(
	value: number | string | null | undefined,
	fallback: number = DEFAULT_PAGE_SIZE_OPTIONS[0] ?? 25,
	max?: number
): number {
	const parsed = Number(value);

	if (!Number.isFinite(parsed) || parsed < 1) {
		return fallback;
	}

	const integerVal = Math.max(1, Math.round(parsed));

	return typeof max === "number" && max > 0
		? Math.min(max, integerVal)
		: integerVal;
}
