<?php
/**
 * Plugin metadata value object.
 *
 * Encapsulates immutable validated manifest details, compatibility flags,
 * and diagnostic errors/warnings for an installed plugin.
 *
 * @package PeakURL\Core\Plugins
 * @since 1.8.0
 */

declare(strict_types=1);

namespace PeakURL\Core\Plugins;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access forbidden.' );
}

/**
 * Metadata — Value object representing discovered plugin metadata.
 *
 * @since 1.8.0
 */
final class Metadata {

	/**
	 * Local plugin identifier (derived from folder name).
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Plugin type (regular or must-use).
	 *
	 * @var Type
	 */
	private Type $type;

	/**
	 * Human-readable display name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Semantic version string.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Minimum required PeakURL version.
	 *
	 * @var string
	 */
	private string $requires;

	/**
	 * PeakURL version up to which the plugin has been verified.
	 *
	 * @var string
	 */
	private string $tested_up_to;

	/**
	 * Minimum required PHP version notation.
	 *
	 * @var string
	 */
	private string $php;

	/**
	 * SPDX license identifier or proprietary notation.
	 *
	 * @var string
	 */
	private string $license;

	/**
	 * Short plain-text description extracted from package README.
	 *
	 * @var string|null
	 */
	private ?string $description;

	/**
	 * Whether the manifest passed structural and semantic schema validation.
	 *
	 * @var bool
	 */
	private bool $valid;

	/**
	 * Whether the plugin is compatible with the host PeakURL and PHP environment.
	 *
	 * @var bool
	 */
	private bool $compatible;

	/**
	 * List of validation or syntax error messages.
	 *
	 * @var array<int, string>
	 */
	private array $errors;

	/**
	 * List of compatibility warning messages.
	 *
	 * @var array<int, string>
	 */
	private array $warnings;

	/**
	 * Map of optional manifest resource URLs.
	 *
	 * @var array<string, string>
	 */
	private array $links;

	/**
	 * Internal absolute path to the plugin directory.
	 *
	 * Kept private to prevent leaking host filesystem paths to APIs.
	 *
	 * @var string
	 */
	private string $plugin_path;

	/**
	 * Internal absolute path to plugin.json.
	 *
	 * Kept private to prevent leaking host filesystem paths to APIs.
	 *
	 * @var string
	 */
	private string $manifest_path;

	/**
	 * Whether the plugin is currently active in the runtime.
	 *
	 * @var bool
	 */
	private bool $active;

	/**
	 * Whether the plugin entrypoint was executed during this process.
	 *
	 * @var bool
	 */
	private bool $loaded;

	/**
	 * Create a new plugin metadata object.
	 *
	 * @param array<string, mixed> $data Normalized metadata attributes.
	 */
	public function __construct( array $data ) {
		$this->id            = (string) ( $data['id'] ?? '' );
		$type_val            = $data['type'] ?? Type::REGULAR;
		$this->type          = $type_val instanceof Type ? $type_val : ( Type::tryFrom( (string) $type_val ) ?? Type::REGULAR );
		$this->name          = (string) ( $data['name'] ?? '' );
		$this->version       = (string) ( $data['version'] ?? '' );
		$this->requires      = (string) ( $data['requires'] ?? '' );
		$this->tested_up_to  = (string) ( $data['tested_up_to'] ?? ( $data['testedUpTo'] ?? '' ) );
		$this->php           = (string) ( $data['php'] ?? '' );
		$this->license       = (string) ( $data['license'] ?? '' );
		$this->description   = isset( $data['description'] ) && is_string( $data['description'] ) && '' !== trim( $data['description'] ) ? trim( $data['description'] ) : null;
		$this->valid         = (bool) ( $data['valid'] ?? false );
		$this->compatible    = (bool) ( $data['compatible'] ?? false );
		$this->active        = (bool) ( $data['active'] ?? false );
		$this->loaded        = (bool) ( $data['loaded'] ?? false );
		$this->errors        = is_array( $data['errors'] ?? null ) ? array_values( $data['errors'] ) : array();
		$this->warnings      = is_array( $data['warnings'] ?? null ) ? array_values( $data['warnings'] ) : array();
		$this->links         = is_array( $data['links'] ?? null ) ? $data['links'] : array();
		$this->plugin_path   = (string) ( $data['plugin_path'] ?? '' );
		$this->manifest_path = (string) ( $data['manifest_path'] ?? '' );
	}

	/**
	 * Create an invalid metadata record for a broken or malformed plugin.
	 *
	 * @param string             $id            Plugin identifier.
	 * @param Type               $type          Plugin type.
	 * @param array<int, string> $errors        Validation error messages.
	 * @param string             $plugin_path   Internal plugin directory path.
	 * @param string             $manifest_path Internal manifest file path.
	 * @return self
	 */
	public static function invalid(
		string $id,
		Type $type,
		array $errors,
		string $plugin_path = '',
		string $manifest_path = ''
	): self {
		return new self(
			array(
				'id'            => $id,
				'type'          => $type,
				'name'          => $id,
				'description'   => null,
				'version'       => '',
				'requires'      => '',
				'tested_up_to'  => '',
				'php'           => '',
				'license'       => '',
				'valid'         => false,
				'compatible'    => false,
				'active'        => false,
				'loaded'        => false,
				'errors'        => $errors,
				'warnings'      => array(),
				'links'         => array(),
				'plugin_path'   => $plugin_path,
				'manifest_path' => $manifest_path,
			)
		);
	}

	/**
	 * Get the local plugin identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Get the plugin installation type.
	 *
	 * @return Type
	 */
	public function type(): Type {
		return $this->type;
	}

	/**
	 * Get the human-readable display name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Get the semantic version string.
	 *
	 * @return string
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * Get the minimum required PeakURL version.
	 *
	 * @return string
	 */
	public function requires(): string {
		return $this->requires;
	}

	/**
	 * Get the tested PeakURL version.
	 *
	 * @return string
	 */
	public function tested_up_to(): string {
		return $this->tested_up_to;
	}

	/**
	 * Get the minimum required PHP version.
	 *
	 * @return string
	 */
	public function php(): string {
		return $this->php;
	}

	/**
	 * Get the license identifier.
	 *
	 * @return string
	 */
	public function license(): string {
		return $this->license;
	}

	/**
	 * Get the short plain-text description extracted from package README.
	 *
	 * @return string|null
	 */
	public function description(): ?string {
		return $this->description;
	}

	/**
	 * Determine whether the manifest is valid.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return $this->valid;
	}

	/**
	 * Determine whether the plugin is compatible with the host environment.
	 *
	 * @return bool
	 */
	public function is_compatible(): bool {
		return $this->compatible;
	}

	/**
	 * Determine whether the plugin is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->active;
	}

	/**
	 * Clone this instance with an updated active state.
	 *
	 * @param bool $active Active status.
	 * @return self
	 */
	public function with_active( bool $active ): self {
		$clone         = clone $this;
		$clone->active = $active;

		return $clone;
	}

	/**
	 * Determine whether the plugin entrypoint was executed during this process.
	 *
	 * @return bool
	 */
	public function is_loaded(): bool {
		return $this->loaded;
	}

	/**
	 * Clone this instance with an updated loaded state.
	 *
	 * @param bool $loaded Loaded status.
	 * @return self
	 */
	public function with_loaded( bool $loaded ): self {
		$clone         = clone $this;
		$clone->loaded = $loaded;

		return $clone;
	}

	/**
	 * Get the list of validation errors.
	 *
	 * @return array<int, string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Get the list of compatibility warnings.
	 *
	 * @return array<int, string>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Get the map of optional manifest links.
	 *
	 * @return array<string, string>
	 */
	public function links(): array {
		return $this->links;
	}

	/**
	 * Get the internal plugin directory path.
	 *
	 * @return string
	 */
	public function plugin_path(): string {
		return $this->plugin_path;
	}

	/**
	 * Get the internal manifest file path.
	 *
	 * @return string
	 */
	public function manifest_path(): string {
		return $this->manifest_path;
	}

	/**
	 * Serialize the metadata into a safe public array.
	 *
	 * Excludes all absolute filesystem paths and sensitive server details.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$result = array(
			'id'          => $this->id,
			'type'        => $this->type->value,
			'typeLabel'   => $this->type->label(),
			'name'        => $this->name,
			'description' => $this->description,
			'version'     => $this->version,
			'requires'    => $this->requires,
			'testedUpTo'  => $this->tested_up_to,
			'php'         => $this->php,
			'license'     => $this->license,
			'valid'       => $this->valid,
			'compatible'  => $this->compatible,
			'active'      => $this->active,
			'loaded'      => $this->loaded,
			'errors'      => $this->errors,
			'warnings'    => $this->warnings,
			'links'       => $this->links,
		);

		foreach ( array( 'licenseUri', 'homepage', 'documentation', 'repository', 'support', 'issues' ) as $link_key ) {
			if ( isset( $this->links[ $link_key ] ) ) {
				$result[ $link_key ] = $this->links[ $link_key ];
			}
		}

		return $result;
	}
}
