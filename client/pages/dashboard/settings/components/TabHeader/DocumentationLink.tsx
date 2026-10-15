import { BookOpen, ArrowUpRight } from "lucide-react";

import { __ } from "@/i18n";

/** Consistent external documentation action within Settings headings. */
export function DocumentationLink({
	href,
	label = __("Documentation"),
}: {
	href: string;
	label?: string;
}) {
	return (
		<a
			href={href}
			target="_blank"
			rel="noopener noreferrer"
			className="settings-documentation-link"
		>
			<BookOpen size={15} aria-hidden="true" />
			{label}
			<ArrowUpRight size={14} aria-hidden="true" />
		</a>
	);
}
