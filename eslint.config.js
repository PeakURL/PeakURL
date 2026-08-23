import js from "@eslint/js";
import globals from "globals";
import jsxA11y from "eslint-plugin-jsx-a11y";
import reactHooks from "eslint-plugin-react-hooks";
import reactRefresh from "eslint-plugin-react-refresh";
import tseslint from "typescript-eslint";
import { defineConfig, globalIgnores } from "eslint/config";

export default defineConfig([
	// Global ignores for build artifacts, vendor libraries, and generated files
	globalIgnores([
		"build/**",
		"release/**",
		"app/vendor/**",
		"content/**",
		"dist/**",
		"node_modules/**",
		"coverage/**",
		"*.log",
	]),

	// Base recommended presets
	js.configs.recommended,
	...tseslint.configs.recommended,
	reactHooks.configs.flat.recommended,
	reactRefresh.configs.vite,
	jsxA11y.flatConfigs.recommended,

	// UI & Source TypeScript / TSX rules
	{
		files: ["ui/**/*.{ts,tsx}"],
		languageOptions: {
			ecmaVersion: 2023,
			sourceType: "module",
			globals: {
				...globals.browser,
				...globals.es2021,
			},
		},
		rules: {
			// ==========================================
			// 1. Security & Defensive Logic
			// ==========================================
			eqeqeq: ["error", "always"],
			"no-eval": "error",
			"no-implied-eval": "error",
			"no-new-func": "error",
			"no-caller": "error",
			"no-script-url": "error",
			"@typescript-eslint/ban-ts-comment": [
				"error",
				{
					"ts-ignore": "allow-with-description",
					"ts-expect-error": "allow-with-description",
					minimumDescriptionLength: 3,
				},
			],

			// ==========================================
			// 2. TypeScript & Import Hygiene
			// ==========================================
			"@typescript-eslint/consistent-type-imports": [
				"error",
				{
					prefer: "type-imports",
					fixStyle: "separate-type-imports",
				},
			],
			"@typescript-eslint/no-unused-vars": [
				"error",
				{
					argsIgnorePattern: "^_",
					varsIgnorePattern: "^_",
					caughtErrorsIgnorePattern: "^_",
				},
			],
			"@typescript-eslint/no-explicit-any": [
				"warn",
				{
					ignoreRestArgs: true,
				},
			],
			"@typescript-eslint/no-wrapper-object-types": "error",
			"@typescript-eslint/no-unsafe-function-type": "error",
			"@typescript-eslint/no-empty-object-type": "error",

			// ==========================================
			// 3. React 19 & Lifecycle Safety
			// ==========================================
			"react-hooks/rules-of-hooks": "error",
			"react-hooks/exhaustive-deps": "error",
			"react-hooks/set-state-in-effect": "warn",
			"react-refresh/only-export-components": [
				"warn",
				{ allowConstantExport: true },
			],

			// ==========================================
			// 4. Accessibility (a11y)
			// ==========================================
			"jsx-a11y/no-autofocus": "warn",
			"jsx-a11y/click-events-have-key-events": "warn",
			"jsx-a11y/no-static-element-interactions": "warn",

			// ==========================================
			// 5. Modern Code Quality & Standards
			// ==========================================
			"prefer-const": "error",
			"no-var": "error",
			"prefer-template": "warn",
			"object-shorthand": ["warn", "always"],
			"no-useless-concat": "error",
			"no-useless-rename": "error",
			"no-empty": ["error", { allowEmptyCatch: true }],
			"no-console": ["warn", { allow: ["warn", "error", "info"] }],
			"no-debugger": "error",
			"no-alert": "warn",
		},
	},

	// Entrypoint override for application mount (no component exports)
	{
		files: ["ui/PeakURL.tsx"],
		rules: {
			"react-refresh/only-export-components": "off",
		},
	},

	// Build & Config files (Node environment)
	{
		files: ["*.config.{js,ts}", "scripts/**/*.{js,ts}"],
		languageOptions: {
			ecmaVersion: 2023,
			globals: {
				...globals.node,
			},
		},
	},
]);
