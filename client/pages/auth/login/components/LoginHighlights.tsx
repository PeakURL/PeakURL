import type { LoginHighlightsProps } from "../types";

export function LoginHighlights({ highlights }: LoginHighlightsProps) {
	return (
		<div className="login-page-highlight-list">
			{highlights.map((highlight) => {
				const Icon = highlight.icon;
				return (
					<div key={highlight.label} className="login-page-highlight">
						<Icon size={16} className="login-page-highlight-icon" />
						<div>
							<p className="login-page-highlight-title">
								{highlight.label}
							</p>
							<p className="login-page-highlight-copy">
								{highlight.desc}
							</p>
						</div>
					</div>
				);
			})}
		</div>
	);
}
