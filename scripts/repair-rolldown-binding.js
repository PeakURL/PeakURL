import { spawnSync, execSync } from "node:child_process";
import { createRequire } from "node:module";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";
import process from "node:process";

const require = createRequire(import.meta.url);
const rootDir = path.resolve(
	path.dirname(fileURLToPath(import.meta.url)),
	".."
);
const npmCommand = process.platform === "win32" ? "npm.cmd" : "npm";
const isCheckOnly = process.argv.includes("--check");

function getRolldownVersion() {
	return require(path.join(rootDir, "node_modules/rolldown/package.json"))
		.version;
}

function packageVersion(name) {
	try {
		return require(path.join(rootDir, `node_modules/${name}/package.json`))
			.version;
	} catch {
		return null;
	}
}

function isMusl() {
	if (process.platform !== "linux") {
		return false;
	}

	try {
		return readFileSync("/usr/bin/ldd", "utf8").includes("musl");
	} catch {}

	if (typeof process.report?.getReport === "function") {
		const report = process.report.getReport();

		if (report?.header?.glibcVersionRuntime) {
			return false;
		}

		if (
			Array.isArray(report?.sharedObjects) &&
			report.sharedObjects.some(
				(file) =>
					file.includes("libc.musl-") || file.includes("ld-musl-")
			)
		) {
			return true;
		}
	}

	try {
		return execSync("ldd --version", { encoding: "utf8" }).includes("musl");
	} catch {
		return false;
	}
}

function resolveNativeBindingName() {
	if (process.platform === "darwin") {
		if (process.arch === "x64") {
			return "@rolldown/binding-darwin-x64";
		}

		if (process.arch === "arm64") {
			return "@rolldown/binding-darwin-arm64";
		}
	}

	if (process.platform === "linux") {
		if (process.arch === "x64") {
			return isMusl()
				? "@rolldown/binding-linux-x64-musl"
				: "@rolldown/binding-linux-x64-gnu";
		}

		if (process.arch === "arm64") {
			return isMusl()
				? "@rolldown/binding-linux-arm64-musl"
				: "@rolldown/binding-linux-arm64-gnu";
		}

		if (process.arch === "arm") {
			return "@rolldown/binding-linux-arm-gnueabihf";
		}
	}

	if (process.platform === "win32") {
		if (process.arch === "x64") {
			return "@rolldown/binding-win32-x64-msvc";
		}

		if (process.arch === "arm64") {
			return "@rolldown/binding-win32-arm64-msvc";
		}
	}

	if (process.platform === "freebsd" && process.arch === "x64") {
		return "@rolldown/binding-freebsd-x64";
	}

	return "@rolldown/binding-wasm32-wasi";
}

function repairPackage(name, version) {
	const existingVersion = packageVersion(name);

	if (existingVersion === version) {
		return true;
	}

	if (isCheckOnly) {
		console.warn(
			`[peakurl] Missing rolldown runtime package ${name}@${version}.`
		);
		return false;
	}

	console.warn(
		`[peakurl] Missing rolldown runtime package ${name}@${version}. Installing a local repair for npm optional dependency drift...`
	);

	const installResult = spawnSync(
		npmCommand,
		[
			"install",
			"--no-save",
			"--package-lock=false",
			"--legacy-peer-deps",
			`${name}@${version}`,
		],
		{
			cwd: rootDir,
			stdio: "inherit",
		}
	);

	return 0 === installResult.status && packageVersion(name) === version;
}

const rolldownVersion = getRolldownVersion();
const nativeBinding = resolveNativeBindingName();
const wasiBinding = "@rolldown/binding-wasm32-wasi";

if (repairPackage(nativeBinding, rolldownVersion)) {
	process.exit(0);
}

if (
	nativeBinding !== wasiBinding &&
	repairPackage(wasiBinding, rolldownVersion)
) {
	process.exit(0);
}

console.error(
	`[peakurl] Unable to install a working rolldown runtime binding for ${process.platform}-${process.arch}. Run npm install and try again.`
);
process.exit(1);
