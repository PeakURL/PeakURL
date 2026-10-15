import type { UpdateSectionProps } from "../types";

/**
 * Groups the controls for one update-management section.
 */
function UpdateSection({ children }: UpdateSectionProps) {
	return (
		<section className="settings-section settings-updates-section">
			{children}
		</section>
	);
}

export default UpdateSection;
