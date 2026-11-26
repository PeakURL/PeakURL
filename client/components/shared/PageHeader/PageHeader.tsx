import type { ReactNode } from "react";

import { cn } from "@/shared/formatting";

export interface PageHeaderProps {
	title: string;
	description: ReactNode;
	actions?: ReactNode;
	docsLink?: ReactNode;
	/** Optional page-specific layout classes. */
	className?: string;
	/** Accessible title when the visible page name is intentionally shorter. */
	titleLabel?: string;
}

/** Shared title, description, and action layout for dashboard pages. */
export function PageHeader({
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
