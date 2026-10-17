import {
	getStatusIllustrationUrl,
	type StatusIllustrationName,
} from "@/assets/status-illustrations";

interface PageIllustrationProps {
	name: StatusIllustrationName;
	className?: string;
}

/** Decorative local artwork shared by authentication and recovery layouts. */
export function PageIllustration({ name, className }: PageIllustrationProps) {
	return (
		<img
			className={className}
			data-page-illustration={name}
			src={getStatusIllustrationUrl(name)}
			alt=""
			aria-hidden="true"
			onContextMenu={(event) => event.preventDefault()}
			draggable={false}
			width="360"
			height="280"
		/>
	);
}
