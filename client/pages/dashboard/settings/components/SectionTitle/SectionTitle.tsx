import type { ReactNode } from "react";
import type { LucideIcon } from "lucide-react";

interface SectionTitleProps {
	icon: LucideIcon;
	children: ReactNode;
}

/** Consistent section identity for settings forms, status summaries, and controls. */
export function SectionTitle({ icon: Icon, children }: SectionTitleProps) {
	return (
		<h2 className="settings-section-title">
			<span className="settings-section-icon" aria-hidden="true">
				<Icon size={18} />
			</span>
			<span>{children}</span>
		</h2>
	);
}
