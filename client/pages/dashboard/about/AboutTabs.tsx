import { FileText, LayoutList, Users } from "lucide-react";
import { __ } from "@/i18n";
import { cn } from "@/shared/formatting";
import type { AboutTab, AboutTabId } from "./types";

interface AboutTabsProps {
	activeTab: AboutTabId;
	onChange: (tab: AboutTabId) => void;
}

/** A single tab stop with arrow-key navigation, including right-to-left layouts. */
export function AboutTabs({ activeTab, onChange }: AboutTabsProps) {
	const tabs: AboutTab[] = [
		{ id: "overview", label: __("Overview"), icon: LayoutList },
		{ id: "releases", label: __("Release notes"), icon: FileText },
		{ id: "contributors", label: __("Contributors"), icon: Users },
	];
	return (
		<div
			className="about-tabs"
			role="tablist"
			aria-label={__("About PeakURL")}
		>
			{tabs.map(({ id, label, icon: Icon }, index) => (
				<button
					key={id}
					id={`about-tab-${id}`}
					type="button"
					role="tab"
					aria-selected={activeTab === id}
					aria-controls="about-panel"
					tabIndex={activeTab === id ? 0 : -1}
					className={cn(
						"about-tab",
						activeTab === id && "about-tab-active"
					)}
					onClick={() => onChange(id)}
					onKeyDown={(event) => {
						const rtl =
							getComputedStyle(event.currentTarget).direction ===
							"rtl";
						let next: number;
						switch (event.key) {
							case "ArrowRight":
								next = index + (rtl ? -1 : 1);
								break;
							case "ArrowLeft":
								next = index + (rtl ? 1 : -1);
								break;
							case "Home":
								next = 0;
								break;
							case "End":
								next = tabs.length - 1;
								break;
							default:
								return;
						}
						event.preventDefault();
						next = (next + tabs.length) % tabs.length;
						const tab = tabs[next];
						if (tab) {
							onChange(tab.id);
							const buttons =
								event.currentTarget.parentElement?.querySelectorAll<HTMLButtonElement>(
									'[role="tab"]'
								);
							buttons?.[next]?.focus();
						}
					}}
				>
					<Icon className="about-tab-icon" aria-hidden="true" />
					<span>{label}</span>
				</button>
			))}
		</div>
	);
}
