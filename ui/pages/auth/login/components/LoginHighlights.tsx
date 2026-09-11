import type { LoginHighlightsProps } from "../types";

export function LoginHighlights({ highlights }: LoginHighlightsProps) {
	return (
		<div className="login-page-highlight-list">
			{highlights.map((feature, i) => {
				const Icon = feature.icon;
				return (
					<div key={i} className="login-page-highlight">
						<Icon size={18} className="login-page-highlight-icon" />
						<div>
							<p className="login-page-highlight-title">
								{feature.label}
							</p>
							<p className="login-page-highlight-copy">
								{feature.desc}
							</p>
						</div>
					</div>
				);
			})}
		</div>
	);
}
