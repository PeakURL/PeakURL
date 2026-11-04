# Example Plugin

Demonstrates standard regular plugin packaging and registers an example filter hook (`peakurl_example_plugin_filter`) to verify hook execution and activation lifecycle.

## Technical Details

- **Location:** `content/plugins/example-plugin/`
- **Type:** Regular plugin
- **Lifecycle:** Discovered during local inventory scans. Requires explicit activation prior to runtime execution.
- **Hook Registered:** `peakurl_example_plugin_filter`
