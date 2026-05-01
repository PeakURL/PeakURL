import type { UpdateSectionProps } from "../types";

/**
 * Provides the bordered wrapper for each update-management section.
 */
function UpdateSection({ children }: UpdateSectionProps) {
	return <div className="settings-updates-card">{children}</div>;
}

export default UpdateSection;
