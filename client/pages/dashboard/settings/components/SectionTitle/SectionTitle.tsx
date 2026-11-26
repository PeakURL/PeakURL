import type { ReactNode } from "react";

/** Shared heading for Settings forms and management panels. */
export function SectionTitle({ children }: { children: ReactNode }) {
	return <h2 className="settings-section-title">{children}</h2>;
}
