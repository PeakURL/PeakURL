import type { PluginCardData } from "./_components/types";

/**
 * Anonymous placeholder cards for the plugins preview page.
 * No real plugin names or descriptions are exposed — only gradient
 * banners and skeleton-bar width classes so every card looks unique.
 */

const GRADIENTS = [
	"from-indigo-500 via-purple-500 to-pink-500",
	"from-cyan-500 via-blue-500 to-indigo-500",
	"from-emerald-500 via-teal-500 to-cyan-500",
	"from-orange-500 via-red-500 to-pink-500",
	"from-violet-500 via-purple-500 to-fuchsia-600",
	"from-rose-500 via-pink-500 to-red-600",
	"from-amber-500 via-orange-500 to-red-500",
	"from-lime-500 via-emerald-500 to-teal-600",
	"from-sky-500 via-cyan-500 to-blue-600",
	"from-fuchsia-500 via-pink-500 to-rose-500",
	"from-teal-500 via-emerald-500 to-green-600",
	"from-blue-500 via-indigo-500 to-violet-600",
];

const BAR_COMBOS: [string, string, string][] = [
	["w-28", "w-full", "w-4/5"],
	["w-32", "w-5/6", "w-3/4"],
	["w-24", "w-full", "w-2/3"],
	["w-36", "w-4/5", "w-full"],
	["w-20", "w-full", "w-5/6"],
	["w-30", "w-3/4", "w-full"],
	["w-28", "w-5/6", "w-4/5"],
	["w-24", "w-full", "w-3/5"],
	["w-32", "w-4/5", "w-5/6"],
	["w-36", "w-full", "w-3/4"],
	["w-20", "w-5/6", "w-full"],
	["w-28", "w-3/4", "w-5/6"],
];

function buildCards(count: number, offset = 0): PluginCardData[] {
	return Array.from({ length: count }, (_, i) => ({
		id: `placeholder-${offset + i}`,
		gradient: GRADIENTS[(offset + i) % GRADIENTS.length],
		barWidths: BAR_COMBOS[(offset + i) % BAR_COMBOS.length],
	}));
}

export const BROWSE_CARDS = buildCards(12);
export const INSTALLED_CARDS = buildCards(3, 20);
export const FEATURED_CARDS = buildCards(8, 40);
export const POPULAR_CARDS = buildCards(8, 60);
