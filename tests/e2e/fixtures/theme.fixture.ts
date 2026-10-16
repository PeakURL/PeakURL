/** WCAG contrast ratio for opaque computed RGB colors in browser appearance checks. */
export function getContrastRatio(first: string, second: string): number {
	const luminance = (color: string) => {
		const channels = (color.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
		if (channels.length !== 3 || !color.startsWith("rgb"))
			throw new Error(`Unsupported color: ${color}`);
		return channels.reduce((total, channel, index) => {
			const value = channel / 255;
			return (
				total +
				(value <= 0.04045
					? value / 12.92
					: ((value + 0.055) / 1.055) ** 2.4) *
					[0.2126, 0.7152, 0.0722][index]
			);
		}, 0);
	};
	const values = [luminance(first), luminance(second)].sort((a, b) => a - b);
	return (values[1] + 0.05) / (values[0] + 0.05);
}
