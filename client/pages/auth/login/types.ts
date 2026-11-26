import type { LucideIcon } from "lucide-react";

export interface LoginHighlightItem {
	icon: LucideIcon;
	label: string;
	desc: string;
}

export interface LoginHighlightsProps {
	highlights: LoginHighlightItem[];
}
