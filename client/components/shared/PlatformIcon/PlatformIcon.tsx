import {
	CircleHelp,
	Gamepad2,
	Globe,
	Monitor,
	Smartphone,
	Tablet,
	Tv,
} from "lucide-react";

import {
	android,
	apple,
	brave,
	chrome,
	edge,
	firefox,
	internetExplorer,
	linux,
	opera,
	playstation,
	safari,
	samsungInternet,
	windows,
} from "./icons";

interface PlatformIconProps {
	name: string;
	platform: "browser" | "os" | "device";
}

const BROWSER_ICONS = [
	{ pattern: /chrome|chromium/i, icon: chrome },
	{ pattern: /firefox/i, icon: firefox },
	{ pattern: /safari/i, icon: safari },
	{ pattern: /edge/i, icon: edge },
	{ pattern: /opera/i, icon: opera },
	{ pattern: /samsung internet/i, icon: samsungInternet },
	{ pattern: /android browser/i, icon: android },
	{ pattern: /brave/i, icon: brave },
	{ pattern: /internet explorer/i, icon: internetExplorer },
];

const OS_ICONS = [
	{ pattern: /windows/i, icon: windows },
	{ pattern: /^(?:macos|mac|ios|ipados|tvos|watchos)$/i, icon: apple },
	{ pattern: /android/i, icon: android },
	{ pattern: /linux|ubuntu|debian|fedora/i, icon: linux },
	{ pattern: /chrome\s?os/i, icon: chrome },
	{ pattern: /playstation/i, icon: playstation },
];

function PlatformSymbol({ name, platform }: PlatformIconProps) {
	if (platform === "browser") return <Globe />;
	if (platform === "os")
		return /webos|tizen|roku/i.test(name) ? <Tv /> : <Monitor />;
	switch (name.trim().toLowerCase()) {
		case "desktop":
			return <Monitor />;
		case "mobile":
			return <Smartphone />;
		case "tablet":
			return <Tablet />;
		case "tv":
			return <Tv />;
		case "console":
			return <Gamepad2 />;
		default:
			return <CircleHelp />;
	}
}

/** Decorative platform marks shared by overview and link analytics; unknown names use a neutral icon. */
export function PlatformIcon({ name, platform }: PlatformIconProps) {
	const icons = platform === "browser" ? BROWSER_ICONS : OS_ICONS;
	const icon =
		platform === "device"
			? undefined
			: icons.find(({ pattern }) => pattern.test(name.trim()))?.icon;

	return (
		<span className="analytics-platform-icon" aria-hidden="true">
			{icon ? (
				<span
					className="analytics-platform-mark"
					style={{ maskImage: `url("${icon}")` }}
				/>
			) : (
				<PlatformSymbol name={name} platform={platform} />
			)}
		</span>
	);
}
