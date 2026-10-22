import type { ReactNode } from "react";
import type { LucideIcon } from "lucide-react";

import { cn } from "@/shared/formatting";

export interface PageHeaderProps {
	icon: LucideIcon;
	title: string;
	description: ReactNode;
	actions?: ReactNode;
	docsLink?: ReactNode;
	/** Allows a page to retain positioning, such as the sticky Settings heading. */
	className?: string;
	/** Accessible title when the visible page name is intentionally shorter. */
	titleLabel?: string;
}

/** Shared title, description, and action layout for dashboard pages. */
export function PageHeader({
	icon: Icon,
	title,
	description,
	actions,
	docsLink,
	className,
	titleLabel,
}: PageHeaderProps) {
	return (
		<header className={cn("page-header", className)}>
			<div className="page-header-copy">
				<span className="page-header-icon" aria-hidden="true">
					<Icon size={21} strokeWidth={1.75} />
				</span>
				<div className="page-header-text">
					<h1 className="page-header-title" aria-label={titleLabel}>
						{title}
					</h1>
					<p className="page-header-description">{description}</p>
					{docsLink && (
						<div className="page-header-docs-row">{docsLink}</div>
					)}
				</div>
			</div>
			{actions && <div className="page-header-actions">{actions}</div>}
		</header>
	);
}
