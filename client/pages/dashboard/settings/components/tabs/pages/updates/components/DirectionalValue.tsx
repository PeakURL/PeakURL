import type { DirectionalValueProps } from "../types";

/**
 * Keeps version numbers, dates, and localized values readable in LTR and RTL.
 */
function DirectionalValue({
	children,
	direction = "auto",
}: DirectionalValueProps) {
	if ("ltr" === direction) {
		return (
			<span className="preserve-ltr-value inline-block">{children}</span>
		);
	}

	if ("rtl" === direction) {
		return (
			<span dir="rtl" className="inline-block">
				{children}
			</span>
		);
	}

	return <bdi dir="auto">{children}</bdi>;
}

export default DirectionalValue;
