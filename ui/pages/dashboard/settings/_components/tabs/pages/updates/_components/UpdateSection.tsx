import type { UpdateSectionProps } from "../types";

/**
 * Provides the bordered wrapper for each update-management section.
 */
function UpdateSection({ children }: UpdateSectionProps) {
	return <fieldset className="settings-fieldset">{children}</fieldset>;
}

export default UpdateSection;
