import fs from "node:fs";
import path from "node:path";
import { test, expect } from "@playwright/test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";

import {
	LogoIcon,
	Logo,
	PoweredBy,
	Wordmark,
} from "../../client/components/shared";

const canonicalSvgPath = path.resolve(
	import.meta.dirname,
	"../../client/assets/logo/peakurl.svg"
);
const canonicalWordmarkPath = path.resolve(
	import.meta.dirname,
	"../../client/assets/logo/peakurl-wordmark.svg"
);

function extractSvgPrimitives(svgMarkup: string) {
	const rects = Array.from(
		svgMarkup.matchAll(/<rect\b([^>]*?)(?:\/>|><\/rect>)/g),
		(match) => match[1]
	).map((attrs) => ({
		x: attrs.match(/\bx="([^"]+)"/)?.[1],
		y: attrs.match(/\by="([^"]+)"/)?.[1],
		width: attrs.match(/\bwidth="([^"]+)"/)?.[1],
		height: attrs.match(/\bheight="([^"]+)"/)?.[1],
		rx: attrs.match(/\brx="([^"]+)"/)?.[1],
	}));
	const circleMatch = svgMarkup.match(/<circle\b([^>]*?)(?:\/>|><\/circle>)/);
	const circleAttrs = circleMatch?.[1] ?? "";
	return {
		viewBox: svgMarkup.match(/\bviewBox="([^"]+)"/)?.[1],
		rects,
		circle: {
			cx: circleAttrs.match(/\bcx="([^"]+)"/)?.[1],
			cy: circleAttrs.match(/\bcy="([^"]+)"/)?.[1],
			r: circleAttrs.match(/\br="([^"]+)"/)?.[1],
		},
	};
}

test.describe("Stage 3.2 Brand Assets, LogoIcon & Vector Wordmark", () => {
	test("inline LogoIcon geometry and compact viewBox match exported peakurl.svg", () => {
		const canonicalSvg = fs.readFileSync(canonicalSvgPath, "utf8");
		const reactMarkHtml = renderToStaticMarkup(
			React.createElement(LogoIcon, { size: 48 })
		);

		const primitives = extractSvgPrimitives(reactMarkHtml);
		expect(primitives).toEqual(extractSvgPrimitives(canonicalSvg));
		expect(primitives.viewBox).toBe("12 12.5 72 72");
		expect(reactMarkHtml).toContain(
			'fill="var(--logo-foreground, #126e68)"'
		);
		expect(reactMarkHtml).toContain('fill="var(--logo-dot, #17373b)"');
		expect(reactMarkHtml).toContain('aria-hidden="true"');
	});

	test("Wordmark SVG path matches canonical peakurl-wordmark.svg outlines", () => {
		const canonicalWordmark = fs.readFileSync(
			canonicalWordmarkPath,
			"utf8"
		);
		const reactWordmarkHtml = renderToStaticMarkup(
			React.createElement(Wordmark)
		);

		expect(reactWordmarkHtml.match(/\bviewBox="([^"]+)"/)?.[1]).toBe(
			canonicalWordmark.match(/\bviewBox="([^"]+)"/)?.[1]
		);
		expect(reactWordmarkHtml.match(/\bd="([^"]+)"/)?.[1]).toBe(
			canonicalWordmark.match(/\bd="([^"]+)"/)?.[1]
		);
		expect(reactWordmarkHtml).toContain('fill="currentColor"');
		expect(reactWordmarkHtml).toContain('aria-hidden="true"');
	});

	test("Logo and PoweredBy render theme-aware LogoIcon and vector wordmark", () => {
		const iconOnlyLogoHtml = renderToStaticMarkup(
			React.createElement(Logo, { showName: false, size: "sm" })
		);
		expect(iconOnlyLogoHtml).toContain('aria-label="PeakURL"');
		expect(iconOnlyLogoHtml).toContain('role="img"');
		expect(iconOnlyLogoHtml).not.toContain("<img");

		const namedLogoHtml = renderToStaticMarkup(
			React.createElement(Logo, {
				showName: true,
				size: "md",
				tone: "dark",
			})
		);
		expect(namedLogoHtml).toContain("logo-dark");
		expect(namedLogoHtml).toContain('class="logo-name"');
		expect(namedLogoHtml).toContain("logo-wordmark-svg");
		expect(namedLogoHtml).toContain("PeakURL");
		expect(namedLogoHtml).not.toContain("<img");

		const poweredByHtml = renderToStaticMarkup(
			React.createElement(PoweredBy, { inverse: true })
		);
		expect(poweredByHtml).toContain("powered-by-inverse");
		expect(poweredByHtml).toContain("powered-by-mark");
		expect(poweredByHtml).toContain("powered-by-wordmark");
		expect(poweredByHtml).toContain("var(--logo-foreground, #126e68)");
		expect(poweredByHtml).not.toContain("<img");
	});

	test("default light and dark SVG favicons match the compact transparent Compression mark", () => {
		const lightFavicon = fs.readFileSync(
			path.resolve(
				import.meta.dirname,
				"../../server/public/default-favicon-light.svg"
			),
			"utf8"
		);
		const darkFavicon = fs.readFileSync(
			path.resolve(
				import.meta.dirname,
				"../../server/public/default-favicon-dark.svg"
			),
			"utf8"
		);

		expect(lightFavicon).toContain('viewBox="18.5 19 59 59"');
		expect(lightFavicon).toContain('fill="#126e68"');
		expect(lightFavicon).toContain('fill="#17373b"');
		expect(darkFavicon).toContain('viewBox="18.5 19 59 59"');
		expect(darkFavicon).toContain('fill="#83dccc"');
		expect(darkFavicon).toContain('fill="#ffffff"');
	});
});
