# VS Code Workspace Setup

PeakURL ignores the repo-local `.vscode/` folder so editor settings stay local.

If you want to recreate the current workspace setup, create `.vscode/settings.json` and `.vscode/extensions.json` with the following content.

## `settings.json`

```json
{
	"editor.formatOnSave": true,
	"editor.defaultFormatter": "esbenp.prettier-vscode",
	"editor.detectIndentation": false,
	"editor.insertSpaces": false,
	"editor.tabSize": 4,
	"prettier.useTabs": true,
	"prettier.tabWidth": 4,
	"prettier.requireConfig": true,
	"[javascript]": {
		"editor.defaultFormatter": "esbenp.prettier-vscode"
	},
	"[javascriptreact]": {
		"editor.defaultFormatter": "esbenp.prettier-vscode"
	},
	"[typescript]": {
		"editor.defaultFormatter": "esbenp.prettier-vscode"
	},
	"[typescriptreact]": {
		"editor.defaultFormatter": "esbenp.prettier-vscode"
	},
	"[json]": {
		"editor.defaultFormatter": "esbenp.prettier-vscode"
	},
	"[jsonc]": {
		"editor.defaultFormatter": "esbenp.prettier-vscode"
	},
	"[html]": {
		"editor.defaultFormatter": "esbenp.prettier-vscode"
	},
	"[css]": {
		"editor.defaultFormatter": "esbenp.prettier-vscode"
	},
	"[scss]": {
		"editor.defaultFormatter": "esbenp.prettier-vscode"
	},
	"[markdown]": {
		"editor.defaultFormatter": "esbenp.prettier-vscode"
	},
	"[yaml]": {
		"editor.defaultFormatter": "esbenp.prettier-vscode"
	},
	"[php]": {
		"editor.formatOnSave": false
	},
	"files.watcherExclude": {
		"**/build/**": true,
		"**/release/**": true,
		"**/app/vendor/**": true,
		"**/content/**": true
	},
	"search.exclude": {
		"**/build/**": true,
		"**/release/**": true,
		"**/app/vendor/**": true,
		"**/content/**": true
	},
	"emeraldwalk.runonsave": {
		"commands": [
			{
				"match": "\\.php$",
				"cmd": "\"${workspaceFolder}/app/vendor/bin/phpcbf\" --standard=\"${workspaceFolder}/phpcs.xml\" --report=none \"${file}\"",
				"isAsync": true
			}
		]
	}
}
```

## `extensions.json`

```json
{
	"recommendations": ["esbenp.prettier-vscode", "emeraldwalk.runonsave"]
}
```

## Notes

- PHP formatting is intentionally handled through `phpcbf`, not the default VS Code PHP formatter.
- Web files should follow the repo-root `.prettierrc.json`, `.prettierignore`, and `.editorconfig` rather than ad-hoc editor defaults.
- The watcher exclusions keep VS Code from re-scanning generated output and vendor/runtime content.
