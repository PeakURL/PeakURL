import {
	getStatusIllustrationUrl,
	type StatusIllustrationName,
} from "@/assets/status-illustrations";
import { useTheme } from "@/providers/ThemeProvider";

interface PageIllustrationProps {
	name: StatusIllustrationName;
	className?: string;
}

/** Decorative local artwork shared by authentication and recovery layouts. */
export function PageIllustration({ name, className }: PageIllustrationProps) {
	const { theme } = useTheme();
	return (
		<img
			className={className}
			data-page-illustration={name}
			src={getStatusIllustrationUrl(name, theme)}
			alt=""
			aria-hidden="true"
			onContextMenu={(event) => event.preventDefault()}
			draggable={false}
			width="360"
			height="280"
		/>
	);
}
