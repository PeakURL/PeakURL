import type { LucideIcon } from "lucide-react";
import type { ReactNode } from "react";

import type { StatusIllustrationName } from "@/assets/status-illustrations";

export interface AuthLayoutProps {
	backLabel?: string;
	backTo?: string;
	badgeIcon?: LucideIcon;
	badgeLabel?: string;
	brandHref?: string;
	cardCopy: string;
	cardTitle: string;
	children: ReactNode;
	illustration: StatusIllustrationName;
	noteCopy?: string;
	noteTitle?: string;
	poweredByHref?: string;
	showcaseFooter?: ReactNode;
	showcaseCopy: string;
	showcaseTitle: ReactNode;
}
