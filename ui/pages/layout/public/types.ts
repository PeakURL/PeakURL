import type { LucideIcon } from "lucide-react";
import type { ReactNode } from "react";

export interface AuthLayoutProps {
	backLabel?: string;
	backTo?: string;
	badgeIcon: LucideIcon;
	badgeLabel: string;
	cardCopy: string;
	cardTitle: string;
	children: ReactNode;
	containerClassName?: string;
	noteCopy: string;
	noteTitle: string;
	pageClassName?: string;
	showcaseCopy: string;
	showcaseTitle: string;
}
