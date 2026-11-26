import { Link } from "react-router";

import { __ } from "@/i18n";

import type { BreadcrumbItem } from "./getBreadcrumbs";

export interface BreadcrumbsProps {
	items: BreadcrumbItem[];
}

/** Compact top-bar location trail with accessible non-interactive current page. */
export function Breadcrumbs({ items }: BreadcrumbsProps) {
	if (items.length === 0) {
		return null;
	}

	return (
		<nav aria-label={__("Breadcrumb")} className="dashboard-breadcrumbs">
			<ol className="dashboard-breadcrumbs-list">
				{items.map((item, index) => {
					const isLast = index === items.length - 1;
					return (
						<li
							key={`${index}-${item.label}`}
							className="dashboard-breadcrumbs-item"
						>
							{index > 0 && (
								<span
									className="dashboard-breadcrumbs-separator"
									aria-hidden="true"
								>
									/
								</span>
							)}
							{!isLast && item.to ? (
								<Link
									to={item.to}
									className="dashboard-breadcrumbs-link"
								>
									{item.label}
								</Link>
							) : (
								<span
									className={
										isLast
											? "dashboard-breadcrumbs-current"
											: "dashboard-breadcrumbs-ancestor"
									}
									aria-current={isLast ? "page" : undefined}
								>
									{item.label}
								</span>
							)}
						</li>
					);
				})}
			</ol>
		</nav>
	);
}
