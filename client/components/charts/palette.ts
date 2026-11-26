/** Canvas charts need concrete values for the same semantic tokens used in CSS. */
export function getChartPalette(element: HTMLElement) {
	const styles = getComputedStyle(element);
	const token = (name: string) => styles.getPropertyValue(name).trim();

	return {
		primary: token("--chart-primary"),
		secondary: token("--chart-secondary"),
		surface: token("--surface"),
		heading: token("--text-heading"),
		muted: token("--text-muted"),
		grid: token("--border"),
		resolveColor(color: string) {
			const variable = color.match(/^var\((--[\w-]+)\)$/)?.[1];
			return variable ? token(variable) : color;
		},
	};
}
