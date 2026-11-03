<?php
/**
 * Canonical installed plugin registry.
 *
 * Scans, validates, and discovers plugins from both regular (content/plugins/)
 * and must-use (content/mu-plugins/) roots without executing plugin code.
 *
 * @package PeakURL\Core\Plugins
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Plugins;

use PeakURL\Core\Config\Configuration;
use PeakURL\Core\Config\Constants;
use PeakURL\Core\Config\Environment;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Registry — Discovers installed plugins and holds metadata.
 *
 * @since 1.8.0
 */
class Registry {

	/**
	 * Effective content directory path.
	 *
	 * @var string
	 */
	private string $content_path;

	/**
	 * Manifest and compatibility validator.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * Host PeakURL version override, if provided.
	 *
	 * @var string|null
	 */
	private ?string $host_version;

	/**
	 * Host PHP version override, if provided.
	 *
	 * @var string|null
	 */
	private ?string $php_version;

	/**
	 * Cached inventory of must-use plugins.
	 *
	 * @var array<string, Metadata>|null
	 */
	private ?array $must_use_cache = null;

	/**
	 * Cached inventory of regular plugins.
	 *
	 * @var array<string, Metadata>|null
	 */
	private ?array $regular_cache = null;

	/**
	 * Cached inventory of all discovered plugins.
	 *
	 * @var array<string, Metadata>|null
	 */
	private ?array $cache = null;

	/**
	 * Create a new plugin registry instance.
	 *
	 * @param string|null    $content_path Optional content directory path override.
	 * @param Validator|null $validator    Optional validator instance.
	 * @param string|null    $host_version Optional host PeakURL version override.
	 * @param string|null    $php_version  Optional host PHP version override.
	 */
	public function __construct(
		?string $content_path = null,
		?Validator $validator = null,
		?string $host_version = null,
		?string $php_version = null
	) {
		$this->validator    = $validator ?? new Validator();
		$this->host_version = $host_version;
		$this->php_version  = $php_version;
		$this->content_path = $this->determine_content_path( $content_path );
	}

	/**
	 * Return the effective persistent content path used by the registry.
	 *
	 * @return string
	 */
	public function content_path(): string {
		return $this->content_path;
	}

	/**
	 * Return all discovered plugins across both roots in deterministic alphabetical order.
	 *
	 * Detects cross-root collisions and flags them with diagnostic errors.
	 *
	 * @param bool $refresh Whether to bypass the in-memory cache and re-scan disk.
	 * @return array<string, Metadata> Map of plugin ID to Metadata instance.
	 */
	public function all( bool $refresh = false ): array {
		if ( null !== $this->cache && ! $refresh ) {
			return $this->cache;
		}

		$must_use = $this->scan_root( Type::MUST_USE );
		$regular  = $this->scan_root( Type::REGULAR );

		// Detect collisions where the same directory-derived ID exists in both roots.
		$collisions = array_intersect( array_keys( $must_use ), array_keys( $regular ) );
		foreach ( $collisions as $id ) {
			$collision_error = sprintf(
				'Plugin identifier "%s" exists in both regular and must-use directories. Identifier must be unique.',
				$id
			);

			// Mark both records invalid and incompatible to prevent ambiguous execution.
			$mu_meta         = $must_use[ $id ];
			$must_use[ $id ] = new Metadata(
				array_merge(
					$mu_meta->to_array(),
					array(
						'type'          => Type::MUST_USE,
						'valid'         => false,
						'compatible'    => false,
						'errors'        => array_merge( $mu_meta->errors(), array( $collision_error ) ),
						'plugin_path'   => $mu_meta->plugin_path(),
						'manifest_path' => $mu_meta->manifest_path(),
					)
				)
			);

			$reg_meta       = $regular[ $id ];
			$regular[ $id ] = new Metadata(
				array_merge(
					$reg_meta->to_array(),
					array(
						'type'          => Type::REGULAR,
						'valid'         => false,
						'compatible'    => false,
						'errors'        => array_merge( $reg_meta->errors(), array( $collision_error ) ),
						'plugin_path'   => $reg_meta->plugin_path(),
						'manifest_path' => $reg_meta->manifest_path(),
					)
				)
			);
		}

		$this->must_use_cache = $must_use;
		$this->regular_cache  = $regular;

		// Combine both sets preserving both records on collision.
		$all = array();
		foreach ( $must_use as $id => $meta ) {
			$key         = isset( $regular[ $id ] ) ? 'mu-plugins:' . $id : $id;
			$all[ $key ] = $meta;
		}
		foreach ( $regular as $id => $meta ) {
			$key         = isset( $must_use[ $id ] ) ? 'plugins:' . $id : $id;
			$all[ $key ] = $meta;
		}

		ksort( $all, SORT_STRING );

		$this->cache = $all;
		return $all;
	}

	/**
	 * Return only must-use plugins.
	 *
	 * @return array<string, Metadata>
	 */
	public function must_use(): array {
		if ( null === $this->must_use_cache ) {
			$this->all();
		}

		return $this->must_use_cache ?? array();
	}

	/**
	 * Return only regular plugins.
	 *
	 * @return array<string, Metadata>
	 */
	public function regular(): array {
		if ( null === $this->regular_cache ) {
			$this->all();
		}

		return $this->regular_cache ?? array();
	}

	/**
	 * Find an installed plugin by ID and optional type.
	 *
	 * Applies strict containment and security validation identical to all().
	 *
	 * @param string    $id   Plugin identifier.
	 * @param Type|null $type Optional specific plugin type filter.
	 * @return Metadata|null Metadata if found, null otherwise.
	 */
	public function find( string $id, ?Type $type = null ): ?Metadata {
		if ( ! $this->is_valid_id( $id ) ) {
			return null;
		}

		if ( null === $this->cache ) {
			$this->all();
		}

		if ( Type::MUST_USE === $type ) {
			return $this->must_use_cache[ $id ] ?? null;
		}

		if ( Type::REGULAR === $type ) {
			return $this->regular_cache[ $id ] ?? null;
		}

		// When no specific type is requested: check must-use first, then regular.
		return $this->must_use_cache[ $id ] ?? ( $this->regular_cache[ $id ] ?? null );
	}

	/**
	 * Determine whether an installed plugin exists.
	 *
	 * @param string    $id   Plugin identifier.
	 * @param Type|null $type Optional specific plugin type filter.
	 * @return bool
	 */
	public function has( string $id, ?Type $type = null ): bool {
		return null !== $this->find( $id, $type );
	}

	/**
	 * Scan an individual root directory (plugins or mu-plugins).
	 *
	 * @param Type $type Plugin installation category.
	 * @return array<string, Metadata> Discovered plugins keyed by identifier.
	 */
	private function scan_root( Type $type ): array {
		$folder_name = Type::MUST_USE === $type ? 'mu-plugins' : 'plugins';
		$root_path   = $this->content_path . '/' . $folder_name;

		if ( ! is_dir( $root_path ) ) {
			return array();
		}

		$root_real = realpath( $root_path );
		if ( false === $root_real ) {
			return array();
		}

		$entries = scandir( $root_real );
		if ( false === $entries ) {
			return array();
		}

		$plugins = array();

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			if ( ! $this->is_valid_id( $entry ) ) {
				continue;
			}

			$plugin_dir = $root_real . '/' . $entry;
			if ( ! is_dir( $plugin_dir ) ) {
				continue;
			}

			$plugins[ $entry ] = $this->inspect_directory( $entry, $plugin_dir, $root_real, $type );
		}

		return $plugins;
	}

	/**
	 * Inspect an individual plugin directory and construct its Metadata object.
	 *
	 * Enforces strict filesystem containment on the folder, plugin.json, and plugin.php.
	 * Never executes plugin.php.
	 *
	 * @param string $id         Plugin folder identifier.
	 * @param string $plugin_dir Plugin directory path.
	 * @param string $root_real  Canonical root directory path.
	 * @param Type   $type       Plugin installation type.
	 * @return Metadata
	 */
	private function inspect_directory(
		string $id,
		string $plugin_dir,
		string $root_real,
		Type $type
	): Metadata {
		$plugin_dir_real = realpath( $plugin_dir );

		// 1. Verify directory containment.
		if ( false === $plugin_dir_real || ! $this->is_contained( $plugin_dir_real, $root_real ) ) {
			return Metadata::invalid(
				$id,
				$type,
				array( 'Plugin directory escapes the content root via symlink or traversal.' ),
				$plugin_dir
			);
		}

		$manifest_path = $plugin_dir . '/plugin.json';
		$entry_path    = $plugin_dir . '/plugin.php';

		// 2. Verify plugin.json containment and existence.
		if ( ! is_file( $manifest_path ) ) {
			return Metadata::invalid(
				$id,
				$type,
				array( 'plugin.json is missing or unreadable.' ),
				$plugin_dir_real,
				$manifest_path
			);
		}

		$manifest_real = realpath( $manifest_path );
		if ( false === $manifest_real || ! $this->is_contained( $manifest_real, $plugin_dir_real ) ) {
			return Metadata::invalid(
				$id,
				$type,
				array( 'Plugin manifest plugin.json escapes the plugin directory via symlink.' ),
				$plugin_dir_real,
				$manifest_path
			);
		}

		// 3. Verify plugin.php containment and existence (WITHOUT executing it).
		$entry_errors = array();
		if ( ! is_file( $entry_path ) ) {
			$entry_errors[] = 'Plugin entry file plugin.php is missing from plugin directory.';
		} else {
			$entry_real = realpath( $entry_path );
			if ( false === $entry_real || ! $this->is_contained( $entry_real, $plugin_dir_real ) ) {
				$entry_errors[] = 'Plugin entry file plugin.php escapes the plugin directory via symlink.';
			}
		}

		// 4. Read and parse manifest.
		$parsed = $this->validator->read_manifest( $manifest_real );
		if ( ! $parsed['valid'] ) {
			return Metadata::invalid(
				$id,
				$type,
				array_merge( $parsed['errors'], $entry_errors ),
				$plugin_dir_real,
				$manifest_real
			);
		}

		// 4b. Optional WordPress-inspired PHP comment header inspection (without PHP execution).
		if ( empty( $entry_errors ) && isset( $entry_real ) && is_string( $entry_real ) ) {
			$header_res = $this->validator->read_php_header( $entry_real );
			if ( ! empty( $header_res['errors'] ) ) {
				$entry_errors = array_merge( $entry_errors, $header_res['errors'] );
			} elseif ( $header_res['has_header'] ) {
				$mismatch_errors = $this->validator->validate_manifest_against_header( $parsed['manifest'], $header_res['headers'] );
				if ( ! empty( $mismatch_errors ) ) {
					$entry_errors = array_merge( $entry_errors, $mismatch_errors );
				}
			}
		}

		// 5. Validate manifest structure, types, and host compatibility.
		$validation = $this->validator->validate(
			$parsed['manifest'],
			$this->host_version,
			$this->php_version,
			$id
		);

		$all_errors = array_merge( $validation['errors'], $entry_errors );
		$is_valid   = $validation['valid'] && empty( $entry_errors );

		$description = isset( $parsed['manifest']['description'] ) && is_string( $parsed['manifest']['description'] ) && '' !== trim( $parsed['manifest']['description'] )
			? trim( $parsed['manifest']['description'] )
			: $this->extract_readme_description( $plugin_dir_real );

		return new Metadata(
			array(
				'id'            => $id,
				'type'          => $type,
				'name'          => (string) ( $parsed['manifest']['name'] ?? $id ),
				'description'   => $description,
				'pluginUri'     => (string) ( $parsed['manifest']['pluginUri'] ?? '' ),
				'author'        => (string) ( $parsed['manifest']['author'] ?? '' ),
				'authorUri'     => (string) ( $parsed['manifest']['authorUri'] ?? '' ),
				'version'       => (string) ( $parsed['manifest']['version'] ?? '' ),
				'requires'      => (string) ( $parsed['manifest']['requires'] ?? '' ),
				'testedUpTo'    => (string) ( $parsed['manifest']['testedUpTo'] ?? '' ),
				'php'           => (string) ( $parsed['manifest']['php'] ?? '' ),
				'license'       => (string) ( $parsed['manifest']['license'] ?? '' ),
				'textDomain'    => (string) ( $parsed['manifest']['textDomain'] ?? $id ),
				'domainPath'    => (string) ( $parsed['manifest']['domainPath'] ?? '/languages' ),
				'valid'         => $is_valid,
				'compatible'    => $is_valid && $validation['compatible'],
				'errors'        => $all_errors,
				'warnings'      => $validation['warnings'],
				'links'         => $validation['links'],
				'plugin_path'   => $plugin_dir_real,
				'manifest_path' => $manifest_real,
			)
		);
	}

	/**
	 * Extract a short plain-text description from an optional README file.
	 *
	 * Bounds file reads to 16 KB, validates filesystem containment, and strips markdown/HTML.
	 *
	 * @param string $plugin_dir_real Canonical plugin directory path.
	 * @return string|null Plain-text description or null if unavailable.
	 */
	private function extract_readme_description( string $plugin_dir_real ): ?string {
		$readme_names = array( 'README.md', 'readme.md', 'README.txt', 'readme.txt', 'README' );
		$readme_real  = null;

		foreach ( $readme_names as $name ) {
			$candidate = $plugin_dir_real . '/' . $name;
			if ( is_file( $candidate ) ) {
				$real = realpath( $candidate );
				if ( false !== $real && $this->is_contained( $real, $plugin_dir_real ) ) {
					$readme_real = $real;
					break;
				}
			}
		}

		if ( null === $readme_real ) {
			return null;
		}

		$size = filesize( $readme_real );
		if ( false === $size || 0 === $size ) {
			return null;
		}

		$bytes_to_read = min( $size, 16384 );
		$fp            = fopen( $readme_real, 'rb' );
		if ( false === $fp ) {
			return null;
		}

		$content = fread( $fp, $bytes_to_read );
		fclose( $fp );

		if ( false === $content || '' === trim( $content ) ) {
			return null;
		}

		$content    = str_replace( array( "\r\n", "\r" ), "\n", $content );
		$paragraphs = explode( "\n\n", $content );

		foreach ( $paragraphs as $paragraph ) {
			$lines       = explode( "\n", trim( $paragraph ) );
			$clean_lines = array();

			foreach ( $lines as $line ) {
				$trimmed = trim( $line );
				if ( '' === $trimmed || str_starts_with( $trimmed, '#' ) || str_starts_with( $trimmed, '---' ) || str_starts_with( $trimmed, '===' ) ) {
					continue;
				}
				$clean_lines[] = $trimmed;
			}

			if ( empty( $clean_lines ) ) {
				continue;
			}

			$text = implode( ' ', $clean_lines );
			$text = strip_tags( $text );
			$text = (string) preg_replace( '/\[([^\]]+)\]\([^)]+\)/', '$1', $text );
			$text = (string) preg_replace( '/`([^`]+)`/', '$1', $text );
			$text = (string) preg_replace( '/(\*\*|__)(.*?)\1/', '$2', $text );
			$text = (string) preg_replace( '/(\*|_)(.*?)\1/', '$2', $text );
			$text = trim( $text );

			if ( '' !== $text ) {
				if ( mb_strlen( $text ) > 250 ) {
					$text = mb_substr( $text, 0, 247 ) . '...';
				}

				return $text;
			}
		}

		return null;
	}

	/**
	 * Determine whether an absolute path is contained strictly within a boundary directory.
	 *
	 * @param string $path         Child path to test.
	 * @param string $boundary_dir Parent boundary directory.
	 * @return bool
	 */
	private function is_contained( string $path, string $boundary_dir ): bool {
		$clean_boundary = rtrim( str_replace( '\\', '/', $boundary_dir ), '/' ) . '/';
		$clean_path     = str_replace( '\\', '/', $path );

		return str_starts_with( $clean_path, $clean_boundary ) || rtrim( $clean_boundary, '/' ) === $clean_path;
	}

	/**
	 * Verify that a directory entry is a valid, safe plugin identifier.
	 *
	 * Forbids path traversal, separators, and special characters.
	 *
	 * @param string $id Directory entry name.
	 * @return bool
	 */
	private function is_valid_id( string $id ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9_\-]+$/', $id );
	}

	/**
	 * Determine the authoritative content directory path.
	 *
	 * Respects explicit argument, PEAKURL_CONTENT_DIR constant/configuration,
	 * environment variable, or Environment::get_content_path().
	 *
	 * @param string|null $override Optional directory path override.
	 * @return string Absolute normalized directory path.
	 */
	private function determine_content_path( ?string $override ): string {
		$source_root = class_exists( Environment::class )
			? Environment::get_instance()->get_source_root()
			: ( defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/\\' ) : dirname( __DIR__, 3 ) );

		if ( null !== $override && '' !== trim( $override ) ) {
			return $this->normalize_path( $override, $source_root );
		}

		// Check PHP constant.
		if ( defined( 'PEAKURL_CONTENT_DIR' ) ) {
			$constant_val = constant( 'PEAKURL_CONTENT_DIR' );
			if ( is_string( $constant_val ) && '' !== trim( $constant_val ) ) {
				return $this->normalize_path( $constant_val, $source_root );
			}
		}

		// Check Configuration registry.
		if ( class_exists( Configuration::class ) && defined( Constants::class . '::CONTENT_DIR' ) ) {
			$config = Configuration::get_current();
			$val    = trim( (string) ( $config[ Constants::CONTENT_DIR ] ?? '' ) );
			if ( '' !== $val ) {
				return $this->normalize_path( $val, $source_root );
			}
		}

		// Check runtime environment variable.
		$env_val = getenv( 'PEAKURL_CONTENT_DIR' );
		if ( false === $env_val || '' === $env_val ) {
			$env_val = $_ENV['PEAKURL_CONTENT_DIR'] ?? ( $_SERVER['PEAKURL_CONTENT_DIR'] ?? null );
		}
		if ( is_string( $env_val ) && '' !== trim( $env_val ) ) {
			return $this->normalize_path( $env_val, $source_root );
		}

		// Fall back to Environment service default.
		if ( class_exists( Environment::class ) ) {
			return Environment::get_instance()->get_content_path();
		}

		return $source_root . '/content';
	}

	/**
	 * Normalize a potentially relative path against the source root.
	 *
	 * @param string $path        Absolute or relative path.
	 * @param string $source_root Base repository or release root.
	 * @return string
	 */
	private function normalize_path( string $path, string $source_root ): string {
		$clean = trim( $path );

		if ( str_starts_with( $clean, '/' ) || preg_match( '/^[A-Za-z]:[\\\\\\/]/', $clean ) ) {
			return rtrim( $clean, '/\\' );
		}

		return rtrim( $source_root, '/\\' ) . '/' . ltrim( $clean, '/\\' );
	}
}
