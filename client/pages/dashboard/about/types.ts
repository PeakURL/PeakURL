import type { ComponentType } from "react";
import type { LucideIcon } from "lucide-react";

export type AboutSource = "install" | "update" | "reinstall";
export type AboutTabId = "overview" | "releases" | "contributors";

export interface AboutTab {
	id: AboutTabId;
	label: string;
	icon: LucideIcon;
}

export interface AboutIconProps {
	className?: string;
}

export interface AddOnLink {
	label: string;
	href: string;
	icon: ComponentType<AboutIconProps>;
}
