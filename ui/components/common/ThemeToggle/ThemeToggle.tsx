import { Moon, Sun } from "lucide-react";

import { __ } from "@/i18n";

import { useTheme } from "../../providers/ThemeProvider";

export const ThemeToggle = () => {
	const { theme, toggleTheme } = useTheme();

	return (
		<button
			type="button"
			onClick={toggleTheme}
			className="theme-toggle"
			aria-label={__("Toggle theme")}
		>
			<div className="theme-toggle-icon-wrapper">
				<Sun
					className={`theme-toggle-icon ${
						"dark" === theme
							? "theme-toggle-icon-hidden theme-toggle-icon-hidden-sun"
							: "theme-toggle-icon-visible"
					}`}
				/>
				<Moon
					className={`theme-toggle-icon ${
						"dark" === theme
							? "theme-toggle-icon-visible"
							: "theme-toggle-icon-hidden theme-toggle-icon-hidden-moon"
					}`}
				/>
			</div>
		</button>
	);
};
