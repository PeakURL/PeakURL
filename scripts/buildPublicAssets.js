import { readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "vite";

const root = fileURLToPath(new URL("../", import.meta.url));
// Preserve runtime-relative paths so release assembly can overlay these files on PHP sources.
const entries = [
	"features/links/publicCaptcha.js",
	"services/install/installer.js",
	"services/install/languagePicker.js",
];
for (const entry of entries) {
	const outputDirectory = path.join(
		root,
		"build/server",
		path.dirname(entry)
	);
	await build({
		configFile: false,
		root,
		publicDir: false,
		build: {
			outDir: outputDirectory,
			emptyOutDir: false,
			target: "es2020",
			minify: true,
			sourcemap: false,
			lib: {
				entry: path.join(root, "server", entry),
				formats: ["iife"],
				name: "PeakURLPublicPage",
				fileName: () => path.basename(entry),
			},
		},
	});
}
// PHP injects the escaped public configuration after minification, once per response.
const captcha = await readFile(
	path.join(root, "build/server/features/links/publicCaptcha.js"),
	"utf8"
);
if (captcha.split("__PEAKURL_CAPTCHA_CONFIG__").length !== 2)
	throw new Error(
		"The public CAPTCHA configuration marker must survive the production build exactly once."
	);
