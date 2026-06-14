import js from "@eslint/js";
import globals from "globals";
import reactHooks from "eslint-plugin-react-hooks";
import reactRefresh from "eslint-plugin-react-refresh";
import tseslint from "typescript-eslint";
import { defineConfig, globalIgnores } from "eslint/config";

export default defineConfig([
	globalIgnores(["build", "release", "app/vendor", "content"]),
	js.configs.recommended,
	tseslint.configs.recommended,
	reactHooks.configs.flat.recommended,
	reactRefresh.configs.vite,
	{
		files: ["**/*.{ts,tsx}"],
		languageOptions: {
			ecmaVersion: 2020,
			globals: globals.browser,
		},
		rules: {
			"@typescript-eslint/ban-ts-comment": "off",
			// Temporary during migration: legacy modules
			"@typescript-eslint/no-unused-vars": "off",
			// Temporary for incremental cleanup of existing code with intentionally unused args/vars.
			// Re-enable once unused declarations are removed or prefixed consistently.
			"no-empty": "off",
			// Temporary to avoid blocking on legacy empty catch/guard blocks.
			// Re-enable after adding explicit handling or explanatory comments in those blocks.
			"prefer-const": "off",
			// Temporary while refactoring mutable legacy bindings.
			// Re-enable after converting eligible declarations to const.
			"react-refresh/only-export-components": "off",
			// Disabled because this project intentionally exports non-component values from module files.
			"react-hooks/set-state-in-effect": "off",
			// Disabled to allow current effect patterns; revisit after effect/state flow refactor.
		},
	},
]);
