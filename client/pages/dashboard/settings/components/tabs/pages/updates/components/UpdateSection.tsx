import type { UpdateSectionProps } from "../types";

/**
 * Provides the bordered wrapper for each update-management section.
 */
function UpdateSection({ children }: UpdateSectionProps) {
	return <section className="settings-fieldset">{children}</section>;
}

export default UpdateSection;
